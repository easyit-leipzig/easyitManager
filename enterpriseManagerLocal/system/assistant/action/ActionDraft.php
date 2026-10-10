<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Action;

final class ActionDraft implements \JsonSerializable
{
    public function __construct(private array $data = [])
    {
        $this->data = array_replace_recursive(self::defaults(), $data);
    }

    public static function defaults(): array
    {
        return [
            'dataForm' => ['name' => ''],
            'actions' => [
                'new' => true,
                'show' => true,
                'edit' => true,
                'save' => true,
                'delete' => true,
                'first' => true,
                'previous' => true,
                'next' => true,
                'last' => true,
            ],
            'ui' => [
                'buttonRegistry' => 'central',
                'localTitlesAllowed' => false,
                'localAriaLabelsAllowed' => false,
                'cssBackgroundButtonsAllowed' => false,
            ],
        ];
    }

    public function merge(array $changes): self
    {
        return new self(array_replace_recursive($this->data, $changes));
    }

    public function toArray(): array { return $this->data; }
    public function jsonSerialize(): array { return $this->data; }
}
