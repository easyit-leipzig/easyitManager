<?php
declare(strict_types=1);

/**
 * DataForm 5 Help Registry
 *
 * Zentrale Zuordnung von Help-IDs zu Hilfe-Inhalten und Dokumentationsankern.
 * Einzelne Seiten sollen keine Dokumentationspfade hart verdrahten.
 */
return [
    'dataform.designer' => [
        'title' => 'DataForm-Designer',
        'helpFile' => 'help/dataform/designer.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'ziel',
    ],

    'dataformContext' => [
        'title' => 'dataformContext',
        'helpFile' => 'help/dataform/dataform-context.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'struktur',
    ],

    'dataformContext.examples' => [
        'title' => 'dataformContext – JavaScript-Beispiele',
        'helpFile' => 'help/dataform/dataform-context.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'praxis-js',
    ],

    'recordset.callbacks' => [
        'title' => 'Recordset-Callbacks',
        'helpFile' => 'help/dataform/recordset.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'callbacks',
    ],

    'recordset.beforeSave' => [
        'title' => 'Vor Speichern',
        'helpFile' => 'help/dataform/callbacks.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'example-before-save',
    ],

    'recordset.afterSave' => [
        'title' => 'Nach Speichern',
        'helpFile' => 'help/dataform/callbacks.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'example-after-save',
    ],

    'recordset.beforeDelete' => [
        'title' => 'Vor Löschen',
        'helpFile' => 'help/dataform/callbacks.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'example-before-delete',
    ],

    'recordset.afterDelete' => [
        'title' => 'Nach Löschen',
        'helpFile' => 'help/dataform/callbacks.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'example-after-delete',
    ],

    'recordset.afterNavigate' => [
        'title' => 'Nach Navigation',
        'helpFile' => 'help/dataform/callbacks.json',
        'document' => 'docs/dataform/dataformContext.html',
        'anchor' => 'example-after-navigate',
    ],
];
