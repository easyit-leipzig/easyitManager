<?php
declare(strict_types=1);
namespace DataForm5\I18n\Contracts;
interface TranslatorInterface
{
    /** @param array<string, scalar|null> $replace */
    public function get(string $key, array $replace = [], ?string $locale = null): string;
    public function has(string $key, ?string $locale = null): bool;
    public function locale(): string;
    public function setLocale(string $locale): void;
}
