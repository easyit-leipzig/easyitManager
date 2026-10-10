<?php
declare(strict_types=1);
require_once __DIR__.'/DataFormRecordSetEventRepository.php';
require_once __DIR__.'/DataFormTransport.php';

/**
 * DataForm project package manager.
 *
 * HF64 embeds the official easyIT DataForm logo in exported project packages and keeps package inspection compatible with the branding asset. HF53 makes imports into genuinely empty projects self-bootstrapping and verifies DataForm metadata. HF51 adds a semantic relation/lookup preview to package inspection so relations are shown as concrete mappings instead of only a row count. HF48 makes stored package rows reusable as export templates by exposing their persisted include/selection configuration to the workspace. HF47 added a real CRUD-managed saved-package repository on top of the HF46 selective, self-contained package format. HF46 added visible/persistent custom package names and package-name history. HF45 introduced the optional custom package name. Besides DataForm
 * metadata, a package may contain persistent table bindings, physical table
 * schemas and example rows from selected application/base tables.
 */
final class ProjectPackageManager
{
    private const DATAFORM_CONFIG_TABLES = [
        'dataforms',
        'dataform_fields',
        'dataform_layout_nodes',
        'dataform_behaviors',
        'dataform_versions',
        'dataform_list_settings',
        'dataform_saved_filters',
        'dataform_import_profiles',
        'dataform_queries',
    ];

    private const RELATION_TABLES = [
        'dataform_relations',
    ];

    private const BINDING_TABLES = [
        'dataform_table_bindings',
    ];

    private const WORKFLOW_TABLES = [
        'workflow_states',
        'workflow_transitions',
        'workflow_actions',
        'workflow_permissions',
    ];

    private const MODULE_TABLES = [
        'dataform_modules',
    ];

