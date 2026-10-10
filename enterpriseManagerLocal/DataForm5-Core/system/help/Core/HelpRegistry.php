<?php
declare(strict_types=1);
namespace DataForm5\Help\Core;
use DataForm5\Help\Contracts\HelpProviderInterface;
final class HelpRegistry
{
    /** @var array<string,HelpTopic> */ private array $topics=[];
    public function add(HelpTopic $topic):void{$this->topics[$topic->id]=$topic;}
    public function addProvider(HelpProviderInterface $provider):void{foreach($provider->topics() as $topic)$this->add($topic instanceof HelpTopic?$topic:HelpTopic::fromArray($topic));}
    public function get(string $id):?HelpTopic{return $this->topics[$id]??null;}
    /** @return list<HelpTopic> */ public function all():array{return array_values($this->topics);}
}
