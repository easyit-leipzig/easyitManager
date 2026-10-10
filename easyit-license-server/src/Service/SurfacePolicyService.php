<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use PDO;

final class SurfacePolicyService
{
    public function __construct(private PDO $pdo, private array $networkCfg=[]) {}

    /**
     * HF133: Surface policy can be inspected without blocking account authentication.
     * Productive API calls still use assertForLicense().
     * @return array{allowed:bool,error_code:?string,origin:string,host:string,source_ip:string,customer_id:string}
     */
    public function checkForLicense(string $licenseId,array $payload): array
    {
        $st=$this->pdo->prepare('SELECT c.* FROM customers c JOIN licenses l ON l.customer_id=c.customer_id WHERE l.license_id=? LIMIT 1');
        $st->execute([$licenseId]);$c=$st->fetch();if(!$c)throw new \RuntimeException('CUSTOMER_NOT_FOUND');
        $surface=(array)($payload['_surface']??[]);$origin=trim((string)($surface['origin']??''));$host=$this->hostFromOrigin($origin,(string)($surface['host']??''));
        $ip=$this->sourceIp();$error=null;
        if((int)($c['surface_domain_required']??1)===1){
            if($host==='')$error='SURFACE_DOMAIN_REQUIRED';
            else{
                $rules=$this->activeDomains((string)$c['customer_id']);
                if(!$rules)$error='SURFACE_DOMAIN_NOT_CONFIGURED';
                else{
                    $ok=false;foreach($rules as $rule){if($this->domainMatches($host,(string)$rule['domain_pattern'])){$ok=true;break;}}
                    if(!$ok)$error='SURFACE_DOMAIN_NOT_ALLOWED';
                }
            }
        }
        if($error===null&&(int)($c['surface_ip_required']??0)===1){
            $rules=$this->activeNetworks((string)$c['customer_id']);
            if(!$rules)$error='SURFACE_IP_NOT_CONFIGURED';
            else{
                $ok=false;foreach($rules as $rule){if($this->cidrContains((string)$rule['cidr'],$ip)){$ok=true;break;}}
                if(!$ok)$error='SURFACE_IP_NOT_ALLOWED';
            }
        }
        return ['allowed'=>$error===null,'error_code'=>$error,'origin'=>$origin,'host'=>$host,'source_ip'=>$ip,'customer_id'=>(string)$c['customer_id']];
    }

    /** @return array{origin:string,host:string,source_ip:string,customer_id:string} */
    public function assertForLicense(string $licenseId,array $payload): array
    {
        $status=$this->checkForLicense($licenseId,$payload);
        if(!$status['allowed'])throw new \RuntimeException((string)$status['error_code']);
        return ['origin'=>$status['origin'],'host'=>$status['host'],'source_ip'=>$status['source_ip'],'customer_id'=>$status['customer_id']];
    }

