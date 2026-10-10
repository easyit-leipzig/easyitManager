<?php
declare(strict_types=1);
namespace DataForm5\Cache\Core;
use DataForm5\Cache\Contracts\CacheInterface;
use DataForm5\Cache\Exceptions\CacheException;
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $directory,private readonly string $namespace='default') { $this->ensureDirectory(); }
    public function get(string $key,mixed $default=null):mixed { $item=$this->read($key); return $item===null?$default:$item['value']; }
    public function set(string $key,mixed $value,?int $ttl=null):bool {
        $this->assertKey($key); $this->ensureDirectory();
        $payload=serialize(['key'=>$key,'expires'=>$ttl===null?null:time()+max(0,$ttl),'value'=>$value]);
        $file=$this->file($key); $tmp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($tmp,$payload,LOCK_EX)===false){@unlink($tmp);throw new CacheException('Cache-Datei konnte nicht geschrieben werden.');}
        if(!@rename($tmp,$file)){@unlink($tmp);throw new CacheException('Cache-Datei konnte nicht atomar ersetzt werden.');}
        return true;
    }
    public function has(string $key):bool { return $this->read($key)!==null; }
    public function delete(string $key):bool { $this->assertKey($key); $file=$this->file($key); return !is_file($file)||@unlink($file); }
    public function clear():bool { $ok=true; foreach(glob($this->directory.'/'.$this->prefix().'*.cache')?:[] as $file){$ok=@unlink($file)&&$ok;} return $ok; }
    public function remember(string $key,?int $ttl,callable $resolver):mixed { $item=$this->read($key); if($item!==null)return $item['value']; $value=$resolver(); $this->set($key,$value,$ttl); return $value; }
    public function prune():int { $count=0; foreach(glob($this->directory.'/'.$this->prefix().'*.cache')?:[] as $file){$raw=@file_get_contents($file);$data=$raw===false?false:@unserialize($raw,['allowed_classes'=>true]);if(!is_array($data)||(($data['expires']??null)!==null&&$data['expires']<=time())){if(@unlink($file))$count++;}} return $count; }
    /** @return array{value:mixed,expires:?int}|null */
    private function read(string $key):?array { $this->assertKey($key); $file=$this->file($key); if(!is_file($file))return null; $raw=@file_get_contents($file); $data=$raw===false?false:@unserialize($raw,['allowed_classes'=>true]); if(!is_array($data)||($data['key']??null)!==$key){@unlink($file);return null;} $expires=$data['expires']??null; if($expires!==null&&$expires<=time()){@unlink($file);return null;} return ['value'=>$data['value']??null,'expires'=>$expires]; }
    private function file(string $key):string { return $this->directory.'/'.$this->prefix().hash('sha256',$key).'.cache'; }
    private function prefix():string { return hash('sha256',$this->namespace).'-'; }
    private function ensureDirectory():void { if(!is_dir($this->directory)&&!@mkdir($this->directory,0775,true)&&!is_dir($this->directory))throw new CacheException('Cache-Verzeichnis konnte nicht angelegt werden: '.$this->directory); }
    private function assertKey(string $key):void { if($key==='')throw new \InvalidArgumentException('Cache-Schlüssel darf nicht leer sein.'); }
}
