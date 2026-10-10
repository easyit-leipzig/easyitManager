<?php
declare(strict_types=1);
namespace DataForm5\Packages\Core;
use DataForm5\Packages\Exceptions\PackageException;
final class PackageManifest
{
    /** @param array<string,string> $dependencies @param array<string,string> $checksums */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $type = 'module',
        public readonly string $description = '',
        public readonly array $dependencies = [],
        public readonly array $checksums = [],
        public readonly ?string $lifecycle = null,
    ) {}
    public static function fromFile(string $file): self
    {
        if (!is_file($file)) throw new PackageException("Paketmanifest fehlt: {$file}");
        $data=json_decode((string)file_get_contents($file),true);
        if(!is_array($data)) throw new PackageException("Ungültiges Paketmanifest: {$file}");
        $name=trim((string)($data['name']??'')); $version=trim((string)($data['version']??''));
        if($name===''||!preg_match('/^[a-z0-9][a-z0-9._-]*$/i',$name)) throw new PackageException('Paketname fehlt oder ist ungültig.');
        if($version===''||!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',$version)) throw new PackageException('Paketversion muss semantisch sein, z. B. 1.0.0.');
        return new self($name,$version,(string)($data['type']??'module'),(string)($data['description']??''),is_array($data['dependencies']??null)?$data['dependencies']:[],is_array($data['checksums']??null)?$data['checksums']:[],isset($data['lifecycle'])?(string)$data['lifecycle']:null);
    }
    /** @return array<string,mixed> */ public function toArray():array{return ['name'=>$this->name,'version'=>$this->version,'type'=>$this->type,'description'=>$this->description,'dependencies'=>$this->dependencies,'checksums'=>$this->checksums,'lifecycle'=>$this->lifecycle];}
}
