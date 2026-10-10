<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/products/dataform/system/DataFormRecordSetEventRepository.php';
return static function (PDO $pdo): void {
    DataFormRecordSetEventRepository::ensureSchema($pdo);
    $columns=[
        'view_mode'=>"TEXT NOT NULL DEFAULT 'table'",'default_per_page'=>"INTEGER NOT NULL DEFAULT 20",'show_search'=>"INTEGER NOT NULL DEFAULT 1",'show_pagination'=>"INTEGER NOT NULL DEFAULT 1",'allow_create'=>"INTEGER NOT NULL DEFAULT 1",'allow_edit'=>"INTEGER NOT NULL DEFAULT 1",'allow_delete'=>"INTEGER NOT NULL DEFAULT 1",'dialog_size'=>"TEXT NOT NULL DEFAULT 'large'",'css_class'=>"TEXT NULL",'additional_css'=>"TEXT NULL",'event_handlers_json'=>"TEXT NULL"
    ];
    $sqlite=class_exists('EnterpriseSqlitePdo',false) && $pdo instanceof EnterpriseSqlitePdo;
    if($sqlite){$existing=array_column($pdo->query("PRAGMA table_info('dataforms')")->fetchAll(PDO::FETCH_ASSOC),'name');foreach($columns as $name=>$def)if(!in_array($name,$existing,true))$pdo->exec('ALTER TABLE dataforms ADD COLUMN "'.$name.'" '.$def);return;}
    $mysql=['view_mode'=>"VARCHAR(20) NOT NULL DEFAULT 'table'",'default_per_page'=>"INT UNSIGNED NOT NULL DEFAULT 20",'show_search'=>"TINYINT(1) NOT NULL DEFAULT 1",'show_pagination'=>"TINYINT(1) NOT NULL DEFAULT 1",'allow_create'=>"TINYINT(1) NOT NULL DEFAULT 1",'allow_edit'=>"TINYINT(1) NOT NULL DEFAULT 1",'allow_delete'=>"TINYINT(1) NOT NULL DEFAULT 1",'dialog_size'=>"VARCHAR(20) NOT NULL DEFAULT 'large'",'css_class'=>"VARCHAR(160) NULL",'additional_css'=>"LONGTEXT NULL",'event_handlers_json'=>"LONGTEXT NULL"];
    $exists=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dataforms' AND COLUMN_NAME=?");foreach($mysql as $name=>$definition){$exists->execute([$name]);if((int)$exists->fetchColumn()===0)$pdo->exec('ALTER TABLE dataforms ADD COLUMN `'.$name.'` '.$definition);}
};
