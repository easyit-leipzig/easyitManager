<?php
declare(strict_types=1);
namespace DataForm5\View\Core;
use DataForm5\View\Exceptions\ViewException;
final class ViewContext
{
    private ?string $layout = null;
    private array $sections = [];
    private array $stack = [];
    public function __construct(private ViewFactory $factory, private array $data = []) {}
    public function extend(string $layout): void { $this->layout = $layout; }
    public function layout(): ?string { return $this->layout; }
    public function start(string $name): void { $this->stack[]=$name; ob_start(); }
    public function end(): void
    {
        $name=array_pop($this->stack); if($name===null) throw new ViewException('Kein offener View-Abschnitt.');
        $this->sections[$name]=(string)ob_get_clean();
    }
    public function section(string $name, string $default=''): string { return $this->sections[$name] ?? $default; }
    public function sections(): array { return $this->sections; }
    public function importSections(array $sections): void { $this->sections=$sections+$this->sections; }
    public function component(string $view, array $data=[]): string { return $this->factory->make($view, $data+$this->data); }
    public function e(mixed $value): string { return Html::escape($value); }
}
