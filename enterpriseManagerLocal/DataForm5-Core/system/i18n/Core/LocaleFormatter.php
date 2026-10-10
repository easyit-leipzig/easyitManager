<?php
declare(strict_types=1);
namespace DataForm5\I18n\Core;
final class LocaleFormatter
{
    public function __construct(private string $locale = 'de_DE', private string $timezone = 'Europe/Berlin') {}
    public function number(int|float $value, int $decimals = 2): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter(str_replace('_','-',$this->locale), \NumberFormatter::DECIMAL);
            $f->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $f->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            return (string)$f->format($value);
        }
        $german = str_starts_with($this->locale, 'de');
        return number_format($value, $decimals, $german ? ',' : '.', $german ? '.' : ',');
    }
    public function currency(int|float $value, string $currency = 'EUR'): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter(str_replace('_','-',$this->locale), \NumberFormatter::CURRENCY);
            return (string)$f->formatCurrency($value, $currency);
        }
        return $this->number($value, 2).' '.$currency;
    }
    public function date(\DateTimeInterface|string $value, string $style = 'medium'): string
    {
        $date = is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone($this->timezone)) : \DateTimeImmutable::createFromInterface($value);
        $date = $date->setTimezone(new \DateTimeZone($this->timezone));
        if (class_exists(\IntlDateFormatter::class)) {
            $styles = ['short'=>\IntlDateFormatter::SHORT,'medium'=>\IntlDateFormatter::MEDIUM,'long'=>\IntlDateFormatter::LONG,'full'=>\IntlDateFormatter::FULL];
            $f = new \IntlDateFormatter(str_replace('_','-',$this->locale), $styles[$style] ?? \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $this->timezone);
            return (string)$f->format($date);
        }
        return $date->format(str_starts_with($this->locale, 'de') ? 'd.m.Y' : 'Y-m-d');
    }
}
