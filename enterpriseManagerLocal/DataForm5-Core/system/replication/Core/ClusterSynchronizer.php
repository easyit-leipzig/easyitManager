<?php
declare(strict_types=1);

namespace DataForm5\Replication\Core;

use DataForm5\Events\Contracts\EventDispatcherInterface;
use DataForm5\Events\Core\NamedEvent;
use DataForm5\Replication\Contracts\ReplicationTransportInterface;
use DataForm5\Cluster\Security\{NodeSigner,NodeAuthenticator};

final class ClusterSynchronizer
{
    /** @var array<string,callable> */
    private array $handlers=[];

    public function __construct(
        private readonly ReplicationTransportInterface $transport,
        private readonly ReplicationStateStore $state,
        private readonly EventDispatcherInterface $events,
        private readonly string $nodeId,
        private readonly string $channel,
        private readonly ?NodeSigner $signer=null,
        private readonly ?NodeAuthenticator $authenticator=null,
        private readonly bool $securityEnabled=false
    ) {}

    public function on(string $type,callable $handler): self
    {
        $this->handlers[$type]=$handler;
        return $this;
    }

    public function publish(string $type,array $payload): ReplicationEvent
    {
        $event=ReplicationEvent::create($this->channel,$type,$this->nodeId,$payload);
        if($this->securityEnabled){
            if($this->signer===null) throw new \RuntimeException('Replication security is enabled, but no signer is available.');
            $event=$event->withSecurity($this->signer->sign($event->signingPayload()));
        }
        $this->transport->publish($event);
        $this->events->dispatch(new NamedEvent('replication.published',[
            'id'=>$event->id,'type'=>$event->type,'channel'=>$event->channel,'source_node'=>$event->sourceNode
        ]),'replication.published');
        return $event;
    }

    public function consume(int $limit=100): array
    {
        $after=$this->state->get($this->channel,'stream');
        $events=$this->transport->consume($this->channel,$after,$limit);
        $results=[];
        foreach($events as $event){
            if($event->sourceNode===$this->nodeId){
                $this->state->set($this->channel,'stream',$event->id);
                continue;
            }
            $status='ignored';
            try{
                if($this->securityEnabled){
                    if($this->authenticator===null) throw new \RuntimeException('Replication authenticator is unavailable.');
                    $auth=$this->authenticator->verify($event->signingPayload(),$event->security,true);
                    if(!(bool)($auth['valid']??false)){
                        throw new \RuntimeException('Replication signature rejected: '.(string)($auth['reason']??'unknown'));
                    }
                }
                if(isset($this->handlers[$event->type])){
                    ($this->handlers[$event->type])($event);
                    $status='applied';
                }
                $this->state->set($this->channel,'stream',$event->id);
                $this->events->dispatch(new NamedEvent('replication.consumed',[
                    'id'=>$event->id,'type'=>$event->type,'status'=>$status,'source_node'=>$event->sourceNode
                ]),'replication.consumed');
            }catch(\Throwable $e){
                $status='failed';
                $results[]=['id'=>$event->id,'type'=>$event->type,'status'=>$status,'error'=>$e->getMessage()];
                break;
            }
            $results[]=['id'=>$event->id,'type'=>$event->type,'status'=>$status];
        }
        return $results;
    }

    public function health(): array
    {
        return [
            'node_id'=>$this->nodeId,
            'channel'=>$this->channel,
            'transport'=>$this->transport->health(),
            'state'=>$this->state->all(),
            'handlers'=>array_keys($this->handlers),
            'security_enabled'=>$this->securityEnabled,
        ];
    }
}
