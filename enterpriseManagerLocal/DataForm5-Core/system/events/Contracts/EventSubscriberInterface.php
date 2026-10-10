<?php
declare(strict_types=1);
namespace DataForm5\Events\Contracts;
interface EventSubscriberInterface
{
    /** @return array<class-string|string, callable|string|array{0:string,1:int}|array{0:callable,1:int}> */
    public static function getSubscribedEvents(): array;
}
