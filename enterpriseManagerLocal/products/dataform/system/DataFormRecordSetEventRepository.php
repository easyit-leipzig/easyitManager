<?php
declare(strict_types=1);

require_once __DIR__.'/DataFormEventDomains.php';

/**
 * DataForm5 STAND 4
 *
 * Eigene Persistenz für DS-/RecordSet-Ereignishandler.
 *
 * Architektur:
 * - dataforms.event_handlers_json = ausschließlich DataForm-Lifecycle
 * - dataform_recordset_event_handlers = ausschließlich DS-/CRUD-Lifecycle
 * - dataforms.record_event_handlers_json = nur noch Legacy-Migrationsquelle
 *
 * Die Methoden akzeptieren bewusst object statt PDO:
 * Neben PDO-basierten Speichern muss auch EnterpriseCsvPdo funktionieren.
 */
final class DataFormRecordSetEventRepository
{
    public const TABLE = 'dataform_recordset_event_handlers';
    public const DEFAULT_RECORDSET_KEY = 'main';

    public static function ensureSchema(object $db): void
    {
        $driver = self::driverName($db);

        switch ($driver) {
            case 'csv':
                // EnterpriseCsvPdo besitzt sein logisches Schema im Adapter selbst.
                // apply_df_ds_event_split.php ergänzt dort TABLE.
                return;

            case 'mysql':
                $db->exec(
                    "CREATE TABLE IF NOT EXISTS ".self::TABLE." (
                        dataform_id BIGINT UNSIGNED NOT NULL,
                        recordset_key VARCHAR(120) NOT NULL,
                        event_key VARCHAR(80) NOT NULL,
                        handler_code LONGTEXT NULL,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (dataform_id, recordset_key, event_key),
                        INDEX idx_df_rs_event_dataform (dataform_id),
                        INDEX idx_df_rs_event_recordset (recordset_key)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
                return;

            case 'sqlite':
                $db->exec(
                    "CREATE TABLE IF NOT EXISTS ".self::TABLE." (
                        dataform_id INTEGER NOT NULL,
                        recordset_key TEXT NOT NULL,
                        event_key TEXT NOT NULL,
                        handler_code TEXT NULL,
                        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (dataform_id, recordset_key, event_key)
                    )"
                );
                $db->exec("CREATE INDEX IF NOT EXISTS idx_df_rs_event_dataform ON ".self::TABLE." (dataform_id)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_df_rs_event_recordset ON ".self::TABLE." (recordset_key)");
                return;

            case 'pgsql':
                $db->exec(
                    "CREATE TABLE IF NOT EXISTS ".self::TABLE." (
                        dataform_id BIGINT NOT NULL,
                        recordset_key VARCHAR(120) NOT NULL,
                        event_key VARCHAR(80) NOT NULL,
                        handler_code TEXT NULL,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (dataform_id, recordset_key, event_key)
                    )"
                );
                $db->exec("CREATE INDEX IF NOT EXISTS idx_df_rs_event_dataform ON ".self::TABLE." (dataform_id)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_df_rs_event_recordset ON ".self::TABLE." (recordset_key)");
                return;

            case 'sqlsrv':
                $db->exec(
                    "IF OBJECT_ID(N'dbo.".self::TABLE."', N'U') IS NULL
                    BEGIN
                        CREATE TABLE dbo.".self::TABLE." (
                            dataform_id BIGINT NOT NULL,
                            recordset_key NVARCHAR(120) NOT NULL,
                            event_key NVARCHAR(80) NOT NULL,
                            handler_code NVARCHAR(MAX) NULL,
                            updated_at DATETIME2(6) NOT NULL
                                CONSTRAINT DF_df_rs_event_updated DEFAULT SYSUTCDATETIME(),
                            CONSTRAINT PK_df_rs_event PRIMARY KEY (dataform_id, recordset_key, event_key)
                        );
                        CREATE INDEX idx_df_rs_event_dataform
                            ON dbo.".self::TABLE." (dataform_id);
                        CREATE INDEX idx_df_rs_event_recordset
                            ON dbo.".self::TABLE." (recordset_key);
                    END"
                );
                return;

            case 'oci':
                $stmt = $db->prepare("SELECT COUNT(*) FROM user_tables WHERE table_name = ?");
                $stmt->execute([strtoupper(self::TABLE)]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $db->exec(
                        "CREATE TABLE ".self::TABLE." (
                            dataform_id NUMBER(19) NOT NULL,
                            recordset_key VARCHAR2(120 CHAR) NOT NULL,
                            event_key VARCHAR2(80 CHAR) NOT NULL,
                            handler_code CLOB NULL,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
                            CONSTRAINT pk_df_rs_event PRIMARY KEY (dataform_id, recordset_key, event_key)
                        )"
                    );
                    $db->exec("CREATE INDEX idx_df_rs_event_dataform ON ".self::TABLE." (dataform_id)");
                    $db->exec("CREATE INDEX idx_df_rs_event_recordset ON ".self::TABLE." (recordset_key)");
                }
                return;

