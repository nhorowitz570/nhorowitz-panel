<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthCode;
use App\Models\McpToken;
use App\Support\Extensions\Extensions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OAuthTokenController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchange($request),
            'refresh_token' => $this->refresh($request),
            default => $this->fail('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    // RFC 7009: always 200, so it can't reveal which tokens exist.
    public function revoke(Request $request): JsonResponse
    {
        $secret = (string) $request->input('token', '');
        $client = McpOAuthClient::query()->where('client_id', (string) $request->input('client_id', ''))->first();
        if ($secret !== '' && $client !== null) {
            $hash = McpToken::hash($secret);
            McpToken::query()->active()
                ->where('mcp_oauth_client_id', $client->id)
                ->where(fn ($q) => $q->where('token_hash', $hash)->orWhere('refresh_hash', $hash))
                ->get()
                ->each(function (McpToken $token): void {
                    $token->revoke();
                    if ($token->workspace_id !== null) {
                        Activity::system($token->workspace_id, 'mcp.revoke', $token->label(), meta: array_filter([
                            'kind' => 'oauth', 'by_client' => true, 'person' => $token->user->name,
                        ]));
                    }
                });
        }

        return response()->json(new \stdClass)->header('Cache-Control', 'no-store');
    }

    private function exchange(Request $request): JsonResponse
    {
        $client = McpOAuthClient::query()->where('client_id', (string) $request->input('client_id'))->first();
        if ($client === null) {
            return $this->fail('invalid_client', 'Unknown client_id.', 401);
        }
        $verifier = (string) $request->input('code_verifier', '');
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            return $this->fail('invalid_request', 'Send the PKCE code_verifier.');
        }

        $access = McpToken::secret('access');
        $refresh = McpToken::secret('refresh');
        $token = DB::transaction(function () use ($request, $client, $verifier, $access, $refresh): ?McpToken {
            $code = McpOAuthCode::query()->lockForUpdate()
                ->where('code_hash', hash('sha256', (string) $request->input('code', '')))
                ->first();
            if ($code === null || $code->mcp_oauth_client_id !== $client->id || ! hash_equals($code->code_challenge, self::challenge($verifier))) {
                return null;
            }
            if ($code->used_at !== null) {
                if ($code->mcp_token_id !== null) {
                    McpToken::query()->active()->whereKey($code->mcp_token_id)->get()->each(fn (McpToken $token) => $token->revoke());
                }

                return null;
            }

            $valid = $code->expires_at->isFuture()
                && hash_equals($code->redirect_uri, (string) $request->input('redirect_uri', $code->redirect_uri_sent ? '' : $code->redirect_uri))
                && (! $request->filled('resource') || rtrim((string) $request->input('resource'), '/') === rtrim($code->resource, '/'));
            if (! $valid) {
                return null;
            }
            $code->forceFill(['used_at' => now()])->save();

            $scopes = self::scopes($code->audience, $code->scopes);
            if ($scopes === null) {
                return null;
            }
            $token = McpToken::query()->create([
                'audience' => $code->audience,
                'workspace_id' => $code->workspace_id,
                'user_id' => $code->user_id,
                'mcp_oauth_client_id' => $client->id,
                'kind' => 'oauth',
                'name' => $client->name,
                'scopes' => $scopes,
                'token_hash' => McpToken::hash($access),
                'token_prefix' => substr($access, 0, 12),
                'refresh_hash' => McpToken::hash($refresh),
                'expires_at' => now()->addMinutes(McpToken::ACCESS_MINUTES),
                'refresh_expires_at' => now()->addDays(McpToken::REFRESH_DAYS),
            ]);
            $code->forceFill(['mcp_token_id' => $token->id])->save();

            return $token;
        });

        if ($token === null) {
            return $this->fail('invalid_grant', 'The code is invalid, expired, already used, or doesn’t match this client, redirect or verifier.');
        }

        return $this->tokens($token, $access, $refresh);
    }

    private function refresh(Request $request): JsonResponse
    {
        $client = McpOAuthClient::query()->where('client_id', (string) $request->input('client_id'))->first();
        if ($client === null) {
            return $this->fail('invalid_client', 'Unknown client_id.', 401);
        }

        $access = McpToken::secret('access');
        $refresh = McpToken::secret('refresh');
        $token = DB::transaction(function () use ($request, $client, $access, $refresh) {
            $token = McpToken::query()->active()->lockForUpdate()
                ->where('refresh_hash', McpToken::hash((string) $request->input('refresh_token', '')))
                ->where('mcp_oauth_client_id', $client->id)
                ->first();
            if ($token === null || $token->refresh_expires_at === null || $token->refresh_expires_at->isPast()) {
                return null;
            }
            if ($token->audience === McpToken::WORKSPACE && $token->workspace_id !== null && $token->workspace->roleOf($token->user) === null) {
                $token->revoke();

                return null;
            }
            $asked = self::scopes($token->audience, (string) $request->input('scope'));
            if ($asked === null) {
                return null;
            }
            if ($request->filled('scope')) {
                $token->scopes = array_values(array_intersect($token->scopes, $asked));
            }
            $token->forceFill([
                'token_hash' => McpToken::hash($access),
                'token_prefix' => substr($access, 0, 12),
                'refresh_hash' => McpToken::hash($refresh),
                'expires_at' => now()->addMinutes(McpToken::ACCESS_MINUTES),
                'refresh_expires_at' => now()->addDays(McpToken::REFRESH_DAYS),
            ])->save();

            return $token;
        });

        return $token === null
            ? $this->fail('invalid_grant', 'The refresh token is invalid, expired or revoked. Connect again.')
            : $this->tokens($token, $access, $refresh);
    }

    private function tokens(McpToken $token, string $access, string $refresh): JsonResponse
    {
        return response()->json([
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => McpToken::ACCESS_MINUTES * 60,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $token->scopes),
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * @param  string|list<string>|null  $scopes
     * @return list<string>|null
     */
    private static function scopes(?string $audience, string|array|null $scopes): ?array
    {
        return app(Extensions::class)->oauthAudience($audience ?? McpToken::WORKSPACE)?->normalise($scopes);
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function fail(string $error, string $description, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], $status)->header('Cache-Control', 'no-store');
    }
}
