<?php
declare(strict_types=1);
namespace DataForm5\Help\Core;
final class HelpResolver
{
    public function __construct(private HelpRegistry $registry){}
    public function resolve(string $context):?HelpTopic
    {
        $context='/'.trim($context,'/');$best=null;$score=-1;
        foreach($this->registry->all() as $topic)foreach($topic->contexts as $pattern){$pattern='/'.trim($pattern,'/');$matches=$pattern===$context||($pattern!=='/'&&str_ends_with($pattern,'*')&&str_starts_with($context,rtrim($pattern,'*')));if($matches&&strlen($pattern)>$score){$best=$topic;$score=strlen($pattern);}}
        return $best;
    }
}
