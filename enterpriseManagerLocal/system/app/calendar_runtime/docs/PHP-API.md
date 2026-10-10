# easyITCalendar PHP-API

## Öffentlicher Einstieg

```php
<?php
require_once __DIR__ . '/../easyITCalendar/bootstrap.php';

$calendar = new Calendar();
$calendar->render();
```

`bootstrap.php` liegt direkt im Root-Verzeichnis von easyITCalendar. Das vorhandene `lib/bootstrap.php` bleibt die interne DataForm-/Datenbank-Laufzeit.

## Namespaced Variante

```php
<?php
require_once __DIR__ . '/../easyITCalendar/bootstrap.php';

use EasyIT\Calendar\Calendar;

$calendar = new Calendar();
$calendar->render();
```

## Termine lesen

```php
$events = $calendar->events()->get();
```

Optional kann ein Zeitfenster angegeben werden:

```php
$events = $calendar->events()->get('2026-10-01T00:00:00', '2026-11-01T00:00:00');
```

## Termin anlegen

```php
$id = $calendar->events()->create([
    'title' => 'Kundentermin',
    'start' => '2026-10-06 10:00:00',
    'end' => '2026-10-06 11:00:00',
    'description' => 'Besprechung',
    'location' => 1,
    'category' => 2,
]);
```

## Termin ändern / löschen

```php
$calendar->events()->update($id, [
    'title' => 'Geänderter Kundentermin',
]);

$calendar->events()->delete($id);
```

`delete()` arbeitet entsprechend der bestehenden Calendar-Logik als Soft-Delete und setzt den Aktivstatus auf 0.

## Orte und Kategorien

```php
$places = $calendar->places()->get();
$categories = $calendar->categories()->get();
```

## Calendar in einer anderen Anwendung anzeigen

Liegt die aufrufende Anwendung z. B. unter `D:\xampp\htdocs\calAnw\calendar.php` und easyITCalendar unter `D:\xampp\htdocs\easyITCalendar`, genügt:

```php
<?php
require_once __DIR__ . '/../easyITCalendar/bootstrap.php';

$calendar = new Calendar();
$calendar->render();
```

Wenn der Calendar nicht automatisch unterhalb von `DOCUMENT_ROOT` erkannt werden kann, kann die URL explizit gesetzt werden:

```php
$calendar = new Calendar([
    'base_url' => '/easyITCalendar'
]);
```
