<?php
declare(strict_types=1);

namespace EasyIT\Calendar;

use PDO;
use RuntimeException;

final class Calendar
{
    private array $options;
    private array $config;
    private PDO $pdo;
    private ?EventRepository $events = null;
    private ?PlaceRepository $places = null;
    private ?CategoryRepository $categories = null;

    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->config = \df_config();
        $this->pdo = \df_pdo();

        if (isset($options['project_id']) && (int)$options['project_id'] !== (int)($this->config['project_id'] ?? 0)) {
            throw new RuntimeException('Das angeforderte Calendar-Projekt ist in diesem Paket nicht aktiv.');
        }
    }

    public function events(): EventRepository
    {
        return $this->events ??= new EventRepository($this->pdo, $this->config);
    }

    public function places(): PlaceRepository
    {
        return $this->places ??= new PlaceRepository($this->pdo, $this->config);
    }

    public function categories(): CategoryRepository
    {
        return $this->categories ??= new CategoryRepository($this->pdo, $this->config);
    }

    public function config(): array
    {
        return $this->config;
    }

    public function projectId(): int
    {
        return (int)($this->config['project_id'] ?? 0);
    }

    public function projectName(): string
    {
        return (string)($this->config['project_name'] ?? 'easyITCalendar');
    }

    public function url(): string
    {
        $configured = trim((string)($this->options['base_url'] ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/index.php';
        }

        $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
        $calendar = realpath(EASYIT_CALENDAR_ROOT) ?: EASYIT_CALENDAR_ROOT;
        if ($root !== '') {
            $rootNorm = str_replace('\\', '/', rtrim($root, '/\\'));
            $calNorm = str_replace('\\', '/', $calendar);
            if (str_starts_with(strtolower($calNorm), strtolower($rootNorm . '/'))) {
                $relative = substr($calNorm, strlen($rootNorm));
                return str_replace(' ', '%20', $relative) . '/index.php';
            }
        }
        return 'index.php';
    }

    public function render(array $attributes = []): void
    {
        $src = $this->url();
        $height = max(300, (int)($attributes['height'] ?? 850));
        $title = htmlspecialchars((string)($attributes['title'] ?? $this->projectName()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $srcEscaped = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $style = 'width:100%;height:' . $height . 'px;border:0;display:block;';
        echo '<iframe class="easyit-calendar-frame" src="' . $srcEscaped . '" title="' . $title . '" style="' . $style . '"></iframe>';
    }
}
