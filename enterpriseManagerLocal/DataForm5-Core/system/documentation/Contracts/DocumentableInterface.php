<?php
declare(strict_types=1);
namespace DataForm5\Documentation\Contracts;
interface DocumentableInterface
{
    /** @return array<string,mixed> */
    public function documentationMetadata(): array;
}
