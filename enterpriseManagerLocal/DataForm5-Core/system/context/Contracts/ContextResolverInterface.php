<?php
declare(strict_types=1);
namespace DataForm5\Context\Contracts;
use DataForm5\Context\Core\ProjectContext;
interface ContextResolverInterface
{
    public function resolve(array $input = []): ?ProjectContext;
}
