<?php
declare(strict_types=1);
namespace DataForm5\Security\Authorization;
use PDO;
final class CapabilityRepository {
 public function __construct(private readonly PDO $pdo) {}
 public function syncModule(string $module,array $capabilities): void { $stmt=$this->pdo->prepare('INSERT IGNORE INTO capabilities(name,module_name,label) VALUES (?,?,?)'); foreach($capabilities as $c){ if(!is_string($c)||$c==='')continue; $stmt->execute([$c,$module,$c]); } }
 public function permissionsForUser(int $userId): array { $st=$this->pdo->prepare('SELECT DISTINCT c.name FROM capabilities c JOIN role_capabilities rc ON rc.capability_id=c.id JOIN user_roles ur ON ur.role_id=rc.role_id WHERE ur.user_id=? ORDER BY c.name'); $st->execute([$userId]); return array_column($st->fetchAll(PDO::FETCH_ASSOC),'name'); }
 public function setRoleCapabilities(int $roleId,array $names): void { $this->pdo->beginTransaction(); try{$this->pdo->prepare('DELETE FROM role_capabilities WHERE role_id=?')->execute([$roleId]); $q=$this->pdo->prepare('SELECT id FROM capabilities WHERE name=?'); $i=$this->pdo->prepare('INSERT IGNORE INTO role_capabilities(role_id,capability_id) VALUES (?,?)'); foreach($names as $n){$q->execute([$n]);$id=$q->fetchColumn();if($id)$i->execute([$roleId,$id]);} $this->pdo->commit();}catch(\Throwable $e){$this->pdo->rollBack();throw $e;} }
 public function all(): array { return $this->pdo->query('SELECT * FROM capabilities ORDER BY module_name,name')->fetchAll(PDO::FETCH_ASSOC); }
}