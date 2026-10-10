# easyIT Calendar Integration API 1.1 – HF156

Die API-Dokumentation beschreibt zwei öffentliche Schnittstellen des Calendars:

1. **REST-/Integrations-API** über `api-v1.php` und die JavaScript-Klasse `EasyITCalendarAPI`.
2. **Kalenderansichts-API** über `window.TinyCalendar`, insbesondere `render(filterObjekt)` und `getFilter()`.

Für jede REST-Funktion sowie für die Render-/Filter-Schnittstelle werden eigenständige JavaScript-Beispiele mitgeliefert. PHP-Beispiele gelten für die REST-API; `render()` ist eine Browser-/Ansichtsfunktion und hat daher kein PHP-Gegenstück.

## Vorbereitung PHP

1. `docs/examples/php/_config.php.example` nach `_config.php` kopieren.
2. `base_url` und `token` eintragen.
3. Gewünschtes Funktionsbeispiel aufrufen.

## REST-Funktionen

- `meta()` – `GET /meta` – Rolle `viewer` – API-/Projektinformationen und Tokenrolle lesen – JS: `examples/javascript/meta.js` – PHP: `examples/php/meta.php`
- `listEvents(start, end)` – `GET /events?start=...&end=...` – Rolle `viewer` – Termine eines Zeitraums lesen – JS: `examples/javascript/list-events.js` – PHP: `examples/php/list-events.php`
- `getEvent(id)` – `GET /events/{id}` – Rolle `viewer` – Einzelnen Termin lesen – JS: `examples/javascript/get-event.js` – PHP: `examples/php/get-event.php`
- `createEvent(data)` – `POST /events` – Rolle `editor` – Termin anlegen – JS: `examples/javascript/create-event.js` – PHP: `examples/php/create-event.php`
- `updateEvent(id, data)` – `PUT /events/{id}` – Rolle `editor` – Termin ändern – JS: `examples/javascript/update-event.js` – PHP: `examples/php/update-event.php`
- `deleteEvent(id)` – `DELETE /events/{id}` – Rolle `editor` – Termin logisch löschen – JS: `examples/javascript/delete-event.js` – PHP: `examples/php/delete-event.php`
- `listBlocks()` – `GET /blocks` – Rolle `viewer` – Zeitblockierungen lesen – JS: `examples/javascript/list-blocks.js` – PHP: `examples/php/list-blocks.php`
- `createBlock(data)` – `POST /blocks` – Rolle `editor` – Zeitblockierung anlegen – JS: `examples/javascript/create-block.js` – PHP: `examples/php/create-block.php`
- `updateBlock(id, data)` – `PUT /blocks/{id}` – Rolle `editor` – Zeitblockierung ändern – JS: `examples/javascript/update-block.js` – PHP: `examples/php/update-block.php`
- `deleteBlock(id)` – `DELETE /blocks/{id}` – Rolle `editor` – Zeitblockierung löschen – JS: `examples/javascript/delete-block.js` – PHP: `examples/php/delete-block.php`
- `listBlockCategories()` – `GET /block-categories` – Rolle `viewer` – Blockierungskategorien lesen – JS: `examples/javascript/list-block-categories.js` – PHP: `examples/php/list-block-categories.php`
- `createBlockCategory(data)` – `POST /block-categories` – Rolle `admin` – Blockierungskategorie anlegen – JS: `examples/javascript/create-block-category.js` – PHP: `examples/php/create-block-category.php`
- `updateBlockCategory(id, data)` – `PUT /block-categories/{id}` – Rolle `admin` – Blockierungskategorie ändern – JS: `examples/javascript/update-block-category.js` – PHP: `examples/php/update-block-category.php`
- `deleteBlockCategory(id)` – `DELETE /block-categories/{id}` – Rolle `admin` – Blockierungskategorie löschen – JS: `examples/javascript/delete-block-category.js` – PHP: `examples/php/delete-block-category.php`
- `listPlaces()` – `GET /places` – Rolle `viewer` – Orte lesen – JS: `examples/javascript/list-places.js` – PHP: `examples/php/list-places.php`
- `createPlace(data)` – `POST /places` – Rolle `admin` – Ort anlegen – JS: `examples/javascript/create-place.js` – PHP: `examples/php/create-place.php`
- `updatePlace(id, data)` – `PUT /places/{id}` – Rolle `admin` – Ort ändern – JS: `examples/javascript/update-place.js` – PHP: `examples/php/update-place.php`
- `deletePlace(id)` – `DELETE /places/{id}` – Rolle `admin` – Ort löschen – JS: `examples/javascript/delete-place.js` – PHP: `examples/php/delete-place.php`

