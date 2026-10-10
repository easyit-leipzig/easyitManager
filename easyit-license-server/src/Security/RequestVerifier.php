<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Security;

use PDO;

final class RequestVerifier
{
    public function __construct(private PDO $pdo, private int $clockSkewSeconds=300) {}

    public function verify(string $method,string $path,array $headers,string $body): array
    {
        $installation=$headers['x-easyit-installation']??'';
        $requestId=$headers['x-easyit-request-id']??'';
        $timestamp=(int)($headers['x-easyit-timestamp']??0);
        $nonce=$headers['x-easyit-nonce']??'';
        $keyId=$headers['x-easyit-key-id']??'';
        $sig64=$headers['x-easyit-signature']??'';
        if($installation===''||$requestId===''||$timestamp===0||$nonce===''||$keyId===''||$sig64==='') throw new \RuntimeException('REQUEST_INVALID');
        if(abs(time()-$timestamp)>$this->clockSkewSeconds) throw new \RuntimeException('REQUEST_EXPIRED');
        $st=$this->pdo->prepare("SELECT i.*,k.public_key,k.status key_status FROM installations i JOIN installation_keys k ON k.installation_id=i.installation_id WHERE i.installation_id=? AND k.key_id=? LIMIT 1");
        $st->execute([$installation,$keyId]); $row=$st->fetch();
        if(!$row) throw new \RuntimeException('INSTALLATION_UNKNOWN');
        if(($row['key_status']??'')!=='active') throw new \RuntimeException('INSTALLATION_BLOCKED');
        $public=KeyCodec::decode((string)$row['public_key']);
        $sig=base64_decode($sig64,true); if($sig===false) throw new \RuntimeException('REQUEST_SIGNATURE_INVALID');
        $canonical=strtoupper($method)."\n".$path."\n".$requestId."\n".$installation."\n".$timestamp."\n".$nonce."\n".hash('sha256',$body);
        if(!sodium_crypto_sign_verify_detached($sig,$canonical,$public)) throw new \RuntimeException('REQUEST_SIGNATURE_INVALID');
        // A temporarily blocked installation may still acknowledge a staged server key
        // after authenticating with its installation key. The business request remains denied.
        $this->recordTrustedServerKeys($installation,(string)($headers['x-easyit-trusted-server-keys']??''));
        if(($row['status']??'')!=='active') throw new \RuntimeException('INSTALLATION_BLOCKED');
        $this->purgeNonces();
        try {
            $ins=$this->pdo->prepare('INSERT INTO used_nonces (installation_id,nonce,request_id,expires_at,created_at) VALUES (?,?,?,?,?)');
            $ins->execute([$installation,$nonce,$requestId,time()+$this->clockSkewSeconds+60,time()]);
        } catch (\Throwable) { throw new \RuntimeException('NONCE_REUSED'); }
        return $row;
    }

    private function recordTrustedServerKeys(string $installation,string $csv): void
    {
        $ids=array_values(array_unique(array_filter(array_map('trim',explode(',',$csv)),static fn(string $v):bool=>$v!=='' && preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$v)===1)));
        if(!$ids) return;
        $now=time();
        $sel=$this->pdo->prepare('SELECT status FROM installation_server_key_trust WHERE installation_id=? AND key_id=?');
        $ins=$this->pdo->prepare('INSERT INTO installation_server_key_trust (installation_id,key_id,status,first_seen_at,last_seen_at) VALUES (?,?,?,?,?)');
        $up=$this->pdo->prepare("UPDATE installation_server_key_trust SET status='trusted',last_seen_at=? WHERE installation_id=? AND key_id=?");
        foreach($ids as $id){
            $sel->execute([$installation,$id]);
            if($sel->fetch()) $up->execute([$now,$installation,$id]);
            else $ins->execute([$installation,$id,'trusted',$now,$now]);
        }
    }

    private function purgeNonces(): void
    {
        try{$st=$this->pdo->prepare('DELETE FROM used_nonces WHERE expires_at < ?');$st->execute([time()]);}catch(\Throwable){}
    }
}
