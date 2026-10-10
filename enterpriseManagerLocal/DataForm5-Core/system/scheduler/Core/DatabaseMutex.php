<?php
declare(strict_types=1);

namespace DataForm5\Scheduler\Core;

use DataForm5\Scheduler\Contracts\MutexInterface;
use PDO;

final class DatabaseMutex implements MutexInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table='enterprise_cluster_locks',
        private readonly string $owner=''
    ) {
        $this->ensureSchema();
    }

    public function acquire(string $name,int $ttl): bool
    {
        $ttl=max(1,$ttl);
        $key=hash('sha256',$name);
        $owner=$this->owner!==''?$this->owner:(gethostname()?:'node').':'.getmypid();
        $now=time();
        $expires=$now+$ttl;

        $this->pdo->beginTransaction();
        try{
            $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $select=$driver==='sqlsrv'
                ? "SELECT lock_key,owner,expires_at FROM {$this->table} WITH (UPDLOCK,HOLDLOCK) WHERE lock_key=?"
                : "SELECT lock_key,owner,expires_at FROM {$this->table} WHERE lock_key=? FOR UPDATE";
            $stmt=$this->pdo->prepare($select);
            $stmt->execute([$key]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if($row && (int)$row['expires_at']>$now && (string)$row['owner']!==$owner){
                $this->pdo->rollBack();
                return false;
            }
            if($row){
                $stmt=$this->pdo->prepare("UPDATE {$this->table} SET lock_name=?,owner=?,expires_at=?,updated_at=CURRENT_TIMESTAMP WHERE lock_key=?");
                $stmt->execute([$name,$owner,$expires,$key]);
            }else{
                $stmt=$this->pdo->prepare("INSERT INTO {$this->table}(lock_key,lock_name,owner,expires_at) VALUES(?,?,?,?)");
                $stmt->execute([$key,$name,$owner,$expires]);
            }
            $this->pdo->commit();
            return true;
        }catch(\Throwable $e){
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function release(string $name): void
    {
        $key=hash('sha256',$name);
        $owner=$this->owner!==''?$this->owner:(gethostname()?:'node').':'.getmypid();
        $stmt=$this->pdo->prepare("DELETE FROM {$this->table} WHERE lock_key=? AND owner=?");
        $stmt->execute([$key,$owner]);
    }

    private function ensureSchema(): void
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/',$this->table)) throw new \InvalidArgumentException('Ungültiger Lock-Tabellenname.');
        $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlsrv'){
            $this->pdo->exec("IF OBJECT_ID(N'{$this->table}',N'U') IS NULL BEGIN CREATE TABLE {$this->table} (lock_key CHAR(64) PRIMARY KEY,lock_name NVARCHAR(255) NOT NULL,owner NVARCHAR(190) NOT NULL,expires_at BIGINT NOT NULL,updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()); CREATE INDEX idx_cluster_lock_expiry ON {$this->table}(expires_at); END");
            return;
        }
        if($driver==='pgsql'){
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
                lock_key CHAR(64) PRIMARY KEY,
                lock_name VARCHAR(255) NOT NULL,
                owner VARCHAR(190) NOT NULL,
                expires_at BIGINT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_cluster_lock_expiry ON {$this->table}(expires_at)");
            return;
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
            lock_key CHAR(64) PRIMARY KEY,
            lock_name VARCHAR(255) NOT NULL,
            owner VARCHAR(190) NOT NULL,
            expires_at BIGINT UNSIGNED NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_cluster_lock_expiry(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }}
