<?php
declare(strict_types=1);
namespace DataForm5\Testing\Core;
use DataForm5\Testing\Exceptions\AssertionFailed;
use Throwable;
final class Assert
{
    public static function true(bool $condition, string $message = 'Bedingung ist nicht wahr.'): void { if (!$condition) throw new AssertionFailed($message); }
    public static function false(bool $condition, string $message = 'Bedingung ist nicht falsch.'): void { self::true(!$condition, $message); }
    public static function same(mixed $expected, mixed $actual, string $message = ''): void { if ($expected !== $actual) throw new AssertionFailed($message !== '' ? $message : 'Werte sind nicht identisch: '.self::export($expected).' !== '.self::export($actual)); }
    public static function equals(mixed $expected, mixed $actual, string $message = ''): void { if ($expected != $actual) throw new AssertionFailed($message !== '' ? $message : 'Werte sind nicht gleich.'); }
    public static function null(mixed $actual, string $message = 'Wert ist nicht null.'): void { self::same(null, $actual, $message); }
    public static function notNull(mixed $actual, string $message = 'Wert ist null.'): void { if ($actual === null) throw new AssertionFailed($message); }
    public static function contains(string $needle, string $haystack, string $message = ''): void { if (!str_contains($haystack, $needle)) throw new AssertionFailed($message !== '' ? $message : "Text enthält '{$needle}' nicht."); }
    public static function count(int $expected, array|\Countable $actual, string $message = ''): void { self::same($expected, count($actual), $message !== '' ? $message : 'Unerwartete Anzahl.'); }
    public static function instanceOf(string $class, mixed $actual, string $message = ''): void { if (!$actual instanceof $class) throw new AssertionFailed($message !== '' ? $message : "Objekt ist keine Instanz von {$class}."); }
    public static function throws(callable $callback, string $exceptionClass = Throwable::class, string $message = ''): Throwable
    {
        try { $callback(); } catch (Throwable $e) { if (!$e instanceof $exceptionClass) throw new AssertionFailed($message !== '' ? $message : 'Falsche Exception-Klasse: '.$e::class); return $e; }
        throw new AssertionFailed($message !== '' ? $message : "Erwartete Exception {$exceptionClass} wurde nicht ausgelöst.");
    }
    private static function export(mixed $value): string { return var_export($value, true); }
}
