<?php
declare(strict_types=1);
return static function (PDO $pdo): void {
    $sqlite=class_exists('EnterpriseSqlitePdo',false) && $pdo instanceof EnterpriseSqlitePdo;
    if($sqlite){$existing=array_column($pdo->query("PRAGMA table_info('dataforms')")->fetchAll(PDO::FETCH_ASSOC),'name');if(!in_array('show_search',$existing,true))$pdo->exec('ALTER TABLE dataforms ADD COLUMN show_search INTEGER NOT NULL DEFAULT 1');if(!in_array('show_filter',$existing,true))$pdo->exec('ALTER TABLE dataforms ADD COLUMN show_filter INTEGER NOT NULL DEFAULT 1');return;}
    $exists=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dataforms' AND COLUMN_NAME=?");$exists->execute(['show_search']);if((int)$exists->fetchColumn()===0)$pdo->exec("ALTER TABLE dataforms ADD COLUMN show_search TINYINT(1) NOT NULL DEFAULT 1 AFTER default_per_page");$exists->execute(['show_filter']);if((int)$exists->fetchColumn()===0)$pdo->exec("ALTER TABLE dataforms ADD COLUMN show_filter TINYINT(1) NOT NULL DEFAULT 1 AFTER show_search");
};
