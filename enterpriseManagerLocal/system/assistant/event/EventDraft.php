<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Event;

final class EventDraft implements \JsonSerializable
{
    public function __construct(private array $data = [])
    {
        $this->data = array_replace_recursive(self::defaults(), $data);
    }

    public static function defaults(): array
    {
        return [
            'context' => [
                'objectName' => 'dataFormContext',
                'schema' => 'easyit.dataform.action-context.v1',
                'includeOriginalRecord' => true,
                'includeChanges' => true,
                'includeRelationContext' => true,
                'includePagination' => true,
            ],
            'events' => [
                'beforeSave' => ['enabled' => false, 'handler' => '', 'blocking' => true],
                'afterSave' => ['enabled' => false, 'handler' => '', 'blocking' => false],
                'beforeDelete' => ['enabled' => false, 'handler' => '', 'blocking' => true],
                'afterDelete' => ['enabled' => false, 'handler' => '', 'blocking' => false],
            ],
            'runtime' => [
                'handlerResolution' => 'global-path',
                'allowEval' => false,
                'beforeEventFalseCancelsAction' => true,
                'captureHandlerErrors' => true,
            ],
        ];
    }

    public function merge(array $changes): self
    {
        return new self(array_replace_recursive($this->data, $changes));
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
