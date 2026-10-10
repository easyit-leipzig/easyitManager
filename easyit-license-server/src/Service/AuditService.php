<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;
use EasyIT\LicenseServer\Support\Id;
use PDO;
final class AuditService
{
 public function __construct(private PDO $pdo){}
 public function write(string $event,string $entityType,?string $entityId,string $actorType,?string $actorId,?string $requestId=null,array $old=[],array $new=[]):void{
   $st=$this->pdo->prepare('INSERT INTO audit_events (audit_id,event_type,entity_type,entity_id,actor_type,actor_id,old_value,new_value,request_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
   $st->execute([Id::make('AUD'),$event,$entityType,$entityId,$actorType,$actorId,$old?json_encode($old):null,$new?json_encode($new):null,$requestId,time()]);
 }
}
