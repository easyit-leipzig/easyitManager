<?php
declare(strict_types=1);

namespace DataForm5\Core\Support;

final class Path
{
    public function __construct(private readonly string $basePath) {}

    public function base(string $path = ''): string { return $this->join($this->basePath, $path); }
    public function config(string $path = ''): string { return $this->join($this->basePath . '/config', $path); }
    public function storage(string $path = ''): string { return $this->join($this->basePath . '/storage', $path); }
    public function system(string $path = ''): string { return $this->join($this->basePath . '/system', $path); }

    private function join(string $root, string $path): string
    {
        return rtrim(str_replace('\\', '/', $root), '/') . ($path === '' ? '' : '/' . ltrim($path, '/\\'));
    }
}
