<?php
declare(strict_types=1);

namespace EasyIT\DataForm5\Help;

use RuntimeException;

final class HelpService
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly HelpRegistry $registry
    ) {
    }

    /** @return array<string,mixed> */
    public function getHelp(string $helpId): array
    {
        $entry = $this->registry->get($helpId);
        $file = $this->safeProjectPath((string)$entry['helpFile']);

        if (!is_file($file)) {
            throw new RuntimeException('Hilfedatei nicht gefunden.');
        }

        $json = file_get_contents($file);

        return json_decode(
            (string)$json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function safeProjectPath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        if (
            $relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '../')
            || str_contains($relativePath, "\0")
        ) {
            throw new RuntimeException('Ungültiger Hilfepfad.');
        }

        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
