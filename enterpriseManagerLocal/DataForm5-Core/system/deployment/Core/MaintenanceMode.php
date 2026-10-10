<?php
declare(strict_types=1);
namespace DataForm5\Deployment\Core;
final class MaintenanceMode
{
    public function __construct(private string $file){}
    public function enable(string $message='Wartungsarbeiten',array $allowedIps=[]):void{if(!is_dir(dirname($this->file)))mkdir(dirname($this->file),0775,true);$data=['enabled'=>true,'message'=>$message,'since'=>gmdate(DATE_ATOM),'allowed_ips'=>array_values($allowedIps)];file_put_contents($this->file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL,LOCK_EX);}
    public function disable():void{if(is_file($this->file))unlink($this->file);}
    public function enabled():bool{return is_file($this->file);}
    public function data():?array{if(!$this->enabled())return null;$v=json_decode((string)file_get_contents($this->file),true);return is_array($v)?$v:null;}
}
