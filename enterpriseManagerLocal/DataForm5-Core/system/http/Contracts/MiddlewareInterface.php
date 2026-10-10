<?php
declare(strict_types=1);
namespace DataForm5\Http\Contracts;
use Closure;
use DataForm5\Http\Core\Request;
use DataForm5\Http\Core\Response;
interface MiddlewareInterface { public function handle(Request $request, Closure $next): Response; }
