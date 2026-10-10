<?php
declare(strict_types=1);
namespace DataForm5\Health\Core;
enum HealthStatus:string
{
    case UP='up';
    case DEGRADED='degraded';
    case DOWN='down';
}
