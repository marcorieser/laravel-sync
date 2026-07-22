<?php

declare(strict_types=1);

namespace Sync\Sync\Data;

final readonly class Remote
{
    public function __construct(
        public string $name,
        public ?string $user,
        public ?string $host,
        public int $port,
        public string $root,
        public bool $readOnly,
    ) {}

    /**
     * Hydrate a remote from its raw config array.
     *
     * @param  array{user?: string, host?: string, root: string, port?: int, read_only?: bool}  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            user: $config['user'] ?? null,
            host: $config['host'] ?? null,
            port: $config['port'] ?? 22,
            root: rtrim($config['root'], '/'),
            readOnly: $config['read_only'] ?? false,
        );
    }

    /**
     * A remote without a host is treated as a local path — no ssh involved.
     */
    public function isLocal(): bool
    {
        return blank($this->host);
    }

    /**
     * Build the full path for a relative recipe path, in `rsync` source/destination form.
     */
    public function path(string $relative): string
    {
        $fullPath = self::collapseSlashes("{$this->root}/{$relative}");

        if ($this->isLocal()) {
            return $fullPath;
        }

        return "{$this->user}@{$this->host}:{$fullPath}";
    }

    /**
     * Collapse duplicate slashes produced when joining paths.
     */
    private static function collapseSlashes(string $path): string
    {
        return preg_replace('#/+#', '/', $path) ?? $path;
    }
}
