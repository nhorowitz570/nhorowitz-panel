<?php

use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\Mcp\OAuthMetadataController;
use App\Http\Controllers\Mcp\OAuthRegistrationController;
use App\Http\Controllers\Mcp\OAuthTokenController;
use App\Http\Controllers\Mcp\ServerCardController;
use App\Http\Middleware\McpCors;
use App\Support\Extensions\Extensions;
use Illuminate\Support\Facades\Route;

Route::middleware(McpCors::class)->group(function () {
    app(Extensions::class)->loadRoutes(Extensions::MCP);

    Route::domain('{workspace:slug}.'.config('app.domain'))->group(function () {
        Route::post('mcp', McpController::class)->name('mcp');
        Route::get('mcp', [McpController::class, 'stream']);
        Route::delete('mcp', [McpController::class, 'close']);
        Route::options('mcp', fn () => response('', 204));
        Route::get('.well-known/oauth-protected-resource/mcp', [OAuthMetadataController::class, 'resource'])->name('mcp.resource-metadata');
        Route::get('.well-known/oauth-protected-resource', [OAuthMetadataController::class, 'resource']);
    });

    Route::get('.well-known/mcp/server-card.json', [ServerCardController::class, 'workspace'])->name('mcp.server-card');

    Route::get('.well-known/oauth-authorization-server', [OAuthMetadataController::class, 'server'])->name('mcp.oauth.metadata');
    Route::get('.well-known/openid-configuration', [OAuthMetadataController::class, 'server']);
    Route::post('oauth/register', OAuthRegistrationController::class)->middleware('throttle:20,1')->name('mcp.oauth.register');
    Route::post('oauth/token', [OAuthTokenController::class, 'token'])->middleware('throttle:60,1')->name('mcp.oauth.token');
    Route::post('oauth/revoke', [OAuthTokenController::class, 'revoke'])->middleware('throttle:60,1')->name('mcp.oauth.revoke');
    Route::options('oauth/{endpoint}', fn () => response('', 204))->where('endpoint', 'register|token|revoke');
    Route::options('.well-known/{document}', fn () => response('', 204))->where('document', '.*');
});
