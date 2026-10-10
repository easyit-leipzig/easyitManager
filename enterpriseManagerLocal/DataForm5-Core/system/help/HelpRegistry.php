<?php
declare(strict_types=1);

namespace EasyIT\DataForm5\Help;

use InvalidArgumentException;
use RuntimeException;

final class HelpRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $entries;

    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);

        $entries = [];

        $mainFile = $projectRoot
            . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'help_registry.php';

        if (is_file($mainFile)) {
            $mainEntries = require $mainFile;

            if (!is_array($mainEntries)) {
                throw new RuntimeException(
                    'Die zentrale Help-Registry muss ein Array zurückgeben.'
                );
            }

            $entries = $mainEntries;
        }

        $fragmentDir = $projectRoot
            . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'help_registry.d';

        if (is_dir($fragmentDir)) {
            $files = glob(
                $fragmentDir . DIRECTORY_SEPARATOR . '*.php'
            );

            if ($files !== false) {
                sort($files, SORT_STRING);

                foreach ($files as $file) {
                    $fragment = require $file;

                    if (!is_array($fragment)) {
                        throw new RuntimeException(
                            'Help-Registry-Fragment muss ein Array zurückgeben: '
                            . $file
                        );
                    }

                    foreach ($fragment as $helpId => $entry) {
                        if (array_key_exists($helpId, $entries)) {
                            throw new RuntimeException(
                                'Doppelte Help-ID: ' . $helpId
                            );
                        }

                        $entries[$helpId] = $entry;
                    }
                }
            }
        }

        if ($entries === []) {
            throw new InvalidArgumentException(
                'Keine Help-Registry gefunden.'
            );
        }

        return new self($entries);
    }

    public function has(string $helpId): bool
    {
        return array_key_exists($helpId, $this->entries);
    }

    /** @return array<string,mixed> */
    public function get(string $helpId): array
    {
        if (!$this->has($helpId)) {
            throw new InvalidArgumentException(
                'Unbekannte Help-ID: ' . $helpId
            );
        }

        return $this->entries[$helpId];
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return $this->entries;
    }
}
