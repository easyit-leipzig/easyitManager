<?php
declare(strict_types=1);
namespace DataForm5\Modules\Config;
final class ModuleConfigManager
{
    public function __construct(private ModuleConfigRepository $repository){}
    public function get(string $module,string $modulePath,string $scopeType='enterprise',string $scopeId='global'): array
    {
        $schema=ModuleConfigSchema::fromModule($modulePath);return array_replace($schema->defaults(),$this->repository->values($module,$scopeType,$scopeId),$this->repository->secrets($module,$scopeType,$scopeId));
    }
    public function save(string $module,string $modulePath,array $values,string $scopeType='enterprise',string $scopeId='global'): array
    {
        if(!in_array($scopeType,['enterprise','product','project'],true))throw new \InvalidArgumentException('Ungültiger Konfigurations-Scope.');
        $schema=ModuleConfigSchema::fromModule($modulePath);$result=$schema->validate($values);if(!$result['valid'])return $result;$this->repository->save($module,$scopeType,$scopeId,$result['values'],$schema->secrets());return $result;
    }
}
