<?php
declare(strict_types=1);
namespace DataForm5\Http\Core;
final class Route {
    /** @param list<string> $methods @param callable|array{class-string,string} $handler @param list<class-string|object|callable> $middleware */
    public function __construct(public array $methods, public string $path, public mixed $handler, public ?string $name=null, public array $middleware=[]) {}
    public function name(string $name):self{$this->name=$name;return $this;} public function middleware(string|object|callable ...$middleware):self{array_push($this->middleware,...$middleware);return $this;}
}
