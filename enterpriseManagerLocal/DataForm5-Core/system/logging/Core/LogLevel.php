<?php
declare(strict_types=1);
namespace DataForm5\Logging\Core;
final class LogLevel
{
    public const EMERGENCY='emergency', ALERT='alert', CRITICAL='critical', ERROR='error', WARNING='warning', NOTICE='notice', INFO='info', DEBUG='debug';
    private const WEIGHTS=[self::DEBUG=>100,self::INFO=>200,self::NOTICE=>250,self::WARNING=>300,self::ERROR=>400,self::CRITICAL=>500,self::ALERT=>550,self::EMERGENCY=>600];
    public static function validate(string $level): string { $level=strtolower($level); if(!isset(self::WEIGHTS[$level])) throw new \InvalidArgumentException("Unbekanntes Log-Level '{$level}'."); return $level; }
    public static function allows(string $minimum, string $level): bool { return self::WEIGHTS[self::validate($level)] >= self::WEIGHTS[self::validate($minimum)]; }
}