            default:
                throw new RuntimeException('DS-Eventhandler-Schema: nicht unterstützter Treiber '.$driver);
        }
    }

    public static function load(
        object $db,
        int $dataformId,
        string $recordsetKey = self::DEFAULT_RECORDSET_KEY
    ): array {
        self::assertIdentity($dataformId, $recordsetKey);
        self::ensureSchema($db);

        $handlers = array_fill_keys(DataFormEventDomains::RECORDSET_EVENTS, '');

        try {
            $stmt = $db->prepare(
                'SELECT event_key, handler_code FROM '.self::TABLE.
                ' WHERE dataform_id=? AND recordset_key=?'
            );
            $stmt->execute([$dataformId, $recordsetKey]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $event = (string)($row['event_key'] ?? '');
                if (DataFormEventDomains::isRecordSetEvent($event)) {
                    $handlers[$event] = (string)($row['handler_code'] ?? '');
                }
            }
        } catch (Throwable $e) {
            if (self::driverName($db) !== 'csv') {
                throw $e;
            }
            // Ein altes CSV-Projekt kann vor dem Adapter-Schema-Upgrade noch
            // keine physische CSV-Datei für TABLE besitzen.
        }

        return DataFormEventDomains::sanitizeRecordSetHandlers($handlers);
    }

    public static function save(
        object $db,
        int $dataformId,
        string $recordsetKey,
        array $handlers
    ): void {
        self::assertIdentity($dataformId, $recordsetKey);
        self::ensureSchema($db);
        $handlers = DataFormEventDomains::sanitizeRecordSetHandlers($handlers);

        $ownTransaction = self::canTransact($db) && !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $delete = $db->prepare(
                'DELETE FROM '.self::TABLE.' WHERE dataform_id=? AND recordset_key=?'
            );
            $delete->execute([$dataformId, $recordsetKey]);

            $insert = $db->prepare(
                'INSERT INTO '.self::TABLE.
                ' (dataform_id, recordset_key, event_key, handler_code) VALUES (?,?,?,?)'
            );

            foreach ($handlers as $event => $code) {
                if ($code === '') {
                    continue;
                }
                $insert->execute([$dataformId, $recordsetKey, $event, $code]);
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function deleteRecordSet(
        object $db,
        int $dataformId,
        string $recordsetKey = self::DEFAULT_RECORDSET_KEY
    ): void {
        self::assertIdentity($dataformId, $recordsetKey);
        self::ensureSchema($db);
        $stmt = $db->prepare(
            'DELETE FROM '.self::TABLE.' WHERE dataform_id=? AND recordset_key=?'
        );
        $stmt->execute([$dataformId, $recordsetKey]);
    }

    public static function listRecordSets(object $db, int $dataformId): array
    {
        if ($dataformId < 1) {
            throw new InvalidArgumentException('dataformId muss positiv sein.');
        }
        self::ensureSchema($db);
        $stmt = $db->prepare(
            'SELECT DISTINCT recordset_key FROM '.self::TABLE.
            ' WHERE dataform_id=? ORDER BY recordset_key'
        );
        $stmt->execute([$dataformId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Übernimmt STAND-4-Handler aus dataforms.record_event_handlers_json.
     * Die Spalte darf aus Kompatibilitätsgründen im Schema bestehen bleiben,
     * wird nach erfolgreicher Migration aber geleert und von der Runtime nicht
     * mehr als aktive Konfiguration gelesen.
     */
    public static function migrateLegacyDataformsColumn(object $db): int
    {
        self::ensureSchema($db);

        if (!self::columnExists($db, 'dataforms', 'record_event_handlers_json')) {
            return 0;
        }

        try {
            $rows = $db->query(
                'SELECT id, record_event_handlers_json FROM dataforms'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return 0;
        }

        $migrated = 0;
        $clear = $db->prepare(
            'UPDATE dataforms SET record_event_handlers_json=NULL WHERE id=?'
        );

        foreach ($rows as $row) {
            $dataformId = (int)($row['id'] ?? 0);
            if ($dataformId < 1) {
                continue;
            }

            $raw = trim((string)($row['record_event_handlers_json'] ?? ''));
            if ($raw === '') {
                continue;
            }

            $legacy = json_decode($raw, true);
            if (!is_array($legacy)) {
                continue;
            }

            $existing = self::load($db, $dataformId, self::DEFAULT_RECORDSET_KEY);
            $merged = $existing;
            foreach (DataFormEventDomains::RECORDSET_EVENTS as $event) {
                $oldCode = trim((string)($legacy[$event] ?? ''));
                if (($merged[$event] ?? '') === '' && $oldCode !== '') {
                    $merged[$event] = $oldCode;
                }
            }

            self::save($db, $dataformId, self::DEFAULT_RECORDSET_KEY, $merged);
            $clear->execute([$dataformId]);
            ++$migrated;
        }

        return $migrated;
    }

    /**
     * Entfernt versehentlich gemischte CRUD-Hooks aus event_handlers_json.
     */
    public static function splitMixedDataFormHandlers(object $db): int
    {
        if (!self::columnExists($db, 'dataforms', 'event_handlers_json')) {
            return 0;
        }

        self::ensureSchema($db);

        try {
            $rows = $db->query(
                'SELECT id, event_handlers_json FROM dataforms'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return 0;
        }

        $writeDf = $db->prepare(
            'UPDATE dataforms SET event_handlers_json=? WHERE id=?'
        );
        $count = 0;

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $legacy = json_decode((string)($row['event_handlers_json'] ?? ''), true);
            if (!is_array($legacy)) {
                $legacy = [];
            }

            $currentRs = self::load($db, $id, self::DEFAULT_RECORDSET_KEY);
            $split = DataFormEventDomains::splitLegacy($legacy, $currentRs);

            self::save($db, $id, self::DEFAULT_RECORDSET_KEY, $split['recordset']);
            $writeDf->execute([
                json_encode($split['dataform'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $id
            ]);
            ++$count;
        }

        return $count;
    }

    public static function driverName(object $db): string
    {
        // EnterpriseCsvPdo extends PDO without invoking PDO's native constructor.
        // Detect it before instanceof PDO, otherwise PDO::getAttribute() throws
        // "object is uninitialized".
        $class = strtolower(get_class($db));
        if (str_contains($class, 'enterprisecsvpdo') || str_contains($class, 'csvpdo')) {
            return 'csv';
        }

        if ($db instanceof PDO) {
            return strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
        }

        if (method_exists($db, 'getAttribute')) {
            try {
                $driver = strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
                if ($driver !== '') {
                    return $driver;
                }
            } catch (Throwable) {
            }
        }

        throw new RuntimeException('Unbekannter DataForm-Datenadapter: '.get_class($db));
    }

    private static function columnExists(object $db, string $table, string $column): bool
    {
        $driver = self::driverName($db);

        try {
            return match ($driver) {
                'csv' => self::csvColumnExists($db, $table, $column),
                'mysql' => self::mysqlColumnExists($db, $table, $column),
                'sqlite' => self::sqliteColumnExists($db, $table, $column),
                'pgsql' => self::pgsqlColumnExists($db, $table, $column),
                'sqlsrv' => self::sqlsrvColumnExists($db, $table, $column),
                'oci' => self::ociColumnExists($db, $table, $column),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private static function csvColumnExists(object $db, string $table, string $column): bool
    {
        // SELECT ist bei EnterpriseCsvPdo die verlässlichste Schema-Abfrage.
        try {
            $db->query('SELECT '.$column.' FROM '.$table.' LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function mysqlColumnExists(object $db, string $table, string $column): bool
    {
        $stmt=$db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '.
            'WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'
        );
        $stmt->execute([$table,$column]);
        return (int)$stmt->fetchColumn()>0;
    }

    private static function sqliteColumnExists(object $db, string $table, string $column): bool
    {
        $safe=str_replace('"','""',$table);
        $rows=$db->query('PRAGMA table_info("'.$safe.'")')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){
            if(strcasecmp((string)($row['name']??''),$column)===0)return true;
        }
        return false;
    }

    private static function pgsqlColumnExists(object $db, string $table, string $column): bool
    {
        $stmt=$db->prepare(
            "SELECT COUNT(*) FROM information_schema.columns ".
            "WHERE table_schema=current_schema() AND table_name=? AND column_name=?"
        );
        $stmt->execute([$table,$column]);
        return (int)$stmt->fetchColumn()>0;
    }

    private static function sqlsrvColumnExists(object $db, string $table, string $column): bool
    {
        $stmt=$db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS ".
            "WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=? AND COLUMN_NAME=?"
        );
        $stmt->execute([$table,$column]);
        return (int)$stmt->fetchColumn()>0;
    }

    private static function ociColumnExists(object $db, string $table, string $column): bool
    {
        $stmt=$db->prepare(
            "SELECT COUNT(*) FROM user_tab_columns WHERE table_name=? AND column_name=?"
        );
        $stmt->execute([strtoupper($table),strtoupper($column)]);
        return (int)$stmt->fetchColumn()>0;
    }

    private static function canTransact(object $db): bool
    {
        return method_exists($db, 'inTransaction')
            && method_exists($db, 'beginTransaction')
            && method_exists($db, 'commit')
            && method_exists($db, 'rollBack');
    }

    private static function assertIdentity(int $dataformId, string $recordsetKey): void
    {
        if ($dataformId < 1) {
            throw new InvalidArgumentException('dataformId muss positiv sein.');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $recordsetKey)) {
            throw new InvalidArgumentException('Ungültiger recordset_key.');
        }
    }
}
