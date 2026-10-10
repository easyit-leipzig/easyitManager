<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

final class SemVersion
{
    /** @return array{major:int,minor:int,patch:int,pre:string} */
    public static function parse(string $version): array
    {
        $version = ltrim(trim($version), 'vV');
        if (!preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/', $version, $m)) {
            throw new \InvalidArgumentException('Ungültige SemVer-Version: ' . $version);
        }
        return ['major'=>(int)$m[1],'minor'=>(int)$m[2],'patch'=>(int)$m[3],'pre'=>(string)($m[4]??'')];
    }

    public static function normalize(string $version): string
    {
        $p = self::parse($version);
        return $p['major'].'.'.$p['minor'].'.'.$p['patch'].($p['pre']!==''?'-'.$p['pre']:'');
    }

    public static function compare(string $a, string $b): int
    {
        $x=self::parse($a);$y=self::parse($b);
        foreach(['major','minor','patch'] as $k){$c=$x[$k]<=>$y[$k];if($c!==0)return $c;}
        if($x['pre']===$y['pre'])return 0;
        if($x['pre']==='')return 1;
        if($y['pre']==='')return -1;
        $xa=explode('.',$x['pre']);$ya=explode('.',$y['pre']);$n=max(count($xa),count($ya));
        for($i=0;$i<$n;$i++){
            if(!isset($xa[$i]))return -1;if(!isset($ya[$i]))return 1;
            $ai=$xa[$i];$bi=$ya[$i];if($ai===$bi)continue;
            $an=ctype_digit($ai);$bn=ctype_digit($bi);
            if($an&&$bn)return (int)$ai<=>(int)$bi;
            if($an!==$bn)return $an?-1:1;
            return strcmp($ai,$bi)<=>0;
        }
        return 0;
    }

    public static function isValidConstraint(string $constraint): bool
    {
        try { self::assertConstraint($constraint); return true; } catch (\Throwable) { return false; }
    }

    public static function satisfies(string $version, string $constraint): bool
    {
        self::parse($version);self::assertConstraint($constraint);
        $constraint=trim($constraint);
        if($constraint===''||$constraint==='*')return true;
        foreach(preg_split('/\s*\|\|\s*/',$constraint)?:[] as $alternative){
            if(self::satisfiesAnd($version,trim($alternative)))return true;
        }
        return false;
    }

    private static function assertConstraint(string $constraint): void
    {
        $constraint=trim($constraint);if($constraint===''||$constraint==='*')return;
        foreach(preg_split('/\s*\|\|\s*/',$constraint)?:[] as $alternative){
            $alternative=trim($alternative);if($alternative==='')throw new \InvalidArgumentException('Leere SemVer-Alternative.');
            $tokens=preg_split('/[\s,]+/',$alternative,-1,PREG_SPLIT_NO_EMPTY)?:[];
            if($tokens===[])throw new \InvalidArgumentException('Ungültige SemVer-Bedingung.');
            foreach($tokens as $token)self::assertToken($token);
        }
    }

    private static function assertToken(string $token): void
    {
        if($token==='*')return;
        if(preg_match('/^[~^](\d+)\.(\d+)\.(\d+)$/',$token))return;
        if(preg_match('/^(>=|<=|>|<|=)?(\d+)\.(\d+)\.(\d+)(?:-[0-9A-Za-z.-]+)?$/',$token))return;
        if(preg_match('/^(\d+)\.(\d+)\.(x|X|\*)$/',$token))return;
        if(preg_match('/^(\d+)\.(x|X|\*)$/',$token))return;
        throw new \InvalidArgumentException('Ungültiger SemVer-Ausdruck: '.$token);
    }

    private static function satisfiesAnd(string $version,string $expr): bool
    {
        $tokens=preg_split('/[\s,]+/',$expr,-1,PREG_SPLIT_NO_EMPTY)?:[];
        foreach($tokens as $token){if(!self::satisfiesToken($version,$token))return false;}return true;
    }

    private static function satisfiesToken(string $version,string $token): bool
    {
        if($token==='*')return true;
        if($token[0]==='^'){
            $base=self::normalize(substr($token,1));$p=self::parse($base);
            if($p['major']>0)$upper=($p['major']+1).'.0.0';
            elseif($p['minor']>0)$upper='0.'.($p['minor']+1).'.0';
            else $upper='0.0.'.($p['patch']+1);
            return self::compare($version,$base)>=0&&self::compare($version,$upper)<0;
        }
        if($token[0]==='~'){
            $base=self::normalize(substr($token,1));$p=self::parse($base);$upper=$p['major'].'.'.($p['minor']+1).'.0';
            return self::compare($version,$base)>=0&&self::compare($version,$upper)<0;
        }
        if(preg_match('/^(\d+)\.(\d+)\.(x|X|\*)$/',$token,$m)){$p=self::parse($version);return $p['major']===(int)$m[1]&&$p['minor']===(int)$m[2];}
        if(preg_match('/^(\d+)\.(x|X|\*)$/',$token,$m)){$p=self::parse($version);return $p['major']===(int)$m[1];}
        preg_match('/^(>=|<=|>|<|=)?(.+)$/',$token,$m);$op=$m[1]??'=';$base=self::normalize((string)$m[2]);$cmp=self::compare($version,$base);
        return match($op){'>='=>$cmp>=0,'<='=>$cmp<=0,'>'=>$cmp>0,'<'=>$cmp<0,default=>$cmp===0};
    }
}
