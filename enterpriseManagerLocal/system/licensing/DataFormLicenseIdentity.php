<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;

use PDO;

/** HF85 Phase 1-3: lokale, unveraenderliche DataForm-Identitaet und Tokenablage. */
final class DataFormLicenseIdentity
{
    public static function ensureSchema(PDO $pdo): void
    {
        $driver=self::driver($pdo);
        $add=in_array($driver,['oracle','sqlsrv'],true)?'ADD':'ADD COLUMN';
        if(!self::columnExists($pdo,'df_uuid')) {
            $pdo->exec("ALTER TABLE dataforms {$add} df_uuid VARCHAR(36) NULL");
        }
        if(!self::columnExists($pdo,'license_status')) {
            $pdo->exec("ALTER TABLE dataforms {$add} license_status VARCHAR(24) DEFAULT 'unlicensed' NOT NULL");
        }
        if(!self::columnExists($pdo,'license_registered_at')) {
            $type=$driver==='oracle'?'NUMBER(19)':'BIGINT';
            $pdo->exec("ALTER TABLE dataforms {$add} license_registered_at {$type} NULL");
        }
        if(!self::columnExists($pdo,'license_token')) {
            $type=$driver==='oracle'?'CLOB':'TEXT';
            $pdo->exec("ALTER TABLE dataforms {$add} license_token {$type} NULL");
        }
        if(!self::columnExists($pdo,'license_token_key_id')) {
            $pdo->exec("ALTER TABLE dataforms {$add} license_token_key_id VARCHAR(100) NULL");
        }
        if(!self::columnExists($pdo,'license_token_issued_at')) {
            $type=$driver==='oracle'?'NUMBER(19)':'BIGINT';
            $pdo->exec("ALTER TABLE dataforms {$add} license_token_issued_at {$type} NULL");
        }

        // Bestehende DataForms erhalten einmalig eine feste UUID. Eine bereits
        // gesetzte UUID wird niemals neu generiert oder aus dem lokalen PK abgeleitet.
        $rows=$pdo->query("SELECT id,df_uuid FROM dataforms ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $seen=[];
        $up=$pdo->prepare('UPDATE dataforms SET df_uuid=? WHERE id=?');
        foreach($rows as $row) {
            $uuid=strtolower(trim((string)($row['df_uuid']??'')));
            if(!self::isUuidV4($uuid) || isset($seen[$uuid])) {
                do {$uuid=self::uuidV4();} while(isset($seen[$uuid]));
                $up->execute([$uuid,(int)$row['id']]);
            }
            $seen[$uuid]=true;
        }

        if(self::driver($pdo)!=='csv') {
            try {$pdo->exec('CREATE UNIQUE INDEX uq_dataforms_df_uuid ON dataforms(df_uuid)');} catch(\Throwable) {}
        }
    }

    public static function uuidFor(PDO $pdo,int $dataformId): string
    {
        self::ensureSchema($pdo);
        $st=$pdo->prepare('SELECT df_uuid FROM dataforms WHERE id=? LIMIT 1');
        $st->execute([$dataformId]);
        $uuid=strtolower(trim((string)$st->fetchColumn()));
        if(!self::isUuidV4($uuid)) throw new \RuntimeException('DATAFORM_UUID_INVALID');
        return $uuid;
    }

    public static function isUuidV4(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',strtolower($uuid))===1;
    }

    public static function uuidV4(): string
    {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&0x0f)|0x40);
        $b[8]=chr((ord($b[8])&0x3f)|0x80);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }

    private static function columnExists(PDO $pdo,string $column): bool
    {
        try {
            $st=$pdo->query("SHOW COLUMNS FROM dataforms LIKE '".str_replace("'","''",$column)."'");
            return $st!==false && (bool)$st->fetch(PDO::FETCH_ASSOC);
        } catch(\Throwable) {
            try {$pdo->query('SELECT '.$column.' FROM dataforms WHERE 1=0');return true;} catch(\Throwable) {return false;}
        }
    }

    private static function driver(PDO $pdo): string
    {
        if(class_exists('EnterpriseCsvPdo',false) && $pdo instanceof \EnterpriseCsvPdo) return 'csv';
        if(class_exists('EnterpriseOraclePdo',false) && $pdo instanceof \EnterpriseOraclePdo) return 'oracle';
        try {return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));} catch(\Throwable) {return 'unknown';}
    }
}
