<?php
declare(strict_types=1);
namespace DataForm5\Secrets\Core;
use DataForm5\Secrets\Contracts\EncrypterInterface;
use DataForm5\Secrets\Exceptions\SecretException;
final class Encrypter implements EncrypterInterface
{
    public function __construct(private readonly KeyRing $keys){}
    public function keyId():string{return $this->keys->activeKeyId();}
    public function encrypt(string $plaintext):string
    {
        $id=$this->keys->activeKeyId();$key=$this->keys->activeKey();
        if(function_exists('sodium_crypto_secretbox')){
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher=sodium_crypto_secretbox($plaintext,$nonce,$key);
            return $this->encode(['v'=>1,'alg'=>'secretbox','kid'=>$id,'nonce'=>base64_encode($nonce),'data'=>base64_encode($cipher)]);
        }
        if(!function_exists('openssl_encrypt')) throw new SecretException('Weder Sodium noch OpenSSL ist verfügbar.');
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'DataForm5');
        if($cipher===false) throw new SecretException('Verschlüsselung fehlgeschlagen.');
        return $this->encode(['v'=>1,'alg'=>'aes-256-gcm','kid'=>$id,'nonce'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)]);
    }
    public function decrypt(string $payload):string
    {
        $p=$this->decode($payload);$key=$this->keys->key((string)($p['kid']??''));$alg=(string)($p['alg']??'');
        $nonce=base64_decode((string)($p['nonce']??''),true);$data=base64_decode((string)($p['data']??''),true);
        if($nonce===false||$data===false) throw new SecretException('Ungültige verschlüsselte Nutzlast.');
        if($alg==='secretbox'){$plain=sodium_crypto_secretbox_open($data,$nonce,$key);if($plain===false)throw new SecretException('Entschlüsselung oder Integritätsprüfung fehlgeschlagen.');return $plain;}
        if($alg==='aes-256-gcm'){$tag=base64_decode((string)($p['tag']??''),true);if($tag===false)throw new SecretException('Ungültiges Authentifizierungs-Tag.');$plain=openssl_decrypt($data,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'DataForm5');if($plain===false)throw new SecretException('Entschlüsselung oder Integritätsprüfung fehlgeschlagen.');return $plain;}
        throw new SecretException("Unbekannter Verschlüsselungsalgorithmus '{$alg}'.");
    }
    /** @param array<string,mixed> $value */ private function encode(array $value):string{return 'df5enc:'.base64_encode((string)json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    /** @return array<string,mixed> */ private function decode(string $payload):array{if(!str_starts_with($payload,'df5enc:'))throw new SecretException('Ungültiges Secret-Format.');$json=base64_decode(substr($payload,7),true);if($json===false)throw new SecretException('Ungültige Secret-Kodierung.');$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new SecretException('Ungültige Secret-Nutzlast.');return $data;}
}
