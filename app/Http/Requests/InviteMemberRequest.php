<?php

namespace App\Http\Requests;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Support\Edition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageMembers', $this->workspace());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $fixed = array_map(fn (WorkspaceRole $role) => $role->value, WorkspaceRole::assignable());
                if (! in_array($value, $fixed, true) && ! preg_match('/^custom:\d+$/', (string) $value)) {
                    $fail('Pick a role.');
                }
            }],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $workspace = $this->workspace();

                if (str_starts_with($this->string('role')->toString(), 'custom:')) {
                    if (! $workspace->hasCustomRoles()) {
                        $validator->errors()->add('role', Edition::selfHosted() ? 'Custom roles need a ServerOS license.' : 'Custom roles are on Business.');

                        return;
                    }
                    if ($this->chosenCustomRole() === null) {
                        $validator->errors()->add('role', 'That role no longer exists.');

                        return;
                    }
                }

                if ($this->chosenRole()->isStaffRole() && ! $workspace->hasStaffRoles()) {
                    $validator->errors()->add('role', 'Member and Viewer roles are on Pro and Business. Invite them as an Admin, or upgrade.');
                } elseif (! $workspace->canGrant($this->user(), Workspace::permissionsFor($this->chosenRole(), $this->chosenCustomRole()))) {
                    $validator->errors()->add('role', 'You can’t give a role with more access than your own.');
                }

                if ($workspace->members()->whereRaw('lower(users.email) = ?', [$this->input('email')])->exists()) {
                    $validator->errors()->add('email', 'They’re already in this workspace.');
                }
            },
        ];
    }

    public function chosenRole(): WorkspaceRole
    {
        $value = $this->string('role')->toString();

        return str_starts_with($value, 'custom:') ? WorkspaceRole::Viewer : WorkspaceRole::from($value);
    }

    public function chosenCustomRole(): ?WorkspaceCustomRole
    {
        $value = $this->string('role')->toString();

        return str_starts_with($value, 'custom:')
            ? $this->workspace()->customRoles()->find((int) substr($value, 7))
            : null;
    }

    private function workspace(): Workspace
    {
        /** @var Workspace */
        return $this->route('workspace');
    }
}
