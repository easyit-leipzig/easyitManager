<?php
declare(strict_types=1);
namespace DataForm5\Modules\Versioning;
final class VersionConstraint
{
    public static function matches(string $version,string $constraint): bool
    {
        $version=self::normalize($version); $constraint=trim($constraint);
        if($constraint===''||$constraint==='*') return true;
        foreach(preg_split('/\s*\|\|\s*/',$constraint) ?: [] as $alternative){
            $ok=true;
            foreach(preg_split('/\s*,\s*|\s+(?=[<>~=^\d])/',$alternative,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $part){
                if(!self::matchesPart($version,trim($part))){$ok=false;break;}
            }
            if($ok)return true;
        }
        return false;
    }
    public static function normalize(string $version): string
    {
        if(preg_match('/(\d+)\.(\d+)\.(\d+)/',$version,$m)) return $m[1].'.'.$m[2].'.'.$m[3];
        if(preg_match('/(\d+)\.(\d+)/',$version,$m)) return $m[1].'.'.$m[2].'.0';
        return '0.0.0';
    }
    private static function matchesPart(string $version,string $part): bool
    {
        if($part===''||$part==='*')return true;
        if(str_starts_with($part,'^')){
            $base=self::normalize(substr($part,1)); [$a,$b,$c]=array_map('intval',explode('.',$base));
            $upper=$a>0?($a+1).'.0.0':($b>0?'0.'.($b+1).'.0':'0.0.'.($c+1));
            return version_compare($version,$base,'>=')&&version_compare($version,$upper,'<');
        }
        if(str_starts_with($part,'~')){
            $base=self::normalize(substr($part,1)); [$a,$b]=array_map('intval',array_slice(explode('.',$base),0,2));
            $upper=$a.'.'.($b+1).'.0'; return version_compare($version,$base,'>=')&&version_compare($version,$upper,'<');
        }
        if(preg_match('/^(>=|<=|>|<|=|==)?\s*(\d+(?:\.\d+){0,2}(?:[-+][0-9A-Za-z.-]+)?)$/',$part,$m)){
            $op=$m[1]?:'=='; return version_compare($version,self::normalize($m[2]),$op);
        }
        return false;
    }
}
