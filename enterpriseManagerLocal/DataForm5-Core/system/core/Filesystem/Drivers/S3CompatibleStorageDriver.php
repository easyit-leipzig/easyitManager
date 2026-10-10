<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem\Drivers;

use DataForm5\Core\Filesystem\Contracts\StorageDriverInterface;

final class S3CompatibleStorageDriver implements StorageDriverInterface
{
    public function __construct(
        private readonly string $driverName,
        private readonly array $config
    ) {}

    public function name(): string { return $this->driverName; }
    public function root(): string { return (string)($this->config['bucket']??''); }

    private function unavailable(): never
    {
        throw new \RuntimeException(
            'S3-kompatibler Storage ist konfiguriert, aber in Phase X noch kein HTTP-S3-Client gebunden. ' .
            'Nutzen Sie local/shared oder installieren Sie später einen S3-Transportprovider.'
        );
    }

    public function exists(string $path): bool { $this->unavailable(); }
    public function read(string $path): string { $this->unavailable(); }
    public function write(string $path,string $content,bool $atomic=true): int { $this->unavailable(); }
    public function append(string $path,string $content): int { $this->unavailable(); }
    public function delete(string $path): void { $this->unavailable(); }
    public function copy(string $source,string $destination): void { $this->unavailable(); }
    public function move(string $source,string $destination): void { $this->unavailable(); }
    public function files(string $directory='',bool $recursive=false): array { $this->unavailable(); }

    public function health(): array
    {
        return [
            'driver'=>'s3-compatible',
            'name'=>$this->driverName,
            'bucket'=>(string)($this->config['bucket']??''),
            'endpoint'=>(string)($this->config['endpoint']??''),
            'status'=>'unavailable',
            'reason'=>'transport_provider_missing',
        ];
    }
}