## Kalenderansichts-API

Der Einstieg in die Kalenderansicht erfolgt über:

```js
TinyCalendar.render(filterObjekt);
```

### Kein Filter

`null` ist der verbindliche ungefilterte Zustand. Dadurch werden alle geladenen Termine des sichtbaren Zeitraums dargestellt:

```js
TinyCalendar.render(null);
```

Auch ein Aufruf ohne Argument wird aus Kompatibilitätsgründen wie `null` behandelt. Für neuen Code soll dennoch `render(null)` verwendet werden, wenn ausdrücklich alle Datensätze angezeigt werden sollen.

### Filter nach Feldern

Jede Eigenschaft des Terminobjekts und jedes in `filter_data` gelieferte DataForm-Feld kann als Filter verwendet werden:

```js
TinyCalendar.render({ category_id: 3 });
TinyCalendar.render({ to_student: 17, activ: true });
TinyCalendar.render({ event_status: ['planned', 'confirmed'] });
```

Mehrere Felder werden logisch mit **UND** verbunden. Ein Array bedeutet: Der Feldwert darf einem der Arraywerte entsprechen.

### Operatoren

Für komplexere Bedingungen kann der Filterwert ein Operatorobjekt sein:

```js
TinyCalendar.render({ thema: { contains: 'Mathematik' } });
TinyCalendar.render({ start: { gte: '2026-10-10T08:00:00', lt: '2026-10-10T12:00:00' } });
```

Unterstützte Operatoren: `eq`, `neq`, `in`, `contains`, `startsWith`, `endsWith`, `gt`, `gte`, `lt`, `lte`, `between`, `empty`.

### Aktiven Filter lesen

```js
const filter = TinyCalendar.getFilter();
```

Das Ergebnis ist `null`, wenn kein Filter aktiv ist, ansonsten eine Kopie des aktuell gesetzten Filterobjekts.

### Filter zurücksetzen

```js
TinyCalendar.render(null);
```

### Beispiele

- `examples/javascript/render-all.js` – ungefilterte Ansicht mit `render(null)`
- `examples/javascript/render-filter.js` – Feld-, Mehrfachwert- und Operatorfilter
- `examples/javascript/get-filter.js` – aktuellen Filter lesen und anschließend zurücksetzen

## Filterbare Daten

Die Runtime prüft beim Filtern zunächst die normalisierten Terminwerte und zusätzlich `filter_data`. Dadurch sind nicht nur die Standardfelder, sondern auch projektspezifische DataForm-Felder filterbar. Standardmäßig gehören unter anderem folgende Werte dazu:

`id`, `to_role`, `to_tutor`, `to_student`, `start`, `end`, `activ`, `thema`, `description`, `location`, `location_name`, `category_id`, `category_name`, `event_status`, `appendizies` sowie die zusätzlichen Felder in `filter_data`.

## Zeitblockierungen

Blockierungen können personenbezogen sein. `person_id = null`, `0` oder leer bedeutet **Alle Personen**. Ist `person_id` gesetzt, gilt die Blockierung nur für diese Person. Mehrere personenbezogene Blockierungen dürfen denselben Zeitraum besitzen und werden getrennt geführt.

Beispiel:

```json
{
  "start": "2026-10-10T08:00:00",
  "end": "2026-10-10T10:00:00",
  "category_id": 2,
  "person_id": 17,
  "reason": "Nicht verfügbar"
}
```

## Hinweise

Datums-/Zeitwerte für Schreibzugriffe: `YYYY-MM-DDTHH:MM[:SS]`. Bei Überschneidung mit einer wirksamen aktiven Blockierung liefert die Event-API HTTP 409 und `TIME_BLOCKED`. Tokens nicht in öffentlich ausgeliefertem JavaScript speichern.

**Dokumentationsstand:** 2026-10-10 · **Revision:** HF156
