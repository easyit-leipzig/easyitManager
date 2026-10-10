<?php
declare(strict_types=1);
namespace DataForm5\Secrets\Contracts;
interface EncrypterInterface
{
    public function encrypt(string $plaintext): string;
    public function decrypt(string $payload): string;
    public function keyId(): string;
}
