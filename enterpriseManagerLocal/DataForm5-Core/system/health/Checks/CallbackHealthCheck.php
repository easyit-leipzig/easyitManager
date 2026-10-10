<?php
declare(strict_types=1);
namespace DataForm5\Health\Checks;
use Closure;use DataForm5\Health\Contracts\HealthCheckInterface;use DataForm5\Health\Core\{HealthResult,HealthStatus};use Throwable;
final class CallbackHealthCheck implements HealthCheckInterface
{
    private Closure $callback;
    public function __construct(private readonly string $checkName, callable $callback){$this->callback=Closure::fromCallable($callback);}
    public function name():string{return $this->checkName;}
    public function run():HealthResult
    {
        $start=microtime(true);
        try{$value=($this->callback)();$ms=(microtime(true)-$start)*1000;
            if($value instanceof HealthResult)return new HealthResult($value->name,$value->status,$value->message,$value->details,$ms);
            if($value===false)return new HealthResult($this->checkName,HealthStatus::DOWN,'Check fehlgeschlagen',[],$ms);
            if(is_array($value))return new HealthResult($this->checkName,HealthStatus::UP,'OK',$value,$ms);
            return new HealthResult($this->checkName,HealthStatus::UP,'OK',[],$ms);
        }catch(Throwable $e){return new HealthResult($this->checkName,HealthStatus::DOWN,$e->getMessage(),['exception'=>$e::class],(microtime(true)-$start)*1000);}
    }
}
