<?php
declare(strict_types=1);
return [
    'enabled' => true,
    'stop_propagation' => true,
    'diagnostics' => [
        'enabled' => true,
        'file' => 'storage/logs/events.log',
        'max_bytes' => 1048576,
    ],
    'catalog' => [
        'auth.user.logged_in' => ['description'=>'Benutzer wurde erfolgreich angemeldet.','producer'=>'enterprise'],
        'auth.user.logged_out' => ['description'=>'Benutzer hat die Enterprise-Sitzung beendet.','producer'=>'enterprise'],
        'project.registered' => ['description'=>'Eine vorhandene Projektdatenbank wurde als Enterprise-Projekt registriert.','producer'=>'enterprise'],
        'dataform.record.created' => ['description'=>'Ein DataForm-Datensatz wurde angelegt.','producer'=>'dataform'],
        'dataform.record.updated' => ['description'=>'Ein DataForm-Datensatz wurde geändert.','producer'=>'dataform'],
        'dataform.record.deleted' => ['description'=>'Ein DataForm-Datensatz wurde gelöscht.','producer'=>'dataform'],
        'dataform.records.deleted' => ['description'=>'Mehrere DataForm-Datensätze wurden gelöscht.','producer'=>'dataform'],
    ],
];
