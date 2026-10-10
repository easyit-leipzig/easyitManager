<?php
declare(strict_types=1);
namespace DataForm5\Security\Http;
use Closure;
use DataForm5\Security\Csrf\CsrfTokenManager;
use DataForm5\Security\SecurityException;
final readonly class VerifyCsrfToken {
    public function __construct(private CsrfTokenManager $csrf) {}
    public function handle(mixed $request, Closure $next): mixed { $method = strtoupper((string)($request['method'] ?? 'GET')); if (in_array($method, ['POST','PUT','PATCH','DELETE'], true) && !$this->csrf->validate($request['_token'] ?? null)) throw new SecurityException('Ungültiges CSRF-Token.'); return $next($request); }
}
