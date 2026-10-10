<?php
declare(strict_types=1);

namespace DataForm5\Installer\Core;

use PDO;

final class InstallationHealthGate
{
    public function check(string $basePath,?PDO $pdo=null): array
    {
        $checks=[];
        $checks['php']=['ok'=>version_compare(PHP_VERSION,'8.2.0','>='),'detail'=>PHP_VERSION];
        foreach(['json','pdo','openssl'] as $ext)$checks['ext_'.$ext]=['ok'=>extension_loaded($ext),'detail'=>$ext];
        $checks['storage']=['ok'=>is_dir($basePath.'/storage')&&is_writable($basePath.'/storage'),'detail'=>$basePath.'/storage'];
        $checks['env']=['ok'=>is_file($basePath.'/.env')&&is_readable($basePath.'/.env'),'detail'=>'.env'];
        if($pdo){
            try{
                $pdo->query('SELECT 1');
                $tables=['users','roles','user_roles','installed_products'];
                $missing=[];
                foreach($tables as $table){
                    $st=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                    $st->execute([$table]);
                    if((int)$st->fetchColumn()===0)$missing[]=$table;
                }
                $checks['database']=['ok'=>$missing===[],'detail'=>$missing===[]?'schema ready':'missing: '.implode(',',$missing)];
            }catch(\Throwable $e){
                $checks['database']=['ok'=>false,'detail'=>$e->getMessage()];
            }
        }else{
            $checks['database']=['ok'=>false,'detail'=>'not connected'];
        }
        $ready=!in_array(false,array_map(static fn(array $c):bool=>(bool)$c['ok'],$checks),true);
        return ['ready'=>$ready,'checks'=>$checks,'checked_at'=>date(DATE_ATOM)];
    }
}
