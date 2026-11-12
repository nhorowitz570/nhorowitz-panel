<?php

namespace App\Support\ServiceTemplates;

use App\Models\Machine;
use App\Support\DaemonFacts;
use InvalidArgumentException;

final readonly class ServiceTemplate
{
    public const KIND_COMPOSE = 'compose';

    public const KIND_APP = 'app';

    public const CATEGORIES = ['Apps', 'Databases', 'Infrastructure', 'Self-hosted', 'Game servers'];

    /**
     * @param  list<array{key: string, label: string, default?: string, secret?: bool, hint?: string, required?: bool, accept?: bool}>  $env
     * @param  array<string, string>  $files
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $category,
        public string $description,
        public string $icon,
        public string $kind,
        public int $port,
        public bool $web,
        public bool $portEditable,
        public ?string $health,
        public array $env,
        public array $files,
        public ?string $dockerfile,
        public ?string $notes,
        public ?int $healthRetries = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $slug, array $data): self
    {
        foreach (['name', 'category', 'description', 'icon', 'kind', 'port'] as $key) {
            if (! isset($data[$key])) {
                throw new InvalidArgumentException("Service template {$slug} is missing {$key}.");
            }
        }

        $kind = (string) $data['kind'];
        if (! in_array($kind, [self::KIND_COMPOSE, self::KIND_APP], true)) {
            throw new InvalidArgumentException("Service template {$slug} has unknown kind {$kind}.");
        }
        if (! in_array($data['category'], self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Service template {$slug} has unknown category {$data['category']}.");
        }

        $files = $data['files'] ?? [];
        if ($kind === self::KIND_COMPOSE && ! isset($files['compose.yaml'])) {
            throw new InvalidArgumentException("Service template {$slug} needs a compose.yaml.");
        }

        return new self(
            slug: $slug,
            name: (string) $data['name'],
            category: (string) $data['category'],
            description: (string) $data['description'],
            icon: (string) $data['icon'],
            kind: $kind,
            port: (int) $data['port'],
            web: (bool) ($data['web'] ?? false),
            portEditable: (bool) ($data['port_editable'] ?? true),
            health: isset($data['health']) ? (string) $data['health'] : null,
            env: array_values($data['env'] ?? []),
            files: $files,
            dockerfile: isset($data['dockerfile']) ? (string) $data['dockerfile'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            healthRetries: isset($data['health_retries']) ? (int) $data['health_retries'] : null,
        );
    }

    public function notesFor(?Machine $machine): ?string
    {
        if ($this->notes === null) {
            return null;
        }

        return str_replace('{bridge}', $machine !== null ? DaemonFacts::dockerBridge($machine->facts ?? []) : 'Docker’s bridge address', $this->notes);
    }

    public function isApp(): bool
    {
        return $this->kind === self::KIND_APP;
    }

    public function controlsHost(): bool
    {
        foreach ([...$this->files, (string) $this->dockerfile] as $content) {
            if (str_contains($content, 'docker.sock') || preg_match('/^\s*privileged:\s*true/mi', $content) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toPageProps(?Machine $machine = null): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'icon' => $this->icon,
            'kind' => $this->kind,
            'port' => $this->port,
            'web' => $this->web,
            'portEditable' => $this->portEditable,
            'health' => $this->health,
            'notes' => $this->notesFor($machine),
            'env' => array_map(fn (array $field) => [
                'key' => $field['key'],
                'label' => $field['label'],
                'hint' => $field['hint'] ?? null,
                'secret' => (bool) ($field['secret'] ?? false),
                'required' => (bool) ($field['required'] ?? false),
                'accept' => (bool) ($field['accept'] ?? false),
                'generated' => str_starts_with($field['default'] ?? '', '{'),
                'default' => str_starts_with($field['default'] ?? '', '{') ? '' : ($field['default'] ?? ''),
            ], $this->env),
        ];
    }
}
