<?php
declare(strict_types=1);

namespace DataForm5\Queue\Drivers;

use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Queue\Contracts\JobInterface;
use DataForm5\Queue\Contracts\QueueInterface;
use DataForm5\Queue\Core\JobEnvelope;
use DataForm5\Queue\Exceptions\QueueException;
use PDO;

final class DatabaseQueue implements QueueInterface
{
    public function __construct(
        private readonly ServiceContainer $container,
        private readonly PDO $pdo,
        private readonly string $table='enterprise_queue_jobs',
        private readonly string $workerId=''
    ) {
        $this->ensureSchema();
    }

    public function push(JobInterface $job,int $delay=0): string
    {
        $id=bin2hex(random_bytes(16));
        $stmt=$this->pdo->prepare("INSERT INTO {$this->table}
            (id,job_class,payload_json,attempts,available_at,status,created_at,updated_at)
            VALUES(?,?,?,?,?,'pending',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $stmt->execute([
            $id,$job::class,
            json_encode($job->payload(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            0,time()+max(0,$delay)
        ]);
        return $id;
    }

    public function pop(): ?JobEnvelope
    {
        $worker=$this->workerId!==''?$this->workerId:(gethostname()?:'node').':'.getmypid();
        $this->pdo->beginTransaction();
        try{
            $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $select=$driver==='sqlsrv'
                ? "SELECT TOP 1 * FROM {$this->table} WITH (UPDLOCK,READPAST,ROWLOCK) WHERE status='pending' AND available_at<=? ORDER BY created_at,id"
                : "SELECT * FROM {$this->table} WHERE status='pending' AND available_at<=? ORDER BY created_at,id LIMIT 1 FOR UPDATE";
            $stmt=$this->pdo->prepare($select);
            $stmt->execute([time()]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$row){$this->pdo->commit();return null;}

            $claim=$this->pdo->prepare("UPDATE {$this->table}
                SET status='processing',reserved_by=?,reserved_at=?,updated_at=CURRENT_TIMESTAMP
                WHERE id=? AND status='pending'");
            $claim->execute([$worker,time(),$row['id']]);
            if($claim->rowCount()!==1){$this->pdo->rollBack();return null;}
            $this->pdo->commit();
            return $this->hydrate($row);
        }catch(\Throwable $e){
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function release(JobEnvelope $envelope,int $delay): void
    {
        $next=$envelope->nextAttempt(time()+max(0,$delay));
        $stmt=$this->pdo->prepare("UPDATE {$this->table}
            SET attempts=?,available_at=?,status='pending',reserved_by=NULL,reserved_at=NULL,updated_at=CURRENT_TIMESTAMP
            WHERE id=?");
        $stmt->execute([$next->attempts,$next->availableAt,$envelope->id]);
    }

    public function acknowledge(JobEnvelope $envelope): void
    {
        $stmt=$this->pdo->prepare("DELETE FROM {$this->table} WHERE id=?");
        $stmt->execute([$envelope->id]);
    }

    public function fail(JobEnvelope $envelope,\Throwable $error): void
    {
        $stmt=$this->pdo->prepare("UPDATE {$this->table}
            SET status='failed',attempts=?,failed_at=CURRENT_TIMESTAMP,error_class=?,error_message=?,
                reserved_by=NULL,reserved_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([
            $envelope->attempts,$error::class,mb_substr($error->getMessage(),0,4000),$envelope->id
        ]);
    }

    public function size(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM {$this->table} WHERE status='pending'")->fetchColumn();
    }

    public function clear(): void
    {
        $this->pdo->exec("DELETE FROM {$this->table} WHERE status IN ('pending','processing')");
    }

    public function statistics(): array
    {
        $rows=$this->pdo->query("SELECT status,COUNT(*) AS c FROM {$this->table} GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        $stats=['pending'=>0,'processing'=>0,'failed'=>0];
        foreach($rows as $row) if(isset($stats[$row['status']])) $stats[$row['status']]=(int)$row['c'];
        return $stats;
    }

    public function retryFailed(string $id,int $delay=0): bool
    {
        if(!preg_match('/^[a-f0-9]{32}$/i',$id)) return false;
        $stmt=$this->pdo->prepare("UPDATE {$this->table}
            SET status='pending',attempts=0,available_at=?,failed_at=NULL,error_class=NULL,error_message=NULL,updated_at=CURRENT_TIMESTAMP
            WHERE id=? AND status='failed'");
        $stmt->execute([time()+max(0,$delay),$id]);
        return $stmt->rowCount()===1;
    }

    private function hydrate(array $row): JobEnvelope
    {
        $class=(string)$row['job_class'];
        if($class===''||!class_exists($class)) throw new QueueException("Jobklasse '{$class}' ist nicht verfügbar.");
        $job=$this->container->get($class);
        if(!$job instanceof JobInterface) throw new QueueException("Jobklasse '{$class}' implementiert JobInterface nicht.");
        $payload=json_decode((string)$row['payload_json'],true);
        $job->restore(is_array($payload)?$payload:[]);
        return new JobEnvelope((string)$row['id'],$job,(int)$row['attempts'],(int)$row['available_at'],null);
    }

    private function ensureSchema(): void
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/',$this->table)) throw new \InvalidArgumentException('Ungültiger Queue-Tabellenname.');
        $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlsrv'){
            $this->pdo->exec("IF OBJECT_ID(N'{$this->table}',N'U') IS NULL BEGIN CREATE TABLE {$this->table} (id CHAR(32) PRIMARY KEY,job_class NVARCHAR(255) NOT NULL,payload_json NVARCHAR(MAX) NOT NULL,attempts INT NOT NULL DEFAULT 0,available_at BIGINT NOT NULL,status NVARCHAR(24) NOT NULL DEFAULT 'pending',reserved_by NVARCHAR(190) NULL,reserved_at BIGINT NULL,failed_at DATETIME2 NULL,error_class NVARCHAR(255) NULL,error_message NVARCHAR(MAX) NULL,created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()); CREATE INDEX idx_queue_claim ON {$this->table}(status,available_at,created_at); CREATE INDEX idx_queue_reserved ON {$this->table}(status,reserved_at); END");
            return;
        }
        if($driver==='pgsql'){
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
                id CHAR(32) PRIMARY KEY,
                job_class VARCHAR(255) NOT NULL,
                payload_json TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at BIGINT NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending',
                reserved_by VARCHAR(190) NULL,
                reserved_at BIGINT NULL,
                failed_at TIMESTAMP NULL,
                error_class VARCHAR(255) NULL,
                error_message TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_claim ON {$this->table}(status,available_at,created_at)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_reserved ON {$this->table}(status,reserved_at)");
            return;
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
            id CHAR(32) PRIMARY KEY,
            job_class VARCHAR(255) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            available_at BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            reserved_by VARCHAR(190) NULL,
            reserved_at BIGINT UNSIGNED NULL,
            failed_at TIMESTAMP NULL,
            error_class VARCHAR(255) NULL,
            error_message TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_queue_claim(status,available_at,created_at),
            KEY idx_queue_reserved(status,reserved_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }}
