# HF134 – Calendar PHP-API im Enterprise-Manager-Export

Der Enterprise-Manager enthält nun den öffentlichen Root-Einstieg `easyITCalendar/bootstrap.php` sowie `src/Calendar.php`, `src/BaseRepository.php`, `src/EventRepository.php`, `src/PlaceRepository.php` und `src/CategoryRepository.php`.

Der Calendar-Standalone-/HTML-Export übernimmt diese Dateien automatisch. Zusätzlich werden `examples/php-new-calendar.php`, `docs/PHP-API.md`, `config/` und `public/` in jedes neu erzeugte Calendar-Paket übernommen. Damit steht nach dem Export unmittelbar `require_once .../easyITCalendar/bootstrap.php` und `new Calendar()` zur Verfügung.
