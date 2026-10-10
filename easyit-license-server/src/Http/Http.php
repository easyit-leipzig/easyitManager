<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Http;

final class Http
{
    public static function headers(): array
    {
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $n = strtolower(str_replace('_', '-', substr($k, 5)));
                $h[$n] = (string) $v;
            }
        }
        return $h;
    }

    public static function body(): string
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * Liefert immer den API-lokalen Pfad, unabhängig davon, ob der Server
     * unter /, /easyit-license-server oder /enterpriseManager/easyit-license-server
     * installiert ist. Das ist zugleich der Pfad, den der Enterprise-Client
     * in seiner Ed25519-Request-Signatur verwendet.
     */
    public static function path(): string
    {
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base === '.' || $base === '/') {
            $base = '';
        }
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        if ($path === '' || $path === '/index.php') {
            return '/';
        }
        return '/' . ltrim($path, '/');
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}
