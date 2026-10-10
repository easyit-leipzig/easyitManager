<?php
declare(strict_types=1);
namespace DataForm5\Http\Core;
final readonly class HttpKernel { public function __construct(private Router $router){} public function handle(?Request $request=null):Response{return $this->router->dispatch($request??Request::capture());} }
