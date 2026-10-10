<?php
declare(strict_types=1);
$root=dirname(__DIR__);$php=PHP_BINARY;$ok=true;
$files=[
    $root.'/tools/hf86-final-gate.php',
    $root.'/tools/hf87-phase1-gate.php',
    $root.'/tools/hf87-phase2-license-customerkey-gate.php',
    $root.'/tools/hf87-phase3-license-request-gate.php',
    $root.'/tools/hf87-phase4-project-product-gate.php',
    $root.'/tools/hf87-phase41-customerkey-schema-order-gate.php',
    $root.'/tools/hf87-phase5-db-engine-gate.php',
];
foreach($files as $file){
    if(!is_file($file)){echo 'FAIL missing '.basename($file).PHP_EOL;$ok=false;continue;}
    echo "\n=== ".basename($file)." ===\n";
    passthru(escapeshellarg($php).' '.escapeshellarg($file),$code);
    if($code!==0)$ok=false;
}
echo "\nHF87 ENTERPRISE FINAL STATIC STATUS: ".($ok?'PASSED':'FAILED').PHP_EOL;
exit($ok?0:1);
