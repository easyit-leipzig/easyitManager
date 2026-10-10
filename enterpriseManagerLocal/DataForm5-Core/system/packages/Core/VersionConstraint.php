<?php
declare(strict_types=1);
namespace DataForm5\Packages\Core;
final class VersionConstraint
{
 public static function matches(string $version,string $constraint):bool{$constraint=trim($constraint);if($constraint===''||$constraint==='*')return true;if(str_starts_with($constraint,'^')){$base=substr($constraint,1);$v=explode('.',$base);$upper=((int)$v[0]+1).'.0.0';return version_compare($version,$base,'>=')&&version_compare($version,$upper,'<');}if(str_starts_with($constraint,'~')){$base=substr($constraint,1);$v=explode('.',$base);$upper=((int)$v[0]).'.'.(((int)($v[1]??0))+1).'.0';return version_compare($version,$base,'>=')&&version_compare($version,$upper,'<');}if(preg_match('/^(>=|<=|>|<|=)\s*(.+)$/',$constraint,$m))return version_compare($version,$m[2],$m[1]);return version_compare($version,$constraint,'==');}
}
