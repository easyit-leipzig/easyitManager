<?php
declare(strict_types=1);
namespace DataForm5\Hardening\Middleware;
use Closure;use DataForm5\Http\Contracts\MiddlewareInterface;use DataForm5\Http\Core\Request;use DataForm5\Http\Core\Response;
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
 public function __construct(private readonly array $headers=[]){ }
 public function handle(Request $request,Closure $next):Response
 {
  $response=$next($request);$defaults=['X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'SAMEORIGIN','Referrer-Policy'=>'strict-origin-when-cross-origin','Permissions-Policy'=>'camera=(), microphone=(), geolocation=()','Content-Security-Policy'=>"default-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'",'Cross-Origin-Opener-Policy'=>'same-origin'];
  foreach($this->headers+$defaults as $name=>$value){$response=$response->withHeader((string)$name,(string)$value);}return $response;
 }
}
