<?php
declare(strict_types=1);
namespace DataForm5\Security\Authorization;
final class CapabilityService {
 public static function allows(array $user,string $capability): bool { return in_array('admin',$user['roles']??[],true) || in_array($capability,$user['permissions']??[],true); }
 public static function require(array $user,string $capability): void { if(!self::allows($user,$capability)){http_response_code(403);exit('Berechtigung fehlt: '.htmlspecialchars($capability,ENT_QUOTES,'UTF-8'));} }
}