<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Security;

/**
 * HF86 Phase 11: Ed25519 server signer with staged key rotation.
 *
 * Backward compatible with the legacy single-key constructor. If a keyring file
 * exists, its active key is used for new signatures while staged/retiring keys
 * remain available for trust distribution and verification of historical DFT1
 * tokens during the transition.
 */
final class ServerSigner
{
    private string $secretKey;
    private string $publicKey;
    /** @var array<string,array<string,mixed>> */
    private array $keys=[];
    private array $ring=[];

    public function __construct(
        private string $keyId,
        string $secretFile,
        string $publicFile,
        ?string $keyringFile=null
    ) {
        if(!extension_loaded('sodium')) throw new \RuntimeException('PHP sodium extension is required.');

        // HF106: Fresh installations no longer require a manually generated
        // signing key. If neither legacy key file nor a keyring exists, the
        // license server provisions one Ed25519 key pair exactly once. The
        // private key never leaves secure storage; only the public key is
        // exposed through the admin/bootstrap surfaces.
        if($keyringFile===null || !is_file($keyringFile)){
            if(is_file($secretFile) xor is_file($publicFile)) throw new \RuntimeException('SERVER_KEY_MATERIAL_INCOMPLETE');
            if(!is_file($secretFile) && !is_file($publicFile)) self::bootstrapInitialKeyPair($secretFile,$publicFile);
        }

        if($keyringFile!==null && is_file($keyringFile)){
            $decoded=json_decode((string)file_get_contents($keyringFile),true);
            if(!is_array($decoded)||((int)($decoded['version']??0))!==1) throw new \RuntimeException('SERVER_KEYRING_INVALID');
            $active=trim((string)($decoded['active_key_id']??''));
            $rows=(array)($decoded['keys']??[]);
            if($active===''||!isset($rows[$active])||!is_array($rows[$active])) throw new \RuntimeException('SERVER_KEYRING_INVALID');
            $loaded=[];
            foreach($rows as $id=>$row){
                if(!is_array($row)) throw new \RuntimeException('SERVER_KEYRING_INVALID');
                $id=(string)$id;$status=(string)($row['status']??'');
                if(!in_array($status,['active','staged','retiring','revoked'],true)) throw new \RuntimeException('SERVER_KEYRING_INVALID');
                $pf=(string)($row['public_file']??'');$sf=(string)($row['private_file']??'');
                if($pf===''||!is_file($pf)) throw new \RuntimeException('Server public signing key missing: '.$id);
                // Only the active key must retain private material. Retiring keys use it for
                // alternate signatures while present; revoked keys may have it destroyed.
                $secretOptional=$status==='revoked'||$status==='staged'||$status==='retiring';
                $loaded[$id]=$this->loadKey($id,$sf,$pf,$status,$secretOptional);
                $loaded[$id]['meta']=$row;
            }
            if((string)($loaded[$active]['status']??'')!=='active'||empty($loaded[$active]['secret'])) throw new \RuntimeException('SERVER_KEYRING_ACTIVE_INVALID');
            $this->keys=$loaded;$this->ring=$decoded;$this->keyId=$active;
        }else{
            $legacy=$this->loadKey($this->keyId,$secretFile,$publicFile,'active');
            $this->keys[$this->keyId]=$legacy;
            $this->ring=[
                'version'=>1,
                'active_key_id'=>$this->keyId,
                'keys'=>[$this->keyId=>[
                    'key_id'=>$this->keyId,'public_file'=>$publicFile,'private_file'=>$secretFile,
                    'status'=>'active','created_at'=>null,'activated_at'=>null,'retired_at'=>null,'revoked_at'=>null,
                ]],
                'transition'=>null,
            ];
        }

        $activeRow=$this->keys[$this->keyId]??null;
        if(!is_array($activeRow)||empty($activeRow['secret'])||empty($activeRow['public'])) throw new \RuntimeException('Server signing key missing.');
        $this->secretKey=(string)$activeRow['secret'];$this->publicKey=(string)$activeRow['public'];
    }

    private static function bootstrapInitialKeyPair(string $secretFile,string $publicFile): void
    {
        if($secretFile===''||$publicFile==='') throw new \RuntimeException('SERVER_KEY_PATH_INVALID');
        $secretDir=dirname($secretFile);$publicDir=dirname($publicFile);
        foreach(array_unique([$secretDir,$publicDir]) as $dir){
            if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('SERVER_KEY_DIRECTORY_NOT_WRITABLE');
            if(!is_writable($dir)) throw new \RuntimeException('SERVER_KEY_DIRECTORY_NOT_WRITABLE');
        }
        $lockFile=$secretDir.DIRECTORY_SEPARATOR.'.server-key-bootstrap.lock';
        $lock=@fopen($lockFile,'c+');if($lock===false) throw new \RuntimeException('SERVER_KEY_BOOTSTRAP_LOCK_FAILED');
        try{
            if(!flock($lock,LOCK_EX)) throw new \RuntimeException('SERVER_KEY_BOOTSTRAP_LOCK_FAILED');
            if(is_file($secretFile) && is_file($publicFile)) return;
            if(is_file($secretFile) xor is_file($publicFile)) throw new \RuntimeException('SERVER_KEY_MATERIAL_INCOMPLETE');
            $pair=sodium_crypto_sign_keypair();
            $secret=base64_encode(sodium_crypto_sign_secretkey($pair)).PHP_EOL;
            $public=base64_encode(sodium_crypto_sign_publickey($pair)).PHP_EOL;
            if(file_put_contents($secretFile,$secret,LOCK_EX)===false) throw new \RuntimeException('SERVER_KEY_WRITE_FAILED');
            if(file_put_contents($publicFile,$public,LOCK_EX)===false){@unlink($secretFile);throw new \RuntimeException('SERVER_KEY_WRITE_FAILED');}
            @chmod($secretFile,0600);@chmod($publicFile,0644);
        }finally{
            @flock($lock,LOCK_UN);@fclose($lock);
        }
    }

    private function loadKey(string $id,string $secretFile,string $publicFile,string $status,bool $secretOptional=false): array
    {
        if(!is_file($publicFile)) throw new \RuntimeException('Server signing key missing.');
        $public=KeyCodec::decode((string)file_get_contents($publicFile));
        if(strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new \RuntimeException('Invalid server public key.');
        $secret='';
        if($secretFile!==''&&is_file($secretFile)){
            $secret=KeyCodec::decode((string)file_get_contents($secretFile));
            if(strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new \RuntimeException('Invalid server private key.');
        }elseif(!$secretOptional){
            throw new \RuntimeException('Server signing key missing.');
        }
        return ['key_id'=>$id,'secret'=>$secret,'public'=>$public,'status'=>$status];
    }

    public function keyId(): string { return $this->keyId; }
    public function publicKeyEncoded(): string { return KeyCodec::encode($this->publicKey); }
    public function sign(string $bytes): string { return base64_encode(sodium_crypto_sign_detached($bytes,$this->secretKey)); }

    /** Legacy verification against the currently active key. */
    public function verify(string $bytes,string $signatureBase64): bool
    {
        return $this->verifyByKeyId($bytes,$signatureBase64,$this->keyId);
    }

    /** Accept active or retiring keys; staged keys have never signed production artifacts. */
    public function verifyByKeyId(string $bytes,string $signatureBase64,string $keyId): bool
    {
        $row=$this->keys[$keyId]??null;
        if(!is_array($row)||!in_array((string)$row['status'],['active','retiring'],true)) return false;
        $sig=base64_decode($signatureBase64,true);
        return $sig!==false && strlen($sig)===SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($sig,$bytes,(string)$row['public']);
    }

    /** @return array<int,array{key_id:string,signature:string}> */
    public function alternateSignatures(string $bytes): array
    {
        $out=[];
        foreach($this->keys as $id=>$row){
            if($id===$this->keyId||(string)($row['status']??'')!=='retiring'||empty($row['secret'])) continue;
            $out[]=['key_id'=>$id,'signature'=>base64_encode(sodium_crypto_sign_detached($bytes,(string)$row['secret']))];
        }
        return $out;
    }

    public function trustBundle(): array
    {
        $keys=[];
        foreach($this->keys as $id=>$row){
            $meta=is_array($row['meta']??null)?$row['meta']:[];
            $keys[]=[
                'key_id'=>$id,
                'algorithm'=>'Ed25519',
                'public_key'=>KeyCodec::encode((string)$row['public']),
                'status'=>(string)$row['status'],
                'created_at'=>$meta['created_at']??null,
                'activated_at'=>$meta['activated_at']??null,
                'retired_at'=>$meta['retired_at']??null,
                'revoked_at'=>$meta['revoked_at']??null,
            ];
        }
        usort($keys,static fn(array $a,array $b):int=>strcmp((string)$a['key_id'],(string)$b['key_id']));
        return [
            'schema'=>'easyit.license.server-trust.v1',
            'active_key_id'=>$this->keyId,
            'keys'=>$keys,
            'transition'=>is_array($this->ring['transition']??null)?$this->ring['transition']:null,
            'issued_at'=>time(),
        ];
    }

    public function signArray(array $value): string
    {
        return $this->sign(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
}
