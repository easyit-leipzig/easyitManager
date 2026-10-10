<?php
declare(strict_types=1);
$root=dirname(__DIR__);$php=PHP_BINARY;$args=[];foreach(array_slice($argv,1) as $a){if(str_starts_with($a,'--')&&str_contains($a,'=')){[$k,$v]=explode('=',substr($a,2),2);$args[$k]=$v;}}
$skipDb=(string)($args['skip-db']??'0')==='1';$ok=true;
$run=static function(array $cmd)use(&$ok):int{$line=implode(' ',array_map('escapeshellarg',$cmd));passthru($line,$code);if($code!==0)$ok=false;return $code;};
$gates=glob($root.'/gates/*.php')?:[];sort($gates);
foreach($gates as $gate){if(str_ends_with($gate,'HF86_PHASE12_DB_GATE.php'))continue;echo "\n=== ".basename($gate)." ===\n";$run([$php,$gate]);}
if($skipDb){echo "\nDB END-GATES: SKIPPED BY REQUEST\nFINAL STATUS: ".($ok?'STATIC PASS / DB NOT VERIFIED':'FAILED')."\n";exit($ok?2:1);}
$targets=[
 ['MariaDB/MySQL',(string)(getenv('HF86_MARIADB_DSN')?:''),(string)(getenv('HF86_MARIADB_USER')?:''),(string)(getenv('HF86_MARIADB_PASSWORD')?:'')],
 ['PostgreSQL',(string)(getenv('HF86_PGSQL_DSN')?:''),(string)(getenv('HF86_PGSQL_USER')?:''),(string)(getenv('HF86_PGSQL_PASSWORD')?:'')],
];
foreach($targets as [$label,$dsn,$user,$password]){
    echo "\n=== REAL DB END-GATE: {$label} ===\n";
    if($dsn===''){echo "BLOCKED {$label}: DSN environment variable missing\n";$ok=false;continue;}
    $run([$php,$root.'/gates/HF86_PHASE12_DB_GATE.php','--dsn='.$dsn,'--user='.$user,'--password='.$password,'--label='.$label]);
}
echo "\nHF86 FINAL STATUS: ".($ok?'PASSED':'FAILED/BLOCKED').PHP_EOL;exit($ok?0:1);
