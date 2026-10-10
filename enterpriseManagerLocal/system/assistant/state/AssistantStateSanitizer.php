<?php
declare(strict_types=1);

namespace EasyIT\Assistant\State;

final class AssistantStateSanitizer
{
    /** @var list<string> */
    private const DROP_KEYS = [
        'password',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'credential',
        'credentials',
        'tmp_name',
        'upload',
        'uploadedfile',
        'uploaded_file',
    ];

    public function sanitize(array $state): array
    {
        return $this->walk($state);
    }

    private function walk(array $value): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? strtolower($key) : '';
            if ($keyString !== '' && in_array($keyString, self::DROP_KEYS, true)) {
                continue;
            }
            if (is_array($item)) {
                $clean[$key] = $this->walk($item);
                continue;
            }
            if (is_resource($item) || $item instanceof \Closure) {
                continue;
            }
            if (is_object($item)) {
                if ($item instanceof \JsonSerializable) {
                    $serialized = $item->jsonSerialize();
                    $clean[$key] = is_array($serialized) ? $this->walk($serialized) : $serialized;
                }
                continue;
            }
            $clean[$key] = $item;
        }
        return $clean;
    }
}
