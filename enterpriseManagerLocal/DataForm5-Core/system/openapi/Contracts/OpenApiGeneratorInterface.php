<?php
declare(strict_types=1);
namespace DataForm5\OpenApi\Contracts;
interface OpenApiGeneratorInterface
{
    public function info(string $title,string $version,string $description=''): self;
    public function server(string $url,string $description=''): self;
    public function schema(string $name,array $schema): self;
    public function operation(string $method,string $path,array $operation): self;
    public function generate(): array;
    public function toJson(int $flags=JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE): string;
}
