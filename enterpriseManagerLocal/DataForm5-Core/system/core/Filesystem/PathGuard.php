<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem;

final class PathGuard
{
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = str_starts_with($path, '/') ? '/' : '';
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                if ($parts === []) {
                    throw new FilesystemException('Pfad verlässt den erlaubten Wurzelbereich.');
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return $prefix . implode('/', $parts);
    }

    public static function within(string $root, string $relativePath = ''): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $normalized = self::normalize($relativePath);
        if (str_starts_with($normalized, '/')) {
            throw new FilesystemException('Absolute Pfade sind in Storage nicht erlaubt.');
        }
        return $root . ($normalized === '' ? '' : '/' . $normalized);
    }
}
