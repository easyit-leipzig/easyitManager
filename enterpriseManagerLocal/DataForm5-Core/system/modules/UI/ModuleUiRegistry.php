<?php
declare(strict_types=1);
namespace DataForm5\Modules\UI;
use DataForm5\Modules\Core\ModuleManager;
final class ModuleUiRegistry
{
    private array $cache=[];
    public function __construct(private readonly ModuleManager $manager) {}
    /** @return list<array<string,mixed>> */
    public function navigation(array $user): array { return $this->collect('navigation',$user); }
    /** @return list<array<string,mixed>> */
    public function dashboard(array $user): array { return $this->collect('dashboard',$user); }
    /** @return list<array<string,mixed>> */
    private function collect(string $section,array $user): array
    {
        $permissions=(array)($user['permissions']??[]);
        sort($permissions,SORT_STRING);
        $cacheKey=$section.':'.hash('sha256',json_encode([$user['roles']??[],$permissions],JSON_UNESCAPED_SLASHES));
        if(isset($this->cache[$cacheKey]))return $this->cache[$cacheKey];
        $rows=[];
        foreach($this->manager->discovered() as $name=>$item){
            $manifest=$item['manifest']; if(!$manifest->enabled) continue;
            foreach((array)($manifest->ui[$section]??[]) as $entry){
                if(!is_array($entry)) continue;
                $cap=(string)($entry['capability']??'');
                if($cap!=='' && !(function_exists('enterprise_can') && enterprise_can($user,$cap))) continue;
                $href=(string)($entry['href']??''); $label=(string)($entry['label']??'');
                if($href===''||$label==='') continue;
                $entry['module']=$name; $entry['priority']=(int)($entry['priority']??100); $rows[]=$entry;
            }
        }
        usort($rows,static fn(array $a,array $b):int=>[$a['priority'],$a['label']]<=>[$b['priority'],$b['label']]);
        return $this->cache[$cacheKey]=$rows;
    }
}
