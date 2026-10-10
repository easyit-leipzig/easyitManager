<?php
declare(strict_types=1);

namespace EasyIT\DataForm5\Designer;

final class BoundFormSettings
{
    /**
     * Metadaten für den Relations-/DataForm-Designer.
     *
     * @return array<string,mixed>
     */
    public static function definition(): array
    {
        return [
            'section' => 'Gebundenes Formular',
            'fields' => [
                [
                    'name' => 'inheritParentValue',
                    'label' => 'Hauptwert automatisch übernehmen',
                    'type' => 'checkbox',
                    'default' => true,
                    'readonly' => true,
                    'help' =>
                        'Bei einer echten Parent→Child-Bindung '
                        . 'ist die Übernahme des Hauptwerts verbindlich.',
                ],
                [
                    'name' => 'boundFieldReadonly',
                    'label' => 'Gebundenes Feld schreibgeschützt',
                    'type' => 'checkbox',
                    'default' => true,
                    'readonly' => false,
                    'helpId' => 'dataform.boundForm',
                ],
            ],
        ];
    }
}
