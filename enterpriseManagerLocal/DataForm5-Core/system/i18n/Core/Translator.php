<?php
declare(strict_types=1);
namespace DataForm5\I18n\Core;
use DataForm5\I18n\Contracts\TranslatorInterface;
use DataForm5\I18n\Exceptions\I18nException;
final class Translator implements TranslatorInterface
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $loaded = [];
    public function __construct(private string $path, private string $currentLocale = 'de_DE', private string $fallbackLocale = 'en_US') {}
    public function locale(): string { return $this->currentLocale; }
    public function setLocale(string $locale): void { $this->assertLocale($locale); $this->currentLocale = $locale; }
    public function has(string $key, ?string $locale = null): bool { return $this->resolve($key, $locale ?? $this->currentLocale) !== null; }
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->currentLocale;
        $value = $this->resolve($key, $locale) ?? ($locale !== $this->fallbackLocale ? $this->resolve($key, $this->fallbackLocale) : null) ?? $key;
        if (!is_string($value)) return $key;
        foreach ($replace as $name => $replacement) $value = str_replace([':'.$name, '{'.$name.'}'], (string)$replacement, $value);
        return $value;
    }
    private function resolve(string $key, string $locale): mixed
    {
        [$group, $item] = array_pad(explode('.', $key, 2), 2, '');
        if ($group === '' || $item === '') return null;
        $messages = $this->load($locale, $group);
        $value = $messages;
        foreach (explode('.', $item) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return null;
            $value = $value[$segment];
        }
        return $value;
    }
    /** @return array<string,mixed> */
    private function load(string $locale, string $group): array
    {
        if (isset($this->loaded[$locale][$group])) return $this->loaded[$locale][$group];
        $this->assertLocale($locale);
        $file = rtrim($this->path, '/\\').DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$group.'.php';
        if (!is_file($file)) return $this->loaded[$locale][$group] = [];
        $messages = require $file;
        if (!is_array($messages)) throw new I18nException("Übersetzungsdatei '{$file}' muss ein Array liefern.");
        return $this->loaded[$locale][$group] = $messages;
    }
    private function assertLocale(string $locale): void
    {
        if (!preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $locale)) throw new I18nException("Ungültige Locale '{$locale}'.");
    }
}
