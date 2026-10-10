<?php
declare(strict_types=1);
namespace DataForm5\Modules\Config;
use PDO;
final class ModuleConfigRepository
{
    public function __construct(private PDO $pdo, private string $keyMaterial){}
    public function values(string $module,string $scopeType='enterprise',string $scopeId='global'): array
    {
        $stmt=$this->pdo->prepare('SELECT config_key,config_value_json FROM enterprise_module_settings WHERE module_name=? AND scope_type=? AND scope_id=?');
        $stmt->execute([$module,$scopeType,$scopeId]);$out=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['config_key']]=json_decode((string)$r['config_value_json'],true);return $out;
    }
    public function secrets(string $module,string $scopeType='enterprise',string $scopeId='global'): array
    {
        $stmt=$this->pdo->prepare('SELECT config_key,ciphertext,nonce,tag FROM enterprise_module_secrets WHERE module_name=? AND scope_type=? AND scope_id=?');
        $stmt->execute([$module,$scopeType,$scopeId]);$out=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){$v=$this->decrypt($r['ciphertext'],$r['nonce'],$r['tag']);if($v!==null)$out[$r['config_key']]=$v;}return $out;
    }
    public function save(string $module,string $scopeType,string $scopeId,array $values,array $secretKeys): void
    {
        $normal=$this->pdo->prepare('INSERT INTO enterprise_module_settings(module_name,scope_type,scope_id,config_key,config_value_json) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE config_value_json=VALUES(config_value_json),updated_at=CURRENT_TIMESTAMP');
        $secret=$this->pdo->prepare('INSERT INTO enterprise_module_secrets(module_name,scope_type,scope_id,config_key,ciphertext,nonce,tag) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),nonce=VALUES(nonce),tag=VALUES(tag),updated_at=CURRENT_TIMESTAMP');
        foreach($values as $key=>$value){if(in_array($key,$secretKeys,true)){if($value===''||$value===null)continue;[$c,$n,$t]=$this->encrypt((string)$value);$secret->execute([$module,$scopeType,$scopeId,$key,$c,$n,$t]);}else{$normal->execute([$module,$scopeType,$scopeId,$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}}
    }
    private function key(): string {if($this->keyMaterial==='')throw new \RuntimeException('APP_KEY/DATAFORM_APP_KEY fehlt; Secrets können nicht gespeichert werden.');return hash('sha256',$this->keyMaterial,true);}
    private function encrypt(string $plain): array {$nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$nonce,$tag);if($cipher===false)throw new \RuntimeException('Secret-Verschlüsselung fehlgeschlagen.');return [base64_encode($cipher),base64_encode($nonce),base64_encode($tag)];}
    private function decrypt(string $cipher,string $nonce,string $tag): ?string {$plain=openssl_decrypt(base64_decode($cipher,true)?:'','aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,base64_decode($nonce,true)?:'',base64_decode($tag,true)?:'');return $plain===false?null:$plain;}
}
