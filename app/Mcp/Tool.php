<?php

namespace App\Mcp;

use App\Enums\WorkspacePermission;
use Illuminate\Support\Facades\Validator;

abstract class Tool
{
    abstract public function name(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|list<mixed>|string
     */
    abstract public function handle(McpContext $context, array $arguments): array|string;

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function scope(): string
    {
        return Scopes::READ;
    }

    public function permission(): ?WorkspacePermission
    {
        return $this->scope() === Scopes::READ ? null : WorkspacePermission::ManageMachines;
    }

    public function destructive(): bool
    {
        return false;
    }

    public function idempotent(): bool
    {
        return $this->scope() === Scopes::READ;
    }

    public function openWorld(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
            'annotations' => [
                'title' => $this->title(),
                'readOnlyHint' => $this->scope() === Scopes::READ,
                'destructiveHint' => $this->destructive(),
                'idempotentHint' => $this->idempotent(),
                'openWorldHint' => $this->openWorld(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    protected function validate(array $arguments, array $rules, array $messages = []): array
    {
        $validator = Validator::make($arguments, $rules, $messages);
        if ($validator->fails()) {
            throw new ToolError('Invalid arguments: '.implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }
}
