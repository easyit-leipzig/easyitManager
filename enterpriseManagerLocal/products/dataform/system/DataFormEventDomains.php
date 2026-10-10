<?php
declare(strict_types=1);

/**
 * DataForm5 STAND 4
 * Kanonische Ereignisdomänen.
 *
 * DATAFORM: nur Formular-/Ansichts-Lifecycle.
 * RECORDSET: ausschließlich Datensatz-/CRUD-Lifecycle.
 */
final class DataFormEventDomains
{
    public const DATAFORM_EVENTS = [
        'open',
        'close',
        'before_view_change',
        'view_change',
        'before_refresh',
        'after_refresh',
    ];

    public const RECORDSET_EVENTS = [
        'before_current_change',
        'after_current_change',
        'before_field_change',
        'after_field_change',
        'before_new',
        'after_new',
        'before_validate',
        'after_validate',
        'before_save',
        'after_save',
        'before_insert',
        'after_insert',
        'before_update',
        'after_update',
        'before_delete',
        'after_delete',
    ];

    public static function isDataFormEvent(string $event): bool
    {
        return in_array($event, self::DATAFORM_EVENTS, true);
    }

    public static function isRecordSetEvent(string $event): bool
    {
        return in_array($event, self::RECORDSET_EVENTS, true);
    }

    public static function assertDisjoint(): void
    {
        $cross = array_intersect(self::DATAFORM_EVENTS, self::RECORDSET_EVENTS);
        if ($cross !== []) {
            throw new LogicException('DataForm- und RecordSet-Ereignisdomänen überlappen: '.implode(', ', $cross));
        }
    }

    public static function sanitizeDataFormHandlers(array $handlers): array
    {
        $out = [];
        foreach (self::DATAFORM_EVENTS as $event) {
            $code = trim((string)($handlers[$event] ?? ''));
            self::assertHandlerLength($code, 'DataForm', $event);
            $out[$event] = $code;
        }
        return $out;
    }

    public static function sanitizeRecordSetHandlers(array $handlers): array
    {
        $out = [];
        foreach (self::RECORDSET_EVENTS as $event) {
            $code = trim((string)($handlers[$event] ?? ''));
            self::assertHandlerLength($code, 'RecordSet', $event);
            $out[$event] = $code;
        }
        return $out;
    }

    public static function dataFormHandlersFromPost(array $post, string $prefix = 'dataform_event_'): array
    {
        $handlers = [];
        foreach (self::DATAFORM_EVENTS as $event) {
            $handlers[$event] = (string)($post[$prefix.$event] ?? '');
        }
        return self::sanitizeDataFormHandlers($handlers);
    }

    public static function recordSetHandlersFromPost(array $post, string $prefix = 'record_event_'): array
    {
        $handlers = [];
        foreach (self::RECORDSET_EVENTS as $event) {
            $handlers[$event] = (string)($post[$prefix.$event] ?? '');
        }
        return self::sanitizeRecordSetHandlers($handlers);
    }

    /**
     * Trennt einen historischen gemischten DataForm-Handlerbestand.
     * Rückgabe:
     *   dataform  => nur DF-Lifecycle
     *   recordset => nur DS/CRUD
     *   unknown   => unbekannte Schlüssel, die NICHT stillschweigend ausgeführt werden
     */
    public static function splitLegacy(array $legacy, array $existingRecordSet = []): array
    {
        $df = [];
        $rs = self::sanitizeRecordSetHandlers($existingRecordSet);
        $unknown = [];

        $legacyMap = [
            'on_open'       => 'open',
            'record_change' => 'after_current_change',
            'field_changed' => 'after_field_change',
        ];

        foreach ($legacy as $key => $value) {
            $key = (string)$key;
            $code = trim((string)$value);
            $mapped = $legacyMap[$key] ?? $key;

            if (self::isDataFormEvent($mapped)) {
                $df[$mapped] = $code;
            } elseif (self::isRecordSetEvent($mapped)) {
                if (($rs[$mapped] ?? '') === '' && $code !== '') {
                    $rs[$mapped] = $code;
                }
            } else {
                $unknown[$key] = $code;
            }
        }

        $df = self::sanitizeDataFormHandlers($df);
        $rs = self::sanitizeRecordSetHandlers($rs);

        return ['dataform'=>$df, 'recordset'=>$rs, 'unknown'=>$unknown];
    }

    private static function assertHandlerLength(string $code, string $domain, string $event): void
    {
        if (strlen($code) > 8000) {
            throw new InvalidArgumentException($domain.'-Event-Handler ist zu lang: '.$event);
        }
    }
}

DataFormEventDomains::assertDisjoint();
