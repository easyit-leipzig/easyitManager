<?php
declare(strict_types=1);
namespace DataForm5\Observability\Stores;
use DataForm5\Observability\Exceptions\ObservabilityException;
final class JsonFileMetricStore extends InMemoryMetricStore
{
    public function __construct(private readonly string $file){$this->load();}
    private function load():void{if(!is_file($this->file))return;$data=json_decode((string)file_get_contents($this->file),true);if(is_array($data['metrics']??null)){foreach($data['metrics'] as $metric){$id=\DataForm5\Observability\Core\MetricKey::id((string)$metric['name'],(array)($metric['tags']??[]));$this->metrics[$id]=$metric;}}}
    protected function mutate(string $type,string $name,float $value,array $tags,bool $replace=false):void{parent::mutate($type,$name,$value,$tags,$replace);$this->persist();}
    public function clear():void{parent::clear();$this->persist();}
    private function persist():void{$dir=dirname($this->file);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new ObservabilityException("Metrikverzeichnis konnte nicht erstellt werden: {$dir}");$tmp=$this->file.'.tmp.'.bin2hex(random_bytes(4));$json=json_encode($this->snapshot(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new ObservabilityException('Metrikdatei konnte nicht geschrieben werden.');if(!rename($tmp,$this->file)){@unlink($tmp);throw new ObservabilityException('Metrikdatei konnte nicht atomar aktiviert werden.');}}
    public function healthy():bool{$dir=is_dir(dirname($this->file))?dirname($this->file):dirname(dirname($this->file));return is_dir($dir)&&is_writable($dir);}
}
