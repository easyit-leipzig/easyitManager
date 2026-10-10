<?php
declare(strict_types=1);
namespace DataForm5\Audit\Stores;
use DataForm5\Audit\Contracts\AuditStoreInterface;
use DataForm5\Audit\Core\{AuditEntry,AuditQuery};
use DataForm5\Audit\Exceptions\AuditException;
final class FileAuditStore implements AuditStoreInterface
{
    public function __construct(private readonly string $file,private readonly string $key=''){}
    public function append(AuditEntry $entry):AuditEntry
    {
        $dir=dirname($this->file);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new AuditException("Audit-Verzeichnis '{$dir}' konnte nicht erstellt werden.");
        $handle=fopen($this->file,'c+b');if($handle===false)throw new AuditException("Audit-Datei '{$this->file}' konnte nicht geöffnet werden.");
        try{
            if(!flock($handle,LOCK_EX))throw new AuditException('Audit-Datei konnte nicht gesperrt werden.');
            $previous=$this->lastHash($handle);$payload=$entry->payload()+['previous_hash'=>$previous];
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $hash=hash_hmac('sha256',$json,$this->key);
            $stored=$entry->withIntegrity($previous,$hash);
            fseek($handle,0,SEEK_END);fwrite($handle,json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);fflush($handle);flock($handle,LOCK_UN);
            return $stored;
        }finally{fclose($handle);}
    }
    public function search(?AuditQuery $query=null):array
    {
        if(!is_file($this->file))return [];$result=[];$handle=fopen($this->file,'rb');if($handle===false)return [];
        while(($line=fgets($handle))!==false){$line=trim($line);if($line==='')continue;$data=json_decode($line,true,512,JSON_THROW_ON_ERROR);$entry=AuditEntry::fromArray($data);if($query===null||$query->matches($entry))$result[]=$entry;}
        fclose($handle);if($query?->limit!==null)$result=array_slice($result,-$query->limit);return $result;
    }
    public function verify():bool
    {
        $previous='';foreach($this->search() as $entry){if($entry->previousHash!==$previous)return false;$payload=$entry->payload()+['previous_hash'=>$previous];$json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(!hash_equals(hash_hmac('sha256',$json,$this->key),$entry->hash))return false;$previous=$entry->hash;}return true;
    }
    /** @param resource $handle */
    private function lastHash($handle):string
    {
        rewind($handle);$last='';while(($line=fgets($handle))!==false){if(trim($line)!=='')$last=$line;}if($last==='')return '';$data=json_decode(trim($last),true);return is_array($data)?(string)($data['hash']??''):'';
    }
}
