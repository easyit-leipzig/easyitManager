<?php
declare(strict_types=1);
namespace DataForm5\Context\Core;
use DataForm5\Context\Contracts\ContextResolverInterface;
final class ArrayContextResolver implements ContextResolverInterface
{
    public function __construct(private readonly array $defaults = []) {}
    public function resolve(array $input = []): ?ProjectContext
    {
        $data = array_replace($this->defaults, $input);
        $tenant = trim((string)($data['tenant_id'] ?? ''));
        $project = trim((string)($data['project_id'] ?? ''));
        if ($tenant === '' || $project === '') return null;
        return new ProjectContext($tenant, $project, (string)($data['project_name'] ?? ''), (array)($data['metadata'] ?? []));
    }
}
