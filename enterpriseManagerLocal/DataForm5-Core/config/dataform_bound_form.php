<?php
declare(strict_types=1);

/**
 * DataForm 5 – gebundene Formulare
 *
 * inheritParentValue ist bei einer echten Parent→Child-Bindung verbindlich.
 * boundFieldReadonly ist konfigurierbar und standardmäßig TRUE.
 */
return [
    'inheritParentValue' => true,
    'boundFieldReadonly' => true,

    // Ohne persistierten Hauptschlüssel dürfen keine echten Kinddatensätze
    // angelegt werden.
    'requirePersistedParent' => true,

    // Read-only-Bindungen werden serverseitig nochmals erzwungen.
    'enforceReadonlyOnServer' => true,
];
