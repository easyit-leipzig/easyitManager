<?php
declare(strict_types=1);
namespace DataForm5\View\Core;
final class ThemeManager
{
    public function __construct(private string $themesPath, private string $active = 'default') {}
    public function active(): string { return $this->active; }
    public function use(string $theme): void
    {
        if (!is_dir($this->themesPath.'/'.$theme)) throw new \InvalidArgumentException("Theme '{$theme}' existiert nicht.");
        $this->active = $theme;
    }
    public function path(string $relative = ''): string
    {
        return rtrim($this->themesPath.'/'.$this->active.'/'.ltrim($relative,'/'), '/');
    }
}
