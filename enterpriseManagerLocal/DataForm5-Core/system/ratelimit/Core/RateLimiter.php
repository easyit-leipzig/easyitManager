<?php
declare(strict_types=1);
namespace DataForm5\RateLimit\Core;
use DataForm5\RateLimit\Contracts\{RateLimiterInterface,RateLimitStoreInterface};
use DataForm5\RateLimit\Exceptions\RateLimitException;
final class RateLimiter implements RateLimiterInterface
{
    public function __construct(private readonly RateLimitStoreInterface $store, private readonly string $prefix='df5'){}
    public function attempt(string $key,int $limit,int $windowSeconds,int $cost=1):RateLimitResult
    {
        [$key,$limit,$windowSeconds,$cost]=$this->validate($key,$limit,$windowSeconds,$cost);$now=time();$id=$this->id($key);$state=$this->store->read($id);
        if($state===null||($state['reset_at']??0)<=$now)$state=['used'=>0,'reset_at'=>$now+$windowSeconds];
        $used=(int)$state['used'];$allowed=($used+$cost)<=$limit;if($allowed){$used+=$cost;$state['used']=$used;$this->store->write($id,$state);} $remaining=max(0,$limit-$used);$retry=$allowed?0:max(1,(int)$state['reset_at']-$now);
        return new RateLimitResult($allowed,$limit,$remaining,$used,(int)$state['reset_at'],$retry);
    }
    public function inspect(string $key,int $limit,int $windowSeconds):RateLimitResult
    {
        [$key,$limit,$windowSeconds]=$this->validate($key,$limit,$windowSeconds,1);$now=time();$state=$this->store->read($this->id($key));
        if($state===null||($state['reset_at']??0)<=$now)return new RateLimitResult(true,$limit,$limit,0,$now+$windowSeconds,0);
        $used=(int)$state['used'];$allowed=$used<$limit;return new RateLimitResult($allowed,$limit,max(0,$limit-$used),$used,(int)$state['reset_at'],$allowed?0:max(1,(int)$state['reset_at']-$now));
    }
    public function quota(string $key,int $limit,int $periodSeconds,int $cost=1):RateLimitResult{return $this->attempt('quota:'.$key,$limit,$periodSeconds,$cost);}
    public function clear(string $key):void{$this->store->delete($this->id($key));$this->store->delete($this->id('quota:'.$key));}
    private function id(string $key):string{return hash('sha256',$this->prefix.'|'.$key);}
    private function validate(string $key,int $limit,int $windowSeconds,int $cost=1):array
    { $key=trim($key);if($key==='')throw new RateLimitException('Rate-Limit-Schlüssel darf nicht leer sein.');if($limit<1||$windowSeconds<1||$cost<1)throw new RateLimitException('Limit, Zeitfenster und Kosten müssen positiv sein.');if($cost>$limit)throw new RateLimitException('Kosten dürfen das Limit nicht überschreiten.');return [$key,$limit,$windowSeconds,$cost]; }
}
