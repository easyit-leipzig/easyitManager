<?php
declare(strict_types=1);
namespace DataForm5\View\Core;
final class AssetManager
{
    public function __construct(private ThemeManager $themes, private string $baseUrl = '') {}
    public function url(string $asset): string
    {
        $asset = ltrim($asset, '/');
        return rtrim($this->baseUrl, '/').'/themes/'.$this->themes->active().'/assets/'.$asset;
    }
    public function versioned(string $asset): string
    {
        $url = $this->url($asset); $file = $this->themes->path('assets/'.$asset);
        return is_file($file) ? $url.'?v='.substr(hash_file('sha256',$file),0,12) : $url;
    }
}
