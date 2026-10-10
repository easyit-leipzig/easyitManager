<?php
declare(strict_types=1);

namespace EasyIT\Assistant;

final class AssistantContextFactory
{
    public static function fromGlobals(): AssistantContext
    {
        $request = array_merge($_GET ?? [], $_POST ?? []);
        $server = $_SERVER ?? [];

        $pick = static function (array $source, array $keys): ?string {
            foreach ($keys as $key) {
                if (isset($source[$key]) && $source[$key] !== '') {
                    return (string) $source[$key];
                }
            }
            return null;
        };

        return new AssistantContext(
            $pick($request, ['project_id', 'projectId', 'project']),
            $pick($request, ['dataform_id', 'dataFormId', 'dataform']),
            $pick($request, ['record_id', 'recordId', 'id']),
            isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : null,
            $request,
            [
                'requestMethod' => (string) ($server['REQUEST_METHOD'] ?? 'GET'),
                'scriptName' => (string) ($server['SCRIPT_NAME'] ?? ''),
                'files' => $_FILES ?? [],
            ]
        );
    }
}
