<?php
declare(strict_types=1);
namespace DataForm5\Security\Http;
use Closure;
use DataForm5\Security\Auth\AuthManager;
use DataForm5\Security\SecurityException;
final readonly class Authenticate {
    public function __construct(private AuthManager $auth) {}
    public function handle(mixed $request, Closure $next): mixed { if ($this->auth->guest()) throw new SecurityException('Authentifizierung erforderlich.'); return $next($request); }
}
