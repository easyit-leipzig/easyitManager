<?php
declare(strict_types=1);
namespace DataForm5\Features\Core;
final class FeatureDefinition
{
    public function __construct(public readonly string $name, private readonly array $config) {}
    public function value(mixed $default=null): mixed { return $this->config['value'] ?? $default; }
    public function isEnabled(array $context=[]): bool
    {
        if (!(bool)($this->config['enabled'] ?? false)) return false;
        $environment=(string)($context['environment'] ?? 'production');
        $environments=(array)($this->config['environments'] ?? []);
        if ($environments!==[] && !in_array($environment,$environments,true)) return false;
        $groups=(array)($this->config['groups'] ?? []);
        if ($groups!==[]) {
            $subjectGroups=(array)($context['groups'] ?? []);
            if (array_intersect($groups,$subjectGroups)===[]) return false;
        }
        $users=array_map('strval',(array)($this->config['users'] ?? []));
        if ($users!==[] && !in_array((string)($context['user_id'] ?? ''),$users,true)) return false;
        $percentage=max(0,min(100,(int)($this->config['percentage'] ?? 100)));
        if ($percentage>=100) return true;
        if ($percentage<=0) return false;
        $subject=(string)($context['subject'] ?? $context['user_id'] ?? $context['session_id'] ?? 'anonymous');
        $bucket=(int)(hexdec(substr(hash('sha256',$this->name.'|'.$subject),0,8)) % 100);
        return $bucket < $percentage;
    }
    public function toArray(): array { return ['name'=>$this->name]+$this->config; }
}
