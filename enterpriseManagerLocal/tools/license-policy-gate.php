<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];$c=function(string $name,bool $ok,string $detail='')use(&$checks):void{$checks[]=[$name,$ok,$detail];};
$records=(string)@file_get_contents($root.'/products/dataform/records.php');
$builder=(string)@file_get_contents($root.'/system/licensing/DataFormDefinitionBuilder.php');
$remote=(string)@file_get_contents($root.'/app/licensing/remote.php');
$c('runtime capability UI gate',str_contains($records,"licensedCapabilities")&&str_contains($records,"dataset.delete"));
$c('search/filter capability gate',str_contains($records,"dataform.search")&&str_contains($records,"dataform.filter"));
$c('relation capability gate',str_contains($records,"relation.read"));
$c('event capability gate',str_contains($records,"dataform.events"));
$c('definition emits CRUD caps',str_contains($builder,"dataset.create")&&str_contains($builder,"dataset.update")&&str_contains($builder,"dataset.delete"));
$c('definition emits UI caps',str_contains($builder,"dataform.search")&&str_contains($builder,"dataform.filter")&&str_contains($builder,"dataform.pagination"));
$c('license policy status visible',str_contains($remote,"Lizenzumfang")&&str_contains($remote,"max_dataforms"));
$forbidden=[
    $root.'/src/ProtectedCore/DataFormCompiler.php',
    $root.'/src/ProtectedCore/ActionResolver.php',
    $root.'/easyit-license-server/src/ProtectedCore/DataFormCompiler.php',
];
foreach($forbidden as $f)$c('protected server core absent: '.basename($f),!is_file($f));
$private=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f){if(!$f->isFile())continue;$n=strtolower($f->getFilename());if(str_contains($n,'private')&&str_ends_with($n,'.key'))$private[]=$f->getPathname();}
$c('server private key absent',$private===[],implode(',',$private));
$fail=0;foreach($checks as[$n,$ok,$d]){echo str_pad($n,52).($ok?'PASS':'FAIL').($d!==''?' '.$d:'').PHP_EOL;if(!$ok)$fail++;}echo 'FINAL STATUS: '.($fail?'FAILED':'PASSED').PHP_EOL;exit($fail?1:0);
