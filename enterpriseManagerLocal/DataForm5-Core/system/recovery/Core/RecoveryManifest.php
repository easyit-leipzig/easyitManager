<?php
declare(strict_types=1);
namespace DataForm5\Recovery\Core;
use DataForm5\Recovery\Exceptions\RecoveryException;
final class RecoveryManifest
{
    public function __construct(public readonly array $data) {}
    public static function load(string $file): self
    {
        $raw=@file_get_contents($file); $data=$raw===false?null:json_decode($raw,true);
        if(!is_array($data)) throw new RecoveryException('Ungültiges Recovery-Manifest: '.$file);
        return new self($data);
    }
    public function write(string $file): void
    {
        $json=json_encode($this->data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(file_put_contents($file,$json."\n",LOCK_EX)===false) throw new RecoveryException('Manifest konnte nicht geschrieben werden.');
    }
}