    private const OPTIONAL_RECORD_TABLES = [
        'dataform_records',
    ];

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_package_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            package_id VARCHAR(64) NOT NULL,
            project_name VARCHAR(190) NOT NULL,
            package_version VARCHAR(32) NOT NULL,
            action_name VARCHAR(20) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL,
            details_json LONGTEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pph_project (project_name), INDEX idx_pph_action (action_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!self::columnExists($pdo,'project_package_history','package_name')) {
            $pdo->exec("ALTER TABLE project_package_history ADD COLUMN package_name VARCHAR(190) NULL AFTER project_name");
        }
    }

    /**
     * HF53: Bootstrap the DataForm metadata schema before a package import.
     *
     * A freshly provisioned project can legitimately have no DataForm metadata
     * tables yet. Older import logic silently skipped database/*.json entries
     * when the corresponding target table did not exist. That could create the
     * physical application tables and sample rows while importing zero
     * DataForms. A self-contained .dfpkg must therefore provision the metadata
     * layer it needs before importing configuration rows.
     */
    private static function ensureImportMetadataSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dataforms (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            slug VARCHAR(160) NOT NULL UNIQUE,
            description TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            table_save_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
            show_save_success TINYINT(1) NOT NULL DEFAULT 1,
            view_mode VARCHAR(20) NOT NULL DEFAULT 'table',
            default_per_page INT UNSIGNED NOT NULL DEFAULT 20,
            show_search TINYINT(1) NOT NULL DEFAULT 1,
            show_filter TINYINT(1) NOT NULL DEFAULT 1,
            show_pagination TINYINT(1) NOT NULL DEFAULT 1,
            allow_create TINYINT(1) NOT NULL DEFAULT 1,
            allow_edit TINYINT(1) NOT NULL DEFAULT 1,
            allow_delete TINYINT(1) NOT NULL DEFAULT 1,
            dialog_size VARCHAR(20) NOT NULL DEFAULT 'large',
            css_class VARCHAR(160) NULL,
            additional_css LONGTEXT NULL,
            event_handlers_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!self::columnExists($pdo,'dataforms','table_save_mode')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN table_save_mode VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER status");
        }
        if (!self::columnExists($pdo,'dataforms','show_save_success')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN show_save_success TINYINT(1) NOT NULL DEFAULT 1 AFTER table_save_mode");
        }
        DataFormRecordSetEventRepository::ensureSchema($pdo);
        $runtimeColumns=[
            'view_mode'=>"VARCHAR(20) NOT NULL DEFAULT 'table'",
            'default_per_page'=>"INT UNSIGNED NOT NULL DEFAULT 20",
            'show_search'=>"TINYINT(1) NOT NULL DEFAULT 1",
            'show_filter'=>"TINYINT(1) NOT NULL DEFAULT 1",
            'show_pagination'=>"TINYINT(1) NOT NULL DEFAULT 1",
            'allow_create'=>"TINYINT(1) NOT NULL DEFAULT 1",
            'allow_edit'=>"TINYINT(1) NOT NULL DEFAULT 1",
            'allow_delete'=>"TINYINT(1) NOT NULL DEFAULT 1",
            'dialog_size'=>"VARCHAR(20) NOT NULL DEFAULT 'large'",
            'css_class'=>"VARCHAR(160) NULL",
            'additional_css'=>"LONGTEXT NULL",
            'event_handlers_json'=>"LONGTEXT NULL",
        ];
        foreach($runtimeColumns as $column=>$definition){
            if(!self::columnExists($pdo,'dataforms',$column)){
                $pdo->exec('ALTER TABLE dataforms ADD COLUMN `'.$column.'` '.$definition);
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_fields (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            label VARCHAR(190) NOT NULL,
            field_type VARCHAR(80) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            configuration_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_field_form FOREIGN KEY(dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE,
            UNIQUE KEY uq_form_field(dataform_id,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_table_bindings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            source_kind VARCHAR(30) NOT NULL DEFAULT 'system',
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            table_name VARCHAR(64) NOT NULL,
            binding_mode VARCHAR(30) NOT NULL DEFAULT 'schema',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dataform_table_binding_form(dataform_id),
            UNIQUE KEY uq_dataform_table_binding_source(source_kind,source_id,table_name),
            CONSTRAINT fk_dataform_table_binding_form FOREIGN KEY(dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_relations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            relation_type VARCHAR(10) NOT NULL,
            source_dataform_id BIGINT UNSIGNED NOT NULL,
            source_field_id BIGINT UNSIGNED NULL,
            target_dataform_id BIGINT UNSIGNED NOT NULL,
            target_display_field_id BIGINT UNSIGNED NULL,
            junction_name VARCHAR(190) NULL,
            lookup_field_id BIGINT UNSIGNED NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            configuration_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_relation_source(source_dataform_id),
            INDEX idx_relation_target(target_dataform_id),
            UNIQUE KEY uq_relation_name(name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_layout_nodes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            node_type VARCHAR(40) NOT NULL,
            title VARCHAR(190) NULL,
            position INT NOT NULL DEFAULT 10,
            configuration_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_layout_dataform(dataform_id), INDEX idx_layout_parent(parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_behaviors (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(60) NOT NULL,
            name VARCHAR(190) NOT NULL,
            action_type VARCHAR(60) NOT NULL DEFAULT 'placeholder',
            configuration_json LONGTEXT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            position INT NOT NULL DEFAULT 10,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_behavior_dataform(dataform_id), INDEX idx_behavior_event(event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            version_label VARCHAR(40) NOT NULL,
            change_note TEXT NULL,
            snapshot_json LONGTEXT NOT NULL,
            created_by VARCHAR(190) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dataform_version(dataform_id,version_label), INDEX idx_version_dataform(dataform_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_queries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            definition_json LONGTEXT NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dataform_query_name(dataform_id,name), KEY idx_dataform_queries_form(dataform_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_records (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            data_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_dataform_records_form(dataform_id),
            CONSTRAINT fk_dataform_records_form FOREIGN KEY(dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_list_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            visible_columns_json LONGTEXT NOT NULL,
            per_page INT UNSIGNED NOT NULL DEFAULT 20,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_list_settings(dataform_id,user_id),
            CONSTRAINT fk_list_settings_form FOREIGN KEY(dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_saved_filters (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            query_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_saved_filter_name(dataform_id,user_id,name), INDEX idx_saved_filters_form_user(dataform_id,user_id),
            CONSTRAINT fk_saved_filters_form FOREIGN KEY(dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_import_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            mapping_json LONGTEXT NOT NULL,
            duplicate_fields_json LONGTEXT NOT NULL,
            duplicate_action VARCHAR(20) NOT NULL DEFAULT 'skip',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_import_profile_name(dataform_id,user_id,name), INDEX idx_import_profiles_form_user(dataform_id,user_id),
            CONSTRAINT fk_import_profiles_form FOREIGN KEY(dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Returns the selectable DataForms and physical application tables for the
     * export UI. Internal DataForm/workflow/system tables are deliberately not
     * offered as base tables.
     */
    public static function exportCatalog(PDO $pdo): array
    {
        $dataforms=[];
        if (self::tableExists($pdo,'dataforms')) {
            if (self::tableExists($pdo,'dataform_table_bindings')) {
                $dataforms=$pdo->query(
                    "SELECT d.id,d.name,d.slug,b.table_name,b.binding_mode
                     FROM dataforms d
                     LEFT JOIN dataform_table_bindings b ON b.dataform_id=d.id
                     ORDER BY d.name,d.id"
                )->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $dataforms=$pdo->query(
                    'SELECT id,name,slug,NULL AS table_name,NULL AS binding_mode FROM dataforms ORDER BY name,id'
                )->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $tables=[];
        $rows=$pdo->query(
            "SELECT TABLE_NAME,TABLE_ROWS
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $table=(string)($row['TABLE_NAME']??'');
            if (!self::isExportableApplicationTable($table)) {
                continue;
            }
            $tables[]=[
                'name'=>$table,
                'rows'=>(int)($row['TABLE_ROWS']??0),
            ];
        }
        return ['dataforms'=>$dataforms,'tables'=>$tables];
    }

    /**
     * @param array|bool $options bool remains accepted for compatibility with
     * older callers and maps to the previous with-records switch.
     */
    public static function export(PDO $pdo,array $project,array $user,array|bool $options=[]): array
    {
        self::ensureSchema($pdo);
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung ext-zip ist nicht verfügbar.');
        }

        $opts=self::normalizeExportOptions($pdo,$options);
        $selectedIds=$opts['dataform_ids'];
        $selectedTables=$opts['base_tables'];

        if ($opts['include_dataforms'] && $selectedIds===[]) {
            throw new RuntimeException('Wählen Sie mindestens ein DataForm für den Paketexport aus.');
        }

        // Relations can reference bound tables and HF43 base-table lookups.
        // These dependencies are automatically included so a package cannot
        // silently omit a table required by a selected DataForm relation.
        $relationRows=$opts['include_relations']
            ? self::selectedRelationRows($pdo,$selectedIds)
            : [];
        $dependencyTables=self::dependencyTables($pdo,$selectedIds,$relationRows);
        $selectedTables=array_values(array_unique(array_merge($selectedTables,$dependencyTables)));
        sort($selectedTables,SORT_STRING);
        foreach ($selectedTables as $table) {
            self::assertExportablePhysicalTable($pdo,$table);
        }

        $packageId=bin2hex(random_bytes(16));
        $version='1.2.0';
        $stamp=gmdate('Ymd_His');
        $standardName=trim((string)($project['name']??''))!=='' ? trim((string)$project['name']) : 'DataForm-Projekt';
        $packageName=$opts['package_name']!=='' ? $opts['package_name'] : $standardName;
        $fileBaseSource=$opts['package_name']!=='' ? $opts['package_name'] : (string)($project['slug']??'');
        $safe=self::packageFileBase($fileBaseSource);
        if ($safe==='') {
            $safe='dataform-project';
        }
        $filename=$safe.'-'.$version.'-'.$stamp.'.dfpkg';
        $dir=self::exportDirectory(true);
        $path=$dir.'/'.$filename;

        $config=[];
        if ($opts['include_dataforms']) {
            $config=array_merge($config,self::collectDataformConfiguration($pdo,$selectedIds));
        }
        if ($opts['include_relations'] && self::tableExists($pdo,'dataform_relations')) {
            $config['dataform_relations']=$relationRows;
        }
        if ($opts['include_bindings'] && self::tableExists($pdo,'dataform_table_bindings')) {
            $config['dataform_table_bindings']=self::rowsByDataformIds($pdo,'dataform_table_bindings',$selectedIds);
        }
        if ($opts['include_workflow']) {
            $config=array_merge($config,self::collectWorkflowConfiguration($pdo,$selectedIds));
        }
        if ($opts['include_modules'] && self::tableExists($pdo,'dataform_modules')) {
            $config['dataform_modules']=$pdo->query('SELECT * FROM dataform_modules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($opts['include_records'] && self::tableExists($pdo,'dataform_records')) {
            $config['dataform_records']=self::rowsByDataformIds($pdo,'dataform_records',$selectedIds);
        }

        $schema=[];
        $records=[];
        foreach ($selectedTables as $table) {
            if ($opts['include_table_schema']) {
                $schema[$table]=self::showCreateTable($pdo,$table);
            }
            if ($opts['include_records']) {
                $records[$table]=$pdo->query('SELECT * FROM '.self::quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $packageMedia=['index'=>[],'files'=>[]];
        if ($opts['include_records'] && $selectedIds!==[]) {
            $packageMedia=self::collectPackageMedia($pdo,$selectedIds);
        }

        $manifest=[
            'format'=>'easyit-dataform-project-package',
            'formatVersion'=>'1.2',
            'packageId'=>$packageId,
            'packageName'=>$packageName,
            'packageNameMode'=>$opts['package_name']!==''?'custom':'standard',
            'product'=>'DataForm',
            'branding'=>[
                'name'=>'easyIT DataForm',
                'logo'=>'assets/branding/easyit-dataform-logo.png',
            ],
            'project'=>[
                'name'=>$project['name'],
                'slug'=>$project['slug'],
                'database'=>$project['database_name'],
            ],
            'version'=>$version,
            'createdAt'=>gmdate(DATE_ATOM),
            'createdBy'=>(string)($user['username']??'unknown'),
            'includes'=>[
                'dataforms'=>$opts['include_dataforms'],
                'relations'=>$opts['include_relations'],
                'bindings'=>$opts['include_bindings'],
                'workflow'=>$opts['include_workflow'],
                'modules'=>$opts['include_modules'],
                'tableSchema'=>$opts['include_table_schema'],
                'records'=>$opts['include_records'],
                'media'=>$opts['include_records'] && $packageMedia['index']!==[],
            ],
            'selection'=>[
                'dataformIds'=>$selectedIds,
                'physicalTables'=>$selectedTables,
                'autoIncludedTables'=>$dependencyTables,
            ],
            'counts'=>[
                'configuration'=>array_map('count',$config),
                'schemas'=>count($schema),
                'physicalRecords'=>array_map('count',$records),
                'media'=>count($packageMedia['index']),
            ],
        ];

        $zip=new ZipArchive();
        if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) {
            throw new RuntimeException('Paketarchiv konnte nicht erstellt werden.');
        }
        $zip->addFromString('manifest.json',self::json($manifest));
        $brandingLogo=dirname(__DIR__).'/assets/branding/easyit-dataform-logo.png';
        if (!is_file($brandingLogo) || !is_readable($brandingLogo)) {
            $zip->close();
            @unlink($path);
            throw new RuntimeException('Das easyIT-DataForm-Logo für den Paketexport fehlt.');
        }
        if (!$zip->addFile($brandingLogo,'assets/branding/easyit-dataform-logo.png')) {
            $zip->close();
            @unlink($path);
            throw new RuntimeException('Das easyIT-DataForm-Logo konnte nicht in das Paket aufgenommen werden.');
        }
        $zip->addFromString('project.json',self::json([
            'name'=>$project['name'],
            'slug'=>$project['slug'],
            'packageName'=>$packageName,
            'product'=>'dataform',
        ]));
        foreach ($config as $table=>$rows) {
            $zip->addFromString('database/'.$table.'.json',self::json($rows));
        }
        foreach ($schema as $table=>$sql) {
            $zip->addFromString('schema/'.$table.'.sql',$sql."\n");
        }
        foreach ($records as $table=>$rows) {
            $zip->addFromString('records/'.$table.'.json',self::json($rows));
        }
        if ($packageMedia['index']!==[]) {
            $zip->addFromString('media/index.json',self::json($packageMedia['index']));
            foreach ($packageMedia['files'] as $entry=>$bytes) {
                $zip->addFromString($entry,$bytes);
            }
        }
        $zip->addFromString(
            'README.txt',
            "easyIT DataForm project package\n"
            ."Package name: {$packageName}\n"
            ."Format: 1.2\nPackage-ID: {$packageId}\n"
            ."Selected DataForms: ".implode(',',$selectedIds)."\n"
            ."Physical tables: ".implode(',',$selectedTables)."\n"
            ."Do not edit package files manually.\n"
        );
        $zip->close();

        $hash=hash_file('sha256',$path);
        self::log(
            $pdo,$manifest,'export',$filename,$hash,'success',
            ['selection'=>$manifest['selection'],'counts'=>$manifest['counts']],
            (int)($user['id']??0)
        );
        return ['path'=>$path,'filename'=>$filename,'sha256'=>$hash,'manifest'=>$manifest];
    }

    public static function inspectUpload(array $file,string $tempRoot): array
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {
            throw new RuntimeException('Das Projektpaket konnte nicht hochgeladen werden.');
        }
        if ((int)($file['size']??0)>250*1024*1024) {
            throw new RuntimeException('Das Paket darf höchstens 250 MB groß sein.');
        }
        $name=(string)($file['name']??'');
        if (!preg_match('/\.(dfpkg|zip)$/i',$name)) {
            throw new RuntimeException('Erlaubt sind nur .dfpkg- oder .zip-Pakete.');
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung ext-zip ist nicht verfügbar.');
        }
        $token=bin2hex(random_bytes(12));
        $dir=rtrim($tempRoot,'/').'/'.$token;
        if (!mkdir($dir,0775,true) && !is_dir($dir)) {
            throw new RuntimeException('Temporäres Verzeichnis konnte nicht erzeugt werden.');
        }
        $archive=$dir.'/package.dfpkg';
        if (!move_uploaded_file((string)$file['tmp_name'],$archive)) {
            throw new RuntimeException('Upload konnte nicht sicher übernommen werden.');
        }

        $zip=new ZipArchive();
        if ($zip->open($archive)!==true) {
            throw new RuntimeException('Das Paket ist kein gültiges ZIP-Archiv.');
        }
        if ($zip->numFiles>5000) {
            $zip->close();
            throw new RuntimeException('Das Paket enthält zu viele Dateien.');
        }

        $total=0;
        $schemaFiles=[];
        $recordFiles=[];
        $configCounts=[];
        $configRows=[];
        for ($i=0;$i<$zip->numFiles;$i++) {
            $stat=$zip->statIndex($i);
            $entry=(string)$stat['name'];
            $total+=(int)($stat['size']??0);
            if ($total>1024*1024*1024) {
                $zip->close();
                throw new RuntimeException('Entpackte Paketgröße ist zu groß.');
            }
            if (str_contains($entry,'../') || str_starts_with($entry,'/') || preg_match('/^[A-Za-z]:/',$entry)) {
                $zip->close();
                throw new RuntimeException('Unsicherer Pfad im Paket.');
            }
            if (!preg_match('#^(manifest\.json|project\.json|README\.txt|database/[a-z0-9_]+\.json|schema/[a-z0-9_]+\.sql|records/[a-z0-9_]+\.json|media/index\.json|media/[a-f0-9]{64}\.bin|assets/branding/easyit-dataform-logo\.png)$#i',$entry)) {
                $zip->close();
                throw new RuntimeException('Nicht erlaubte Paketdatei: '.$entry);
            }
            if ($entry==='assets/branding/easyit-dataform-logo.png') {
                if ((int)($stat['size']??0)>2*1024*1024) {
                    $zip->close();
                    throw new RuntimeException('Das DataForm-Logo im Paket ist zu groß.');
                }
                $logoBytes=(string)$zip->getFromIndex($i);
                if (!str_starts_with($logoBytes, "\x89PNG\r\n\x1a\n")) {
                    $zip->close();
                    throw new RuntimeException('Das DataForm-Logo im Paket ist keine gültige PNG-Datei.');
                }
            }
        }

        $raw=$zip->getFromName('manifest.json');
        if ($raw===false) {
            $zip->close();
            throw new RuntimeException('manifest.json fehlt.');
        }
        $manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (($manifest['format']??'')!=='easyit-dataform-project-package' || ($manifest['product']??'')!=='DataForm') {
            $zip->close();
            throw new RuntimeException('Das Paketformat wird nicht unterstützt.');
        }

        $allowedConfig=self::allConfigTables();
        for ($i=0;$i<$zip->numFiles;$i++) {
            $entry=(string)$zip->getNameIndex($i);
            if (preg_match('#^database/([a-z0-9_]+)\.json$#i',$entry,$m)) {
                $table=$m[1];
                if (!in_array($table,$allowedConfig,true)) {
                    $zip->close();
                    throw new RuntimeException('Nicht erlaubte Konfigurationstabelle im Paket: '.$table);
                }
                $rows=json_decode((string)$zip->getFromIndex($i),true,512,JSON_THROW_ON_ERROR);
                if (!is_array($rows)) {
                    $zip->close();
                    throw new RuntimeException('Ungültige Tabellendaten: '.$table);
                }
                $configCounts[$table]=count($rows);
                $configRows[$table]=$rows;
                continue;
            }
            if (preg_match('#^schema/([a-z0-9_]+)\.sql$#i',$entry,$m)) {
                $table=$m[1];
                if (!self::isExportableApplicationTable($table)) {
                    $zip->close();
                    throw new RuntimeException('Nicht erlaubte Basistabelle im Schema: '.$table);
                }
                $sql=(string)$zip->getFromIndex($i);
                self::validateCreateTableSql($table,$sql);
                $schemaFiles[$table]=$entry;
                continue;
            }
            if (preg_match('#^records/([a-z0-9_]+)\.json$#i',$entry,$m)) {
                $table=$m[1];
                if (!self::isExportableApplicationTable($table)) {
                    $zip->close();
                    throw new RuntimeException('Nicht erlaubte Basistabelle in Beispieldaten: '.$table);
                }
                $rows=json_decode((string)$zip->getFromIndex($i),true,512,JSON_THROW_ON_ERROR);
                if (!is_array($rows)) {
                    $zip->close();
                    throw new RuntimeException('Ungültige Beispieldaten: '.$table);
                }
                $recordFiles[$table]=['entry'=>$entry,'count'=>count($rows)];
            }
        }
        $mediaCount=0;
        $mediaRaw=$zip->getFromName('media/index.json');
        if ($mediaRaw!==false) {
            $mediaIndex=json_decode((string)$mediaRaw,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($mediaIndex)) { $zip->close(); throw new RuntimeException('Ungültiger Medienindex im Paket.'); }
            foreach ($mediaIndex as $sha=>$meta) {
                if (preg_match('/^[a-f0-9]{64}$/i',(string)$sha)!==1 || !is_array($meta)) { $zip->close(); throw new RuntimeException('Ungültiger Medieneintrag im Paket.'); }
                $entry=(string)($meta['entry']??'');
                if ($entry!=='media/'.strtolower((string)$sha).'.bin') { $zip->close(); throw new RuntimeException('Medienindex enthält einen ungültigen Dateipfad.'); }
                $bytes=$zip->getFromName($entry);
                if ($bytes===false || !hash_equals(strtolower((string)$sha),hash('sha256',$bytes))) { $zip->close(); throw new RuntimeException('Medienprüfsumme im Paket ist ungültig.'); }
                if (isset($meta['size']) && (int)$meta['size']!==strlen($bytes)) { $zip->close(); throw new RuntimeException('Mediengröße im Paket ist inkonsistent.'); }
                $mediaCount++;
            }
        }
        $zip->close();

        return [
            'archive'=>$archive,
            'temp_dir'=>$dir,
            'filename'=>$name,
            'sha256'=>hash_file('sha256',$archive),
            'manifest'=>$manifest,
            'tables'=>$configCounts,
            'relations'=>self::relationPreviewRows($configRows),
            'schemas'=>array_keys($schemaFiles),
            'records'=>array_map(static fn(array $x): int=>$x['count'],$recordFiles),
            'media'=>$mediaCount,
        ];
    }

    /**
     * HF52: Rebuild derived preview details from the checked archive itself.
     *
     * Preview state is persisted in the session. After upgrading from HF50 to
     * HF51 an already checked package can therefore still contain the old
     * preview shape (relation count present, relation details absent). This
     * method makes previews self-healing and also protects future UI-only
     * preview extensions from the same stale-session problem.
     */
    public static function refreshPreviewDetails(array $preview): array
    {
        $archive=(string)($preview['archive']??'');
        if ($archive==='' || !is_file($archive) || !class_exists('ZipArchive')) {
            return $preview;
        }

        $zip=new ZipArchive();
        if ($zip->open($archive)!==true) {
            return $preview;
        }

        try {
            $configRows=[];
            foreach (['dataforms','dataform_fields','dataform_relations'] as $table) {
                $raw=$zip->getFromName('database/'.$table.'.json');
                if ($raw===false) {
                    $configRows[$table]=[];
                    continue;
                }
                $rows=json_decode((string)$raw,true);
                $configRows[$table]=is_array($rows)?$rows:[];
            }
            $preview['relations']=self::relationPreviewRows($configRows);
            $preview['relation_detail_count']=count($preview['relations']);
        } finally {
            $zip->close();
        }
        return $preview;
    }

    /**
     * Build human-readable relation descriptions exclusively from the package
     * metadata. The import preview must show what is linked, not only how many
     * rows dataform_relations contains.
     */
    private static function relationPreviewRows(array $configRows): array
    {
        $relations=is_array($configRows['dataform_relations']??null)
            ? $configRows['dataform_relations']
            : [];
        if ($relations===[]) {
            return [];
        }

        $forms=[];
        foreach ((array)($configRows['dataforms']??[]) as $row) {
            $id=(int)($row['id']??0);
            if ($id<1) {
                continue;
            }
            $forms[$id]=(string)($row['name']??$row['slug']??('#'.$id));
        }

        $fields=[];
        foreach ((array)($configRows['dataform_fields']??[]) as $row) {
            $id=(int)($row['id']??0);
            if ($id<1) {
                continue;
            }
            $fields[$id]=[
                'name'=>(string)($row['name']??('#'.$id)),
                'label'=>(string)($row['label']??$row['name']??('#'.$id)),
                'dataform_id'=>(int)($row['dataform_id']??0),
            ];
        }

        $result=[];
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }
            $type=(string)($relation['relation_type']??'');
            $sourceId=(int)($relation['source_dataform_id']??0);
            $targetId=(int)($relation['target_dataform_id']??0);
            $source=$forms[$sourceId]??('#'.$sourceId);
            $target=$forms[$targetId]??('#'.$targetId);
            $sourceFieldId=(int)($relation['source_field_id']??0);
            $lookupFieldId=(int)($relation['lookup_field_id']??0);
            $displayFieldId=(int)($relation['target_display_field_id']??0);
            $sourceField=(string)($fields[$sourceFieldId]['name']??'');
            $lookupField=(string)($fields[$lookupFieldId]['name']??'');
            $displayField=(string)($fields[$displayFieldId]['name']??'');
            $cfg=[];
            $rawCfg=$relation['configuration_json']??null;
            if (is_string($rawCfg) && trim($rawCfg)!=='') {
                $decoded=json_decode($rawCfg,true);
                if (is_array($decoded)) {
                    $cfg=$decoded;
                }
            } elseif (is_array($rawCfg)) {
                $cfg=$rawCfg;
            }

            $targetLabel=$target;
            $mapping='';
            $display='';

            if ($type==='n:1') {
                $base=is_array($cfg['base_table']??null)?$cfg['base_table']:[];
                $isBase=(string)($cfg['lookup_source_kind']??'')==='base_table'
                    || (string)($cfg['semantics']??'')==='lookup-base-table-v1';
                if ($isBase && trim((string)($base['table']??''))!=='') {
                    $table=(string)$base['table'];
                    $key=trim((string)($base['key_column']??'id')) ?: 'id';
                    $show=trim((string)($base['display_column']??''));
                    $targetLabel='Basistabelle → '.$table;
                    $mapping=($sourceField!==''?$sourceField:'?').' → '.$table.'.'.$key;
                    $display=$show!==''?$show:$key;
                } else {
                    $mapping=($sourceField!==''?$sourceField:'?').' → '.$target.'.id';
                    $display=$displayField!==''?$displayField:'id';
                }
            } elseif ($type==='1:n') {
                $mapping='id → '.($lookupField!==''?$lookupField:'?');
                $display=$displayField!==''?$displayField:'Technische Datensatz-ID';
            } elseif ($type==='n:m') {
                $junction=trim((string)($relation['junction_name']??''));
                $mapping=$junction!==''?'über '.$junction:'über Zuordnungstabelle';
            } else {
                $mapping='—';
            }

            $result[]=[
                'name'=>(string)($relation['name']??''),
                'type'=>$type,
                'source'=>$source,
                'target'=>$targetLabel,
                'mapping'=>$mapping,
                'display'=>$display,
                'required'=>(int)($relation['is_required']??0)===1,
                'enabled'=>(int)($relation['is_enabled']??1)===1,
            ];
        }
        return $result;
    }

    public static function import(PDO $pdo,array $preview,array $user,string $policy='merge',int $targetProjectId=0): array
    {
        self::ensureSchema($pdo);
        if (!in_array($policy,['merge','skip'],true)) {
            throw new RuntimeException('Ungültige Konfliktstrategie.');
        }
        $zip=new ZipArchive();
        if ($zip->open((string)$preview['archive'])!==true) {
            throw new RuntimeException('Geprüftes Paket ist nicht mehr verfügbar.');
        }

        // HF53: A package import into a genuinely empty DataForm project must
        // bootstrap the DataForm metadata layer before any database/*.json row
        // is processed. Otherwise older logic silently skipped missing target
        // metadata tables and imported only the physical application tables.
        self::ensureImportMetadataSchema($pdo);

        $result=[
            'inserted'=>0,
            'updated'=>0,
            'skipped'=>0,
            'tables'=>[],
            'schemas'=>['created'=>0,'existing'=>0],
            'physical_records'=>[],
            'media'=>['restored'=>0,'available'=>0],
        ];

        // DDL is deliberately executed before the metadata transaction. MySQL
        // commits DDL implicitly, therefore pretending it is transactional
        // would be misleading. Existing target tables are never altered.
        $schemaEntries=[];
        for ($i=0;$i<$zip->numFiles;$i++) {
            $entry=(string)$zip->getNameIndex($i);
            if (preg_match('#^schema/([a-z0-9_]+)\.sql$#i',$entry,$m)) {
                $schemaEntries[$m[1]]=$entry;
            }
        }
        if ($schemaEntries!==[]) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($schemaEntries as $table=>$entry) {
                    if (self::tableExists($pdo,$table)) {
                        $result['schemas']['existing']++;
                        continue;
                    }
                    $sql=(string)$zip->getFromName($entry);
                    self::validateCreateTableSql($table,$sql);
                    $pdo->exec($sql);
                    $result['schemas']['created']++;
                    self::registerManagedTable($pdo,$table);
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        $order=[
            'dataforms',
            'dataform_fields',
            'dataform_table_bindings',
            'dataform_layout_nodes',
            'dataform_behaviors',
            'dataform_versions',
            'dataform_queries',
            'dataform_list_settings',
            'dataform_saved_filters',
            'dataform_import_profiles',
            'workflow_states',
            'workflow_transitions',
            'workflow_actions',
            'workflow_permissions',
            'dataform_relations',
            'dataform_modules',
            'dataform_records',
        ];

        $pdo->beginTransaction();
        try {
            foreach ($order as $table) {
                $raw=$zip->getFromName('database/'.$table.'.json');
                if ($raw===false) {
                    continue;
                }
                $rows=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($rows)) {
                    throw new RuntimeException('Ungültige Metadaten im Paket: '.$table);
                }
                if (!self::tableExists($pdo,$table)) {
                    if ($rows!==[]) {
                        throw new RuntimeException(
                            'Das Paket enthält Metadaten für „'.$table.'“, aber die erforderliche Zieltabelle konnte nicht bereitgestellt werden.'
                        );
                    }
                    continue;
                }
                $counts=self::importRows($pdo,$table,$rows,$policy);
                $result['tables'][$table]=$counts;
                foreach (['inserted','updated','skipped'] as $k) {
                    $result[$k]+=$counts[$k];
                }
            }

            // Physical example rows are imported after metadata, but inside the
            // same DML transaction. Their IDs are preserved intentionally so
            // the exported 1:n and lookup sample references stay coherent.
            for ($i=0;$i<$zip->numFiles;$i++) {
                $entry=(string)$zip->getNameIndex($i);
                if (!preg_match('#^records/([a-z0-9_]+)\.json$#i',$entry,$m)) {
                    continue;
                }
                $table=$m[1];
                if (!self::tableExists($pdo,$table)) {
                    throw new RuntimeException('Beispieldaten-Zieltabelle fehlt: '.$table);
                }
                $rows=json_decode((string)$zip->getFromIndex($i),true,512,JSON_THROW_ON_ERROR);
                $counts=self::importRows($pdo,$table,$rows,$policy);
                $result['physical_records'][$table]=$counts;
                foreach (['inserted','updated','skipped'] as $k) {
                    $result[$k]+=$counts[$k];
                }
            }
            // HF76: Re-home packaged media through the target field's
            // storage policy. Source filesystem paths are never reused.
            $mediaRaw=$zip->getFromName('media/index.json');
            $createdMedia=[];
            if ($mediaRaw!==false) {
                $mediaIndex=json_decode((string)$mediaRaw,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($mediaIndex)) throw new RuntimeException('Ungültiger Medienindex im Paket.');
                $result['media']['available']=count($mediaIndex);
                $storage=new DataFormFieldStorageManager(dirname(__DIR__,3));
                $dataformIds=array_values(array_filter(array_map('intval',(array)($preview['manifest']['selection']['dataformIds']??[])),static fn(int $id):bool=>$id>0));
                foreach ($dataformIds as $dfId) {
                    $fields=DataFormTransport::fields($pdo,$dfId);
                    $mediaFields=array_values(array_filter($fields,static fn(array $f):bool=>DataFormFieldTypeRegistry::isMedia((string)$f['field_type'])));
                    if ($mediaFields===[]) continue;
                    foreach (DataFormRecordStore::all($pdo,$dfId) as $record) {
                        $data=(array)$record['data']; $changed=false;
                        foreach ($mediaFields as $field) {
                            $name=(string)$field['name']; $raw=(string)($data[$name]??'');
                            $descriptor=DataFormFieldStorageManager::descriptor($raw);
                            if ($descriptor===null) continue;
                            $sha=strtolower((string)$descriptor['sha256']);
                            $meta=$mediaIndex[$sha]??null;
                            if (!is_array($meta)) continue;
                            $entry=(string)($meta['entry']??'');
                            $bytes=$zip->getFromName($entry);
                            if ($bytes===false || !hash_equals($sha,hash('sha256',$bytes))) throw new RuntimeException('Paketmedium ist beschädigt: '.$sha);
                            $cfg=is_array($field['configuration']??null)?$field['configuration']:[];
                            $stored=$storage->storeBytes($bytes,(string)($descriptor['name']??'package.bin'),(string)$field['field_type'],$cfg,[
                                'project_id'=>$targetProjectId,
                                'dataform_id'=>$dfId,
                                'field_name'=>$name,
                            ]);
                            $createdMedia[]=$stored;
                            $data[$name]=$stored; $changed=true; $result['media']['restored']++;
                        }
                        if ($changed) DataFormRecordStore::update($pdo,$dfId,(int)$record['id'],$data);
                    }
                }
            }

            // HF53: A package that contains DataForms must not be reported
            // as successfully imported when no DataForm rows reached the target.
            $packagedDataformsRaw=$zip->getFromName('database/dataforms.json');
            if ($packagedDataformsRaw!==false) {
                $packagedDataforms=json_decode($packagedDataformsRaw,true,512,JSON_THROW_ON_ERROR);
                if (is_array($packagedDataforms) && $packagedDataforms!==[]) {
                    $expectedIds=[];
                    foreach ($packagedDataforms as $row) {
                        if (is_array($row) && isset($row['id'])) {
                            $expectedIds[]=(int)$row['id'];
                        }
                    }
                    if ($expectedIds!==[]) {
                        $placeholders=implode(',',array_fill(0,count($expectedIds),'?'));
                        $verify=$pdo->prepare('SELECT COUNT(*) FROM dataforms WHERE id IN ('.$placeholders.')');
                        $verify->execute($expectedIds);
                        if ((int)$verify->fetchColumn()!==count(array_unique($expectedIds))) {
                            throw new RuntimeException('DataForm-Metadaten wurden nicht vollständig in das Zielprojekt übernommen.');
                        }
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!empty($createdMedia) && isset($storage) && $storage instanceof DataFormFieldStorageManager) {
                DataFormTransport::cleanup($createdMedia,$storage);
            }
            $zip->close();
            throw $e;
        }
        $zip->close();

        self::log(
            $pdo,$preview['manifest'],'import',(string)$preview['filename'],
            (string)$preview['sha256'],'success',$result,(int)($user['id']??0)
        );
        self::cleanup($preview);
        return $result;
    }

    /**
     * Returns physical .dfpkg files that are currently stored for this project.
     * This list is deliberately separate from project_package_history: the
     * former is the CRUD repository, the latter remains the immutable audit log.
     */
    public static function storedPackages(array $project): array
    {
        $dir=self::exportDirectory(false);
        if (!is_dir($dir)) {
            return [];
        }
        $out=[];
        foreach (glob($dir.'/*.dfpkg')?:[] as $path) {
            try {
                $manifest=self::storedManifest($path);
                if (!self::manifestBelongsToProject($manifest,$project)) {
                    continue;
                }
                $out[]=[
                    'file_name'=>basename($path),
                    'package_id'=>(string)($manifest['packageId']??''),
                    'package_name'=>(string)($manifest['packageName']??$manifest['project']['name']??basename($path)),
                    'project_name'=>(string)($manifest['project']['name']??''),
                    'version'=>(string)($manifest['version']??''),
                    'created_at'=>(string)($manifest['createdAt']??''),
                    'updated_at'=>(string)($manifest['updatedAt']??''),
                    'size'=>(int)(filesize($path)?:0),
                    'sha256'=>(string)hash_file('sha256',$path),
                    // HF48: expose the persisted export configuration so a click
                    // on a stored package can repopulate the export form.
                    'package_name_mode'=>(string)($manifest['packageNameMode']??'standard'),
                    'includes'=>is_array($manifest['includes']??null) ? $manifest['includes'] : [],
                    'selection'=>is_array($manifest['selection']??null) ? $manifest['selection'] : [],
                ];
            } catch (Throwable) {
                // Corrupt/foreign files are not exposed as manageable project packages.
            }
        }
        usort($out,static function(array $a,array $b): int {
            $ta=strtotime((string)($a['updated_at']?:$a['created_at']))?:0;
            $tb=strtotime((string)($b['updated_at']?:$b['created_at']))?:0;
            if ($ta===$tb) {
                return strcmp((string)$b['file_name'],(string)$a['file_name']);
            }
            return $tb<=>$ta;
        });
        return $out;
    }

    /**
     * HF49: resolve a stored package by its immutable manifest packageId.
     * Filenames are mutable CRUD presentation data and therefore must not be
     * the primary identifier for follow-up operations.
     */
    private static function resolveStoredPackagePathById(array $project,string $packageId): string
    {
        $packageId=trim($packageId);
        if ($packageId==='' || !preg_match('/^[A-Za-z0-9_-]{8,128}$/',$packageId)) {
            throw new RuntimeException('Ungültige Paket-ID.');
        }
        $dir=self::exportDirectory(false);
        if (!is_dir($dir)) {
            throw new RuntimeException('Die Paketablage ist nicht vorhanden.');
        }
        clearstatcache();
        foreach (glob($dir.'/*.dfpkg')?:[] as $path) {
            try {
                $manifest=self::storedManifest($path);
                if (!self::manifestBelongsToProject($manifest,$project)) {
                    continue;
                }
                if (hash_equals($packageId,(string)($manifest['packageId']??''))) {
                    return $path;
                }
            } catch (Throwable) {
                // A corrupt package cannot satisfy an ID lookup.
            }
        }
        throw new RuntimeException('Das gespeicherte Paket wurde nicht gefunden.');
    }

    /** HF50: classify a lookup failure as a harmless already-missing package state. */
    public static function isStoredPackageMissingError(Throwable $error): bool
    {
        $message=mb_strtolower(trim($error->getMessage()),'UTF-8');
        return str_contains($message,'gespeicherte paket wurde nicht gefunden')
            || str_contains($message,'paketablage ist nicht vorhanden');
    }

    /** Resolve a stored package for a safe read/download operation. */
    public static function storedPackageForDownload(array $project,string $file): array
    {
        $path=self::resolveStoredPackagePath($file);
        $manifest=self::storedManifest($path);
        if (!self::manifestBelongsToProject($manifest,$project)) {
            throw new RuntimeException('Dieses Paket gehört nicht zum aktuellen Projekt.');
        }
        return [
            'path'=>$path,
            'filename'=>basename($path),
            'size'=>(int)(filesize($path)?:0),
            'sha256'=>(string)hash_file('sha256',$path),
            'manifest'=>$manifest,
        ];
    }

    /** HF49: stable-ID variant used by current package CRUD. */
    public static function storedPackageForDownloadById(array $project,string $packageId): array
    {
        $path=self::resolveStoredPackagePathById($project,$packageId);
        $manifest=self::storedManifest($path);
        return [
            'path'=>$path,
            'filename'=>basename($path),
            'size'=>(int)(filesize($path)?:0),
            'sha256'=>(string)hash_file('sha256',$path),
            'manifest'=>$manifest,
        ];
    }

    /** HF49: UPDATE by immutable package ID, resilient to prior filename changes. */
    public static function renameStoredPackageById(
        PDO $pdo,array $project,array $user,string $packageId,string $newPackageName
    ): array {
        $path=self::resolveStoredPackagePathById($project,$packageId);
        return self::renameStoredPackage($pdo,$project,$user,basename($path),$newPackageName);
    }

    /**
     * UPDATE in package CRUD: changes the visible package name and the physical
     * filename while keeping the package ID and package contents intact.
     */
    public static function renameStoredPackage(
        PDO $pdo,array $project,array $user,string $file,string $newPackageName
    ): array {
        self::ensureSchema($pdo);
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung ext-zip ist nicht verfügbar.');
        }
        $newPackageName=self::normalizePackageName($newPackageName);
        if ($newPackageName==='') {
            throw new RuntimeException('Geben Sie einen neuen Paketnamen ein.');
        }
        $source=self::resolveStoredPackagePath($file);
        $manifest=self::storedManifest($source);
        if (!self::manifestBelongsToProject($manifest,$project)) {
            throw new RuntimeException('Dieses Paket gehört nicht zum aktuellen Projekt.');
        }

        $oldFile=basename($source);
        $version=(string)($manifest['version']??'1.1.0');
        $stamp='';
        if (preg_match('/-(\d+\.\d+\.\d+)-(\d{8}_\d{6})\.dfpkg$/i',$oldFile,$m)) {
            $stamp=$m[2];
        }
        if ($stamp==='') {
            $created=strtotime((string)($manifest['createdAt']??''));
            $stamp=$created ? gmdate('Ymd_His',$created) : gmdate('Ymd_His');
        }
        $safe=self::packageFileBase($newPackageName);
        if ($safe==='') {
            $safe='dataform-project';
        }
        $target=self::uniquePackagePath($safe.'-'.$version.'-'.$stamp.'.dfpkg',$source);
        $temp=self::exportDirectory(true).'/.hf47-'.bin2hex(random_bytes(8)).'.dfpkg';
        if (!copy($source,$temp)) {
            throw new RuntimeException('Paketkopie für die Bearbeitung konnte nicht erzeugt werden.');
        }

        $zip=new ZipArchive();
        try {
            if ($zip->open($temp)!==true) {
                throw new RuntimeException('Gespeichertes Paket konnte nicht zur Bearbeitung geöffnet werden.');
            }
            $manifest['packageName']=$newPackageName;
            $manifest['packageNameMode']='custom';
            $manifest['updatedAt']=gmdate(DATE_ATOM);
            self::replaceZipString($zip,'manifest.json',self::json($manifest));

            $projectJson=[];
            $rawProject=$zip->getFromName('project.json');
            if (is_string($rawProject) && $rawProject!=='') {
                $decoded=json_decode($rawProject,true);
                if (is_array($decoded)) {
                    $projectJson=$decoded;
                }
            }
            $projectJson['packageName']=$newPackageName;
            self::replaceZipString($zip,'project.json',self::json($projectJson));

            $readme=(string)($zip->getFromName('README.txt')?:'');
            if ($readme!=='') {
                $replacement='Package name: '.$newPackageName;
                if (preg_match('/^Package name:.*$/mi',$readme)) {
                    $readme=(string)preg_replace('/^Package name:.*$/mi',$replacement,$readme,1);
                } else {
                    $readme=$replacement."\n".$readme;
                }
                self::replaceZipString($zip,'README.txt',$readme);
            }
            $zip->close();

            $sourceReal=realpath($source);
            $targetReal=realpath($target);
            $sameTarget=$sourceReal!==false && $targetReal!==false && $sourceReal===$targetReal;
            if ($sameTarget) {
                // Windows cannot reliably rename a file over an existing target.
                $backup=$source.'.hf47-backup-'.bin2hex(random_bytes(4));
                if (!rename($source,$backup)) {
                    throw new RuntimeException('Bestehendes Paket konnte für die Aktualisierung nicht vorbereitet werden.');
                }
                if (!rename($temp,$target)) {
                    @rename($backup,$source);
                    throw new RuntimeException('Bearbeitetes Paket konnte nicht gespeichert werden.');
                }
                @unlink($backup);
            } else {
                if (!rename($temp,$target)) {
                    throw new RuntimeException('Umbenanntes Paket konnte nicht gespeichert werden.');
                }
                if (is_file($source) && !unlink($source)) {
                    @unlink($target);
                    throw new RuntimeException('Der bisherige Paketstand konnte nach dem Umbenennen nicht entfernt werden.');
                }
            }
        } catch (Throwable $e) {
            if ($zip->status===ZipArchive::ER_OK) {
                @$zip->close();
            }
            @unlink($temp);
            throw $e;
        }

        $hash=(string)hash_file('sha256',$target);
        self::log(
            $pdo,$manifest,'update',basename($target),$hash,'success',
            ['renamedFrom'=>$oldFile,'packageName'=>$newPackageName],
            (int)($user['id']??0)
        );
        return ['filename'=>basename($target),'path'=>$target,'sha256'=>$hash,'manifest'=>$manifest];
    }

    /** HF49: DELETE by immutable package ID. */
    public static function deleteStoredPackageById(
        PDO $pdo,array $project,array $user,string $packageId
    ): void {
        $path=self::resolveStoredPackagePathById($project,$packageId);
        self::deleteStoredPackage($pdo,$project,$user,basename($path));
    }

    /** DELETE in package CRUD: removes the physical saved package, not its audit history. */
    public static function deleteStoredPackage(
        PDO $pdo,array $project,array $user,string $file
    ): void {
        self::ensureSchema($pdo);
        $path=self::resolveStoredPackagePath($file);
        $manifest=self::storedManifest($path);
        if (!self::manifestBelongsToProject($manifest,$project)) {
            throw new RuntimeException('Dieses Paket gehört nicht zum aktuellen Projekt.');
        }
        $hash=(string)hash_file('sha256',$path);
        $filename=basename($path);
        if (!unlink($path)) {
            throw new RuntimeException('Das gespeicherte Paket konnte nicht gelöscht werden.');
        }
        self::log(
            $pdo,$manifest,'delete',$filename,$hash,'success',
            ['deletedFile'=>$filename],(int)($user['id']??0)
        );
    }

    public static function history(PDO $pdo): array
    {
        self::ensureSchema($pdo);
        return $pdo->query('SELECT * FROM project_package_history ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function cleanup(array $preview): void
    {
        $dir=(string)($preview['temp_dir']??'');
        if ($dir && is_dir($dir)) {
            foreach (glob($dir.'/*')?:[] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private static function normalizeExportOptions(PDO $pdo,array|bool $options): array
    {
        if (is_bool($options)) {
            $ids=self::tableExists($pdo,'dataforms')
                ? array_map('intval',$pdo->query('SELECT id FROM dataforms ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))
                : [];
            return [
                'include_dataforms'=>true,
                'include_relations'=>true,
                'include_bindings'=>true,
                'include_workflow'=>true,
                'include_modules'=>true,
                'include_table_schema'=>false,
                'include_records'=>$options,
                'package_name'=>'',
                'dataform_ids'=>$ids,
                'base_tables'=>[],
            ];
        }
        $bool=static fn(string $key,bool $default=false): bool => array_key_exists($key,$options)
            ? (bool)$options[$key]
            : $default;
        $ids=[];
        foreach ((array)($options['dataform_ids']??[]) as $id) {
            $id=(int)$id;
            if ($id>0) {
                $ids[$id]=$id;
            }
        }
        $tables=[];
        foreach ((array)($options['base_tables']??[]) as $table) {
            $table=trim((string)$table);
            if ($table!=='' && preg_match('/^[a-zA-Z0-9_]+$/',$table)) {
                $tables[$table]=$table;
            }
        }
        return [
            'include_dataforms'=>$bool('include_dataforms',true),
            'include_relations'=>$bool('include_relations',true),
            'include_bindings'=>$bool('include_bindings',true),
            'include_workflow'=>$bool('include_workflow',true),
            'include_modules'=>$bool('include_modules',false),
            'include_table_schema'=>$bool('include_table_schema',true),
            'include_records'=>$bool('include_records',false),
            'package_name'=>self::normalizePackageName((string)($options['package_name']??'')),
            'dataform_ids'=>array_values($ids),
            'base_tables'=>array_values($tables),
        ];
    }

    private static function exportDirectory(bool $create): string
    {
        $dir=dirname(__DIR__,3).'/workspace/project-packages/exports';
        if ($create && !is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) {
            throw new RuntimeException('Exportverzeichnis konnte nicht erzeugt werden.');
        }
        return $dir;
    }

    private static function resolveStoredPackagePath(string $file): string
    {
        $file=trim($file);
        if ($file==='' || basename($file)!==$file || !preg_match('/^[A-Za-z0-9._-]+\.dfpkg$/i',$file)) {
            throw new RuntimeException('Ungültiger Paketdateiname.');
        }
        $dir=self::exportDirectory(false);
        $path=$dir.'/'.$file;
        if (!is_file($path)) {
            throw new RuntimeException('Das gespeicherte Paket wurde nicht gefunden.');
        }
        return $path;
    }

    private static function storedManifest(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung ext-zip ist nicht verfügbar.');
        }
        $zip=new ZipArchive();
        if ($zip->open($path)!==true) {
            throw new RuntimeException('Gespeichertes Paket ist kein gültiges ZIP-Archiv.');
        }
        try {
            $raw=$zip->getFromName('manifest.json');
            if (!is_string($raw) || $raw==='') {
                throw new RuntimeException('manifest.json fehlt im gespeicherten Paket.');
            }
            $manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($manifest)
                || ($manifest['format']??'')!=='easyit-dataform-project-package'
                || ($manifest['product']??'')!=='DataForm') {
                throw new RuntimeException('Ungültiges DataForm-Projektpaket.');
            }
            return $manifest;
        } finally {
            $zip->close();
        }
    }

    private static function manifestBelongsToProject(array $manifest,array $project): bool
    {
        $manifestDatabase=(string)($manifest['project']['database']??'');
        $projectDatabase=(string)($project['database_name']??'');
        if ($manifestDatabase!=='' && $projectDatabase!=='') {
            return hash_equals($projectDatabase,$manifestDatabase);
        }
        return (string)($manifest['project']['name']??'')===(string)($project['name']??'');
    }

    private static function uniquePackagePath(string $filename,string $source=''): string
    {
        $dir=self::exportDirectory(true);
        $candidate=$dir.'/'.$filename;
        if ($source!=='' && realpath($candidate)!==false && realpath($source)===realpath($candidate)) {
            return $candidate;
        }
        if (!file_exists($candidate)) {
            return $candidate;
        }
        $stem=preg_replace('/\.dfpkg$/i','',$filename)??$filename;
        for ($i=2;$i<1000;$i++) {
            $candidate=$dir.'/'.$stem.'-'.$i.'.dfpkg';
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Für den neuen Paketnamen konnte kein freier Dateiname erzeugt werden.');
    }

    private static function replaceZipString(ZipArchive $zip,string $name,string $content): void
    {
        if ($zip->locateName($name)!==false) {
            $zip->deleteName($name);
        }
        if (!$zip->addFromString($name,$content)) {
            throw new RuntimeException('Paketdatei konnte nicht aktualisiert werden: '.$name);
        }
    }

    private static function normalizePackageName(string $name): string
    {
        $name=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ', $name)??'');
        $name=preg_replace('/\s+/u',' ', $name)??$name;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($name,'UTF-8')>120) {
                $name=mb_substr($name,0,120,'UTF-8');
            }
        } elseif (strlen($name)>120) {
            $name=substr($name,0,120);
        }
        return trim($name);
    }

    private static function packageFileBase(string $name): string
    {
        $name=trim($name);
        if ($name==='') {
            return '';
        }
        if (function_exists('iconv')) {
            $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name);
            if (is_string($ascii) && $ascii!=='') {
                $name=$ascii;
            }
        }
        $name=preg_replace('/[^a-zA-Z0-9_-]+/','-',$name)??'';
        $name=trim(preg_replace('/-+/','-',$name)??$name,'-_');
        return substr($name,0,120);
    }

    /** @return array{index:array<string,array<string,mixed>>,files:array<string,string>} */
    private static function collectPackageMedia(PDO $pdo,array $dataformIds): array
    {
        $storage=new DataFormFieldStorageManager(dirname(__DIR__,3));
        $index=[]; $files=[];
        foreach ($dataformIds as $dataformId) {
            $fields=DataFormTransport::fields($pdo,(int)$dataformId);
            $mediaFields=array_values(array_filter($fields,static fn(array $f):bool=>DataFormFieldTypeRegistry::isMedia((string)$f['field_type'])));
            if ($mediaFields===[]) continue;
            foreach (DataFormRecordStore::all($pdo,(int)$dataformId) as $record) {
                foreach ($mediaFields as $field) {
                    $raw=(string)($record['data'][(string)$field['name']]??'');
                    if ($raw==='') continue;
                    $payload=$storage->payload($raw);
                    if ($payload===null) throw new RuntimeException('Ein referenziertes Medium ist nicht verfügbar oder beschädigt: DataForm #'.(int)$dataformId.', Datensatz #'.(int)$record['id'].'.');
                    $sha=strtolower((string)$payload['sha256']);
                    if (isset($index[$sha])) continue;
                    $entry='media/'.$sha.'.bin';
                    $index[$sha]=[
                        'entry'=>$entry,
                        'name'=>(string)$payload['name'],
                        'mime'=>(string)$payload['mime'],
                        'size'=>(int)$payload['size'],
                        'sha256'=>$sha,
                    ];
                    $files[$entry]=(string)$payload['bytes'];
                }
            }
        }
        ksort($index,SORT_STRING); ksort($files,SORT_STRING);
        return ['index'=>$index,'files'=>$files];
    }

    private static function collectDataformConfiguration(PDO $pdo,array $ids): array
    {
        $out=[];
        foreach (self::DATAFORM_CONFIG_TABLES as $table) {
            if (!self::tableExists($pdo,$table)) {
                continue;
            }
            if ($table==='dataforms') {
                $out[$table]=self::rowsByIds($pdo,$table,$ids);
            } else {
                $out[$table]=self::rowsByDataformIds($pdo,$table,$ids);
            }
        }
        return $out;
    }

    private static function collectWorkflowConfiguration(PDO $pdo,array $ids): array
    {
        $out=[];
        foreach (['workflow_states','workflow_transitions','workflow_permissions'] as $table) {
            if (self::tableExists($pdo,$table)) {
                $out[$table]=self::rowsByDataformIds($pdo,$table,$ids);
            }
        }
        if (self::tableExists($pdo,'workflow_actions') && isset($out['workflow_transitions'])) {
            $transitionIds=[];
            foreach ($out['workflow_transitions'] as $row) {
                $id=(int)($row['id']??0);
                if ($id>0) {
                    $transitionIds[]=$id;
                }
            }
            $out['workflow_actions']=self::rowsByColumnValues($pdo,'workflow_actions','transition_id',$transitionIds);
        }
        return $out;
    }

    private static function selectedRelationRows(PDO $pdo,array $ids): array
    {
        if ($ids===[] || !self::tableExists($pdo,'dataform_relations')) {
            return [];
        }
        $all=self::rowsByColumnValues($pdo,'dataform_relations','source_dataform_id',$ids);
        $selected=array_flip(array_map('intval',$ids));
        $out=[];
        foreach ($all as $row) {
            $cfg=self::decodeJson($row['configuration_json']??null);
            $base=((string)($cfg['lookup_source_kind']??'')==='base_table')
                || ((string)($cfg['semantics']??'')==='lookup-base-table-v1');
            $target=(int)($row['target_dataform_id']??0);
            if ($base || isset($selected[$target])) {
                $out[]=$row;
            }
        }
        return $out;
    }

    private static function dependencyTables(PDO $pdo,array $dataformIds,array $relations): array
    {
        $tables=[];
        if ($dataformIds!==[] && self::tableExists($pdo,'dataform_table_bindings')) {
            foreach (self::rowsByDataformIds($pdo,'dataform_table_bindings',$dataformIds) as $row) {
                if ((string)($row['source_kind']??'system')==='system') {
                    $table=trim((string)($row['table_name']??''));
                    if ($table!=='' && self::isExportableApplicationTable($table)) {
                        $tables[$table]=$table;
                    }
                }
            }
        }
        foreach ($relations as $row) {
            $cfg=self::decodeJson($row['configuration_json']??null);
            $base=is_array($cfg['base_table']??null)?$cfg['base_table']:[];
            $table=trim((string)($base['table']??''));
            if ($table!=='' && self::isExportableApplicationTable($table)) {
                $tables[$table]=$table;
            }
        }
        return array_values($tables);
    }

    private static function rowsByIds(PDO $pdo,string $table,array $ids): array
    {
        return self::rowsByColumnValues($pdo,$table,'id',$ids);
    }

    private static function rowsByDataformIds(PDO $pdo,string $table,array $ids): array
    {
        if (!self::columnExists($pdo,$table,'dataform_id')) {
            return [];
        }
        return self::rowsByColumnValues($pdo,$table,'dataform_id',$ids);
    }

    private static function rowsByColumnValues(PDO $pdo,string $table,string $column,array $values): array
    {
        if ($values===[]) {
            return [];
        }
        self::assertIdentifier($table);
        self::assertIdentifier($column);
        $placeholders=implode(',',array_fill(0,count($values),'?'));
        $sql='SELECT * FROM '.self::quoteIdentifier($table)
            .' WHERE '.self::quoteIdentifier($column).' IN ('.$placeholders.') ORDER BY id';
        $stmt=$pdo->prepare($sql);
        $stmt->execute(array_values($values));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function showCreateTable(PDO $pdo,string $table): string
    {
        self::assertExportablePhysicalTable($pdo,$table);
        $row=$pdo->query('SHOW CREATE TABLE '.self::quoteIdentifier($table))->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Tabellenschema konnte nicht gelesen werden: '.$table);
        }
        $sql=(string)(array_values($row)[1]??'');
        if ($sql==='') {
            throw new RuntimeException('Leeres Tabellenschema: '.$table);
        }
        $sql=preg_replace('/^CREATE TABLE\s+/i','CREATE TABLE IF NOT EXISTS ',$sql,1)??$sql;
        self::validateCreateTableSql($table,$sql);
        return $sql;
    }

    private static function validateCreateTableSql(string $table,string $sql): void
    {
        self::assertIdentifier($table);
        $clean=trim($sql);
        $clean=rtrim($clean,"; \t\r\n");
        if (str_contains($clean,';')) {
            throw new RuntimeException('Mehrere SQL-Anweisungen im Tabellenschema sind nicht erlaubt: '.$table);
        }
        $pattern='/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`'.preg_quote($table,'/').'`\s*\(/i';
        if (!preg_match($pattern,$clean)) {
            throw new RuntimeException('Ungültiges CREATE-TABLE-Schema für '.$table.'.');
        }
        if (preg_match('/\b(DROP|INSERT|DELETE|ALTER|TRUNCATE|GRANT|REVOKE)\b/i',$clean)) {
            throw new RuntimeException('Nicht erlaubte SQL-Anweisung im Tabellenschema: '.$table);
        }
    }

    private static function importRows(PDO $pdo,string $table,array $rows,string $policy): array
    {
        $counts=['inserted'=>0,'updated'=>0,'skipped'=>0];
        foreach ($rows as $row) {
            if (!is_array($row) || $row===[]) {
                continue;
            }
            $cols=[];
            foreach (array_keys($row) as $c) {
                if (preg_match('/^[a-zA-Z0-9_]+$/',(string)$c) && self::columnExists($pdo,$table,(string)$c)) {
                    $cols[]=(string)$c;
                }
            }
            if ($cols===[]) {
                continue;
            }
            $hasId=in_array('id',$cols,true) && isset($row['id']);
            $exists=$hasId && self::rowExists($pdo,$table,(string)$row['id']);
            if ($exists && $policy==='skip') {
                $counts['skipped']++;
                continue;
            }
            $payload=array_intersect_key($row,array_flip($cols));
            if ($exists) {
                $updates=array_values(array_filter($cols,static fn(string $c): bool=>$c!=='id'));
                if ($updates===[]) {
                    $counts['skipped']++;
                    continue;
                }
                $sql='UPDATE '.self::quoteIdentifier($table).' SET '
                    .implode(',',array_map(static fn(string $c): string=>'`'.$c.'`=:'.$c,$updates))
                    .' WHERE id=:id';
                $pdo->prepare($sql)->execute($payload);
                $counts['updated']++;
            } else {
                $quoted=array_map(static fn(string $c): string=>'`'.$c.'`',$cols);
                $params=array_map(static fn(string $c): string=>':'.$c,$cols);
                $sql='INSERT INTO '.self::quoteIdentifier($table)
                    .' ('.implode(',',$quoted).') VALUES ('.implode(',',$params).')';
                $pdo->prepare($sql)->execute($payload);
                $counts['inserted']++;
            }
        }
        return $counts;
    }

    private static function registerManagedTable(PDO $pdo,string $table): void
    {
        if (!self::tableExists($pdo,'dataform_managed_tables')) {
            return;
        }
        try {
            $stmt=$pdo->prepare('INSERT IGNORE INTO dataform_managed_tables(table_name) VALUES(?)');
            $stmt->execute([$table]);
        } catch (Throwable) {
            // Registry support is useful but never allowed to invalidate an
            // otherwise valid physical package import.
        }
    }

    private static function allConfigTables(): array
    {
        return array_values(array_unique(array_merge(
            self::DATAFORM_CONFIG_TABLES,
            self::RELATION_TABLES,
            self::BINDING_TABLES,
            self::WORKFLOW_TABLES,
            self::MODULE_TABLES,
            self::OPTIONAL_RECORD_TABLES
        )));
    }

    private static function isExportableApplicationTable(string $table): bool
    {
        $t=strtolower(trim($table));
        if ($t==='') {
            return false;
        }
        if (str_starts_with($t,'dataform_') || str_starts_with($t,'workflow_')) {
            return false;
        }
        return !in_array($t,[
            'dataforms','data_sources','migrations','project_package_history',
        ],true);
    }

    private static function assertExportablePhysicalTable(PDO $pdo,string $table): void
    {
        self::assertIdentifier($table);
        if (!self::isExportableApplicationTable($table) || !self::tableExists($pdo,$table)) {
            throw new RuntimeException('Die Basistabelle kann nicht exportiert werden: '.$table);
        }
    }

    private static function tableExists(PDO $pdo,string $table): bool
    {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private static function columnExists(PDO $pdo,string $table,string $column): bool
    {
        $stmt=$pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'
        );
        $stmt->execute([$table,$column]);
        return (bool)$stmt->fetchColumn();
    }

    private static function rowExists(PDO $pdo,string $table,string $id): bool
    {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM '.self::quoteIdentifier($table).' WHERE id=?');
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    private static function quoteIdentifier(string $identifier): string
    {
        self::assertIdentifier($identifier);
        return '`'.$identifier.'`';
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/',$identifier)) {
            throw new RuntimeException('Ungültiger SQL-Bezeichner.');
        }
    }

    private static function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value)==='') {
            return [];
        }
        try {
            $decoded=json_decode($value,true,512,JSON_THROW_ON_ERROR);
            return is_array($decoded)?$decoded:[];
        } catch (Throwable) {
            return [];
        }
    }

    private static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
        );
    }

    private static function log(
        PDO $pdo,array $manifest,string $action,string $file,string $hash,
        string $status,array $details,int $uid
    ): void {
        $stmt=$pdo->prepare(
            'INSERT INTO project_package_history('
            .'package_id,project_name,package_name,package_version,action_name,file_name,sha256,status,details_json,user_id'
            .') VALUES(?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (string)($manifest['packageId']??''),
            (string)($manifest['project']['name']??'Unbekannt'),
            (string)($manifest['packageName']??$manifest['project']['name']??'Unbekannt'),
            (string)($manifest['version']??'0.0.0'),
            $action,$file,$hash,$status,self::json($details),$uid?:null,
        ]);
    }
}