    public function normalizeDomainPattern(string $value): string
    {
        $value=strtolower(trim($value));$wild=str_starts_with($value,'*.');if($wild)$value=substr($value,2);
        if(str_contains($value,'://')){$h=parse_url($value,PHP_URL_HOST);if(is_string($h)&&$h!=='')$value=strtolower($h);}
        $value=rtrim($value,'.');
        if($value==='localhost')return $wild?'*.localhost':'localhost';
        if(!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$value))throw new \RuntimeException('SURFACE_DOMAIN_INVALID');
        return ($wild?'*.':'').$value;
    }

    public function normalizeCidr(string $value): string
    {
        $value=trim($value);if($value==='')throw new \RuntimeException('SURFACE_IP_INVALID');
        [$ip,$prefix]=array_pad(explode('/',$value,2),2,null);$packed=@inet_pton($ip);if($packed===false)throw new \RuntimeException('SURFACE_IP_INVALID');
        $bits=strlen($packed)*8;$p=$prefix===null?$bits:(int)$prefix;if((string)$p!==(string)($prefix??$bits) || $p<0 || $p>$bits)throw new \RuntimeException('SURFACE_IP_INVALID');
        return strtolower($ip).'/'.$p;
    }

    public function removeDomainRuleSafely(string $customerId,string $ruleId,string $currentHost=''): bool
    {
        $started=!$this->pdo->inTransaction();if($started)$this->pdo->beginTransaction();
        try{
            $st=$this->pdo->prepare('SELECT surface_domain_required FROM customers WHERE customer_id=? FOR UPDATE');$st->execute([$customerId]);$required=(int)($st->fetchColumn()?:0)===1;
            $st=$this->pdo->prepare("SELECT rule_id,domain_pattern FROM customer_allowed_domains WHERE customer_id=? AND status='active' ORDER BY rule_id FOR UPDATE");$st->execute([$customerId]);$rows=$st->fetchAll();
            $target=false;$remaining=[];foreach($rows as $row){if((string)$row['rule_id']===$ruleId)$target=true;else $remaining[]=$row;}
            if(!$target){if($started)$this->pdo->commit();return false;}
            if($required){
                if(!$remaining)throw new \RuntimeException('SURFACE_DOMAIN_LAST_REQUIRED');
                $host=strtolower(rtrim(trim($currentHost),'.'));if($host!==''){$ok=false;foreach($remaining as $row){if($this->domainMatches($host,(string)$row['domain_pattern'])){$ok=true;break;}}if(!$ok)throw new \RuntimeException('SURFACE_DOMAIN_CURRENT_REQUIRED');}
            }
            $this->pdo->prepare("UPDATE customer_allowed_domains SET status='removed',updated_at=? WHERE rule_id=? AND customer_id=? AND status='active'")->execute([time(),$ruleId,$customerId]);
            if($started)$this->pdo->commit();return true;
        }catch(\Throwable $e){if($started&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function removeNetworkRuleSafely(string $customerId,string $ruleId,string $currentIp=''): bool
    {
        $started=!$this->pdo->inTransaction();if($started)$this->pdo->beginTransaction();
        try{
            $st=$this->pdo->prepare('SELECT surface_ip_required FROM customers WHERE customer_id=? FOR UPDATE');$st->execute([$customerId]);$required=(int)($st->fetchColumn()?:0)===1;
            $st=$this->pdo->prepare("SELECT rule_id,cidr FROM customer_allowed_networks WHERE customer_id=? AND status='active' ORDER BY rule_id FOR UPDATE");$st->execute([$customerId]);$rows=$st->fetchAll();
            $target=false;$remaining=[];foreach($rows as $row){if((string)$row['rule_id']===$ruleId)$target=true;else $remaining[]=$row;}
            if(!$target){if($started)$this->pdo->commit();return false;}
            if($required){
                if(!$remaining)throw new \RuntimeException('SURFACE_IP_LAST_REQUIRED');
                $ip=trim($currentIp);if($ip!==''){$ok=false;foreach($remaining as $row){if($this->cidrContains((string)$row['cidr'],$ip)){$ok=true;break;}}if(!$ok)throw new \RuntimeException('SURFACE_IP_CURRENT_REQUIRED');}
            }
            $this->pdo->prepare("UPDATE customer_allowed_networks SET status='removed',updated_at=? WHERE rule_id=? AND customer_id=? AND status='active'")->execute([time(),$ruleId,$customerId]);
            if($started)$this->pdo->commit();return true;
        }catch(\Throwable $e){if($started&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function hostFromOrigin(string $origin,string $fallback): string
    {
        $host='';if($origin!==''){$parsed=parse_url($origin);if(is_array($parsed) && in_array(strtolower((string)($parsed['scheme']??'')),['http','https'],true))$host=strtolower((string)($parsed['host']??''));}
        if($host==='')$host=strtolower(trim($fallback));return rtrim($host,'.');
    }
    private function activeDomains(string $customerId): array{$st=$this->pdo->prepare("SELECT domain_pattern FROM customer_allowed_domains WHERE customer_id=? AND status='active' ORDER BY domain_pattern");$st->execute([$customerId]);return $st->fetchAll();}
    private function activeNetworks(string $customerId): array{$st=$this->pdo->prepare("SELECT cidr FROM customer_allowed_networks WHERE customer_id=? AND status='active' ORDER BY cidr");$st->execute([$customerId]);return $st->fetchAll();}
    private function domainMatches(string $host,string $pattern): bool
    {
        $host=strtolower(rtrim($host,'.'));$pattern=strtolower(rtrim($pattern,'.'));if(str_starts_with($pattern,'*.')){$base=substr($pattern,2);return $host!==$base && str_ends_with($host,'.'.$base);}return hash_equals($pattern,$host);
    }
    private function sourceIp(): string
    {
        $remote=(string)($_SERVER['REMOTE_ADDR']??'');$trusted=(array)($this->networkCfg['trusted_proxy_ips']??[]);
        if($remote!=='' && in_array($remote,$trusted,true)){
            $xff=(string)($_SERVER['HTTP_X_FORWARDED_FOR']??'');if($xff!==''){$first=trim(explode(',',$xff)[0]);if(filter_var($first,FILTER_VALIDATE_IP))return $first;}
        }
        return filter_var($remote,FILTER_VALIDATE_IP)?$remote:'0.0.0.0';
    }
    private function cidrContains(string $cidr,string $ip): bool
    {
        [$network,$prefix]=array_pad(explode('/',$cidr,2),2,null);$n=@inet_pton($network);$a=@inet_pton($ip);if($n===false||$a===false||strlen($n)!==strlen($a))return false;$bits=(int)($prefix??strlen($n)*8);$bytes=intdiv($bits,8);$rem=$bits%8;if($bytes>0 && substr($n,0,$bytes)!==substr($a,0,$bytes))return false;if($rem===0)return true;$mask=(0xFF << (8-$rem)) & 0xFF;return (ord($n[$bytes])&$mask)===(ord($a[$bytes])&$mask);
    }
}
