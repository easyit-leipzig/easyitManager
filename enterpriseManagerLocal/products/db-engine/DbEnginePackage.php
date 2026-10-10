<?php
declare(strict_types=1);

function easyit_db_engine_export_root(): string { return dirname(__DIR__,2).'/storage/db-engine-packages'; }
function easyit_db_engine_slug(string $v): string { $v=strtolower(trim($v));$v=preg_replace('/[^a-z0-9_-]+/','-',$v)??'';return trim($v,'-_')?:'project'; }
function easyit_db_engine_add(ZipArchive $z,string $src,string $dst): void { if(!is_file($src)||!$z->addFile($src,$dst))throw new RuntimeException('DB-Engine-Datei fehlt: '.$dst); }
function easyit_db_engine_text(ZipArchive $z,string $dst,string $txt): void { if(!$z->addFromString($dst,$txt))throw new RuntimeException('DB-Engine-Datei konnte nicht erzeugt werden: '.$dst); }

function easyit_db_engine_create_package(array $project,array $licenseToken): array {
 if(!class_exists('ZipArchive'))throw new RuntimeException('Für den DB-Engine-Export wird ext-zip benötigt.');
 $projectId=(int)($project['id']??0);if($projectId<1)throw new RuntimeException('Ungültiges Projekt.');
 $root=easyit_db_engine_export_root();if(!is_dir($root)&&!mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Exportverzeichnis konnte nicht angelegt werden.');
 $app=dirname(__DIR__,2);$slug=easyit_db_engine_slug((string)($project['slug']??$project['name']??'project'));$folder='easyIT-DB-Engine-'.$slug;$file=$folder.'-'.gmdate('Ymd_His').'.zip';$path=$root.'/'.$file;
 $z=new ZipArchive();if($z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('DB-Engine-ZIP konnte nicht erzeugt werden.');
 try{
  foreach(['EnterpriseCsvPdo.php','EnterpriseSqlitePdo.php','EnterprisePgsqlPdo.php','EnterpriseOraclePdo.php','EnterpriseMssqlAdapter.php','EnterpriseMssqlPdo.php'] as $f)easyit_db_engine_add($z,$app.'/system/app/'.$f,$folder.'/lib/'.$f);
  easyit_db_engine_add($z,__DIR__.'/runtime/LicenseGate.php',$folder.'/lib/LicenseGate.php');
  easyit_db_engine_add($z,__DIR__.'/runtime/DbEngine.php',$folder.'/lib/DbEngine.php');
  easyit_db_engine_add($z,__DIR__.'/runtime/bootstrap.php',$folder.'/bootstrap.php');
  easyit_db_engine_add($z,__DIR__.'/runtime/setup.php',$folder.'/setup.php');
  easyit_db_engine_add($z,__DIR__.'/runtime/index.php',$folder.'/index.php');
  easyit_db_engine_text($z,$folder.'/license/project-product-token.json',json_encode($licenseToken,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  easyit_db_engine_text($z,$folder.'/config.php.example',"<?php\ndeclare(strict_types=1);\nreturn ['driver'=>'sqlite','sqlite_path'=>__DIR__.'/data/project.sqlite','csv_path'=>__DIR__.'/data/csv','host'=>'127.0.0.1','port'=>3306,'database'=>'project','schema'=>'public','service'=>'XEPDB1','user'=>'','password'=>'','encrypt'=>true,'trust_server_certificate'=>false,'project_id'=>'".$projectId."','project_slug'=>'".addslashes((string)($project['slug']??''))."'];\n");
  easyit_db_engine_text($z,$folder.'/.htaccess',"Options -Indexes\n<FilesMatch \"^(config\\.php|project-product-token\\.json)$\">\nRequire all denied\n</FilesMatch>\n");
  easyit_db_engine_text($z,$folder.'/README.md',"# easyIT DB-Engine\n\nEigenständiges exportiertes DB-Engine-Projekt für lokale Kundendaten.\n\nUnterstützte Treiber: CSV, SQLite, MySQL/MariaDB, PostgreSQL, Oracle XE, MSSQL.\n\n1. Paket entpacken.\n2. `setup.php` öffnen oder `config.php.example` nach `config.php` kopieren.\n3. Datenbanktyp und lokale Zugangsdaten konfigurieren.\n4. DataForm-Runtime über `db_engine_path` auf dieses Verzeichnis verweisen.\n\nDie Nutzung ist projektbezogen lizenziert. `license/project-product-token.json` wird bei jeder Verbindung geprüft.\n");
  easyit_db_engine_text($z,$folder.'/VERSION',"RC1.8-FC1-HF87-PHASE5-DB-ENGINE-LOCAL-DATA\n");
  easyit_db_engine_text($z,$folder.'/data/.htaccess',"Require all denied\nDeny from all\n");
 }finally{$z->close();}
 return ['path'=>$path,'filename'=>$file,'sha256'=>hash_file('sha256',$path),'size'=>(int)filesize($path)];
}
