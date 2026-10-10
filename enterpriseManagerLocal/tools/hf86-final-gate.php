<?php
declare(strict_types=1);
$root=dirname(__DIR__);$php=PHP_BINARY;$ok=true;
$files=array_merge(glob($root.'/tools/hf85-phase*-gate.php')?:[],glob($root.'/tools/hf86-phase*-gate.php')?:[],[$root.'/tools/license-policy-gate.php']);
$files=array_values(array_unique(array_filter($files,'is_file')));sort($files);
foreach($files as $file){if(realpath($file)===realpath(__FILE__))continue;echo "\n=== ".basename($file)." ===\n";$cmd=escapeshellarg($php).' '.escapeshellarg($file);passthru($cmd,$code);if($code!==0)$ok=false;}
echo "\nHF86 ENTERPRISE FINAL STATIC STATUS: ".($ok?'PASSED':'FAILED').PHP_EOL;exit($ok?0:1);
