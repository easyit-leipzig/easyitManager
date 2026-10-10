<?php
declare(strict_types=1);

/**
 * Öffentlicher PHP-Einstiegspunkt für easyITCalendar.
 *
 * Verwendung:
 *   require_once '/pfad/easyITCalendar/bootstrap.php';
 *   $calendar = new Calendar();
 */

if (!defined('EASYIT_CALENDAR_ROOT')) {
    define('EASYIT_CALENDAR_ROOT', __DIR__);
}

require_once EASYIT_CALENDAR_ROOT . '/lib/DataFormApp.php';
require_once EASYIT_CALENDAR_ROOT . '/src/BaseRepository.php';
require_once EASYIT_CALENDAR_ROOT . '/src/EventRepository.php';
require_once EASYIT_CALENDAR_ROOT . '/src/PlaceRepository.php';
require_once EASYIT_CALENDAR_ROOT . '/src/CategoryRepository.php';
require_once EASYIT_CALENDAR_ROOT . '/src/Calendar.php';

// Komfort-Alias: erlaubt bewusst "$calendar = new Calendar();"
// ohne Namespace-Import in kleinen PHP-Anwendungen.
if (!class_exists('Calendar', false)) {
    class_alias(\EasyIT\Calendar\Calendar::class, 'Calendar');
}
