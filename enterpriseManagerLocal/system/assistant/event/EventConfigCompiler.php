<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Event;

final class EventConfigCompiler
{
    public function compile(EventDraft $draft): array
    {
        $d = $draft->toArray();
        $events = [];
        foreach (['beforeSave', 'afterSave', 'beforeDelete', 'afterDelete'] as $name) {
            $event = $d['events'][$name];
            $events[$name] = [
                'enabled' => (bool) $event['enabled'],
                'handler' => trim((string) $event['handler']),
                'argument' => (string) $d['context']['objectName'],
                'blocking' => (bool) $event['blocking'],
            ];
        }

        return [
            'schema' => 'easyit.dataform.events.assistant.v1',
            'contextObject' => [
                'name' => (string) $d['context']['objectName'],
                'schema' => 'easyit.dataform.action-context.v1',
                'contains' => [
                    'event', 'action', 'project', 'dataForm', 'record', 'changes',
                    'relation', 'pagination', 'operation', 'ui', 'meta',
                ],
            ],
            'events' => $events,
            'runtime' => [
                'handlerResolution' => 'global-path',
                'allowEval' => false,
                'beforeEventFalseCancelsAction' => true,
                'captureHandlerErrors' => true,
                'javascriptRuntime' => 'assets/js/dataform-events.js',
            ],
        ];
    }
}
