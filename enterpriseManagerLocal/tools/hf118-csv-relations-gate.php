<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/system/app/EnterpriseCsvPdo.php';
require_once dirname(__DIR__).'/products/dataform/system/DataFormFieldTypes.php';
require_once dirname(__DIR__).'/products/dataform/system/DataFormManager.php';
require_once dirname(__DIR__).'/products/dataform/system/RelationManager.php';

$root=sys_get_temp_dir().'/easyit_hf118_'.bin2hex(random_bytes(4));
@mkdir($root,0777,true);
$pdo=new EnterpriseCsvPdo($root,'calendar_relations');
$checks=[];
$ck=function(bool $ok,string $label)use(&$checks):void{$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";};

$pdo->exec("CREATE TABLE IF NOT EXISTS dataforms (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190), slug VARCHAR(190))");
$pdo->exec("CREATE TABLE IF NOT EXISTS dataform_fields (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, dataform_id BIGINT UNSIGNED, name VARCHAR(190), label VARCHAR(190), field_type VARCHAR(40), position INT, is_required TINYINT, configuration_json LONGTEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, thema VARCHAR(190))");

$q=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
$tables=$q->fetchAll(PDO::FETCH_COLUMN);
$ck(in_array('dataform_fields',$tables,true)&&in_array('events',$tables,true),'information_schema.TABLES liefert echte CSV-Tabellennamen');
$ck(!in_array('0',$tables,true),'information_schema.TABLES liefert niemals den Pseudo-Bezeichner 0');

$q=$pdo->query("SELECT TABLE_NAME,TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
$meta=$q->fetchAll(PDO::FETCH_ASSOC);
$ck(count($meta)>=3 && isset($meta[0]['TABLE_NAME']) && array_key_exists('TABLE_ROWS',$meta[0]),'TABLE_NAME/TABLE_ROWS-Metadaten werden emuliert');

$st=$pdo->prepare('SELECT table_type FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
$st->execute(['events']);
$ck(strtoupper((string)$st->fetchColumn())==='BASE TABLE','table_type-Abfrage funktioniert für CSV');

$ins=$pdo->prepare('INSERT INTO dataform_fields (dataform_id,name,label,field_type,position,is_required,configuration_json) VALUES (?,?,?,?,?,?,?)');
$ins->execute([1,'a','A','text',10,0,'{}']);
$ins->execute([1,'b','B','text',20,0,'{}']);
$pos=$pdo->prepare('SELECT COALESCE(MAX(position),0)+10 FROM dataform_fields WHERE dataform_id=?');
$pos->execute([1]);
$ck((int)$pos->fetchColumn()===30,'COALESCE(MAX(position),0)+10 liefert 30');
$pos=$pdo->prepare('SELECT COALESCE(MAX(position), 0) + 10 FROM dataform_fields WHERE dataform_id=?');
$pos->execute([2]);
$ck((int)$pos->fetchColumn()===10,'COALESCE/MAX auf leerer Feldmenge liefert 10');


// Functional reproduction of the relations page startup on a table-bound CSV DataForm.
$pdo->exec("CREATE TABLE IF NOT EXISTS dataform_table_bindings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, dataform_id BIGINT UNSIGNED, source_kind VARCHAR(30), source_id BIGINT UNSIGNED, table_name VARCHAR(190), binding_mode VARCHAR(30), created_at DATETIME)");
$pdo->exec("CREATE TABLE IF NOT EXISTS dataform_relations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190), relation_type VARCHAR(10), source_dataform_id BIGINT UNSIGNED, source_field_id BIGINT UNSIGNED, target_dataform_id BIGINT UNSIGNED, target_display_field_id BIGINT UNSIGNED, junction_name VARCHAR(190), lookup_field_id BIGINT UNSIGNED, is_required TINYINT, is_enabled TINYINT, configuration_json LONGTEXT, created_at DATETIME, updated_at DATETIME)");
$pdo->prepare('INSERT INTO dataforms (name,slug) VALUES (?,?)')->execute(['Events','events']);
$dfId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO dataform_table_bindings (dataform_id,source_kind,source_id,table_name,binding_mode) VALUES (?,?,?,?,?)')->execute([$dfId,'system',0,'events','schema']);
$sync=DataFormManager::synchronizeAllBoundTableFields($pdo);
$warnings=implode(' | ',(array)($sync['warnings']??[]));
$ck(!str_contains($warnings,'Ungültiger CSV-SQL-Bezeichner')&&!str_contains($warnings,'COALESCE(MAX(position)'), 'DataForm-Synchronisierung erzeugt keine CSV-Identifier-/Positionswarnung');
$base=RelationManager::selectableBaseTables($pdo);
$ck(in_array('events',$base,true),'Relationsdesigner erkennt events als CSV-Basistabelle');
$cols=RelationManager::selectableBaseTableColumns($pdo,'events');
$ck(count($cols)>0,'Relationsdesigner kann CSV-Basistabellenspalten lesen');

$bad=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$bad->execute(['0']);
$ck((int)$bad->fetchColumn()===0,'ungültiger Introspektionswert 0 wird als nicht vorhandene Tabelle behandelt');

$failed=array_values(array_filter($checks,fn($x)=>!$x[0]));
echo "HF118 CSV relations gate: ".(count($failed)===0?'PASS':'FAIL')." (".(count($checks)-count($failed)).'/'.count($checks).")\n";
exit(count($failed)===0?0:1);
