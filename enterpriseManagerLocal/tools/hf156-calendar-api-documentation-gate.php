<?php
$root=dirname(__DIR__);
$checks=[];
function ck(string $name,bool $ok,string $detail=''):void{global $checks;$checks[]=[$name,$ok?'PASS':'FAIL',$detail];}
$files=[
 $root.'/products/calendar/docs/API.md',
 $root.'/products/calendar/docs/API_DOKUMENTATION.html',
 $root.'/system/app/calendar_runtime/docs/API.md',
 $root.'/system/app/calendar_runtime/docs/API.html',
];
foreach($files as $f){$c=is_file($f)?file_get_contents($f):'';ck('DOC_'.basename(dirname($f)).'_'.basename($f),$c!=='' && str_contains($c,'HF156') && str_contains($c,'render(filterObjekt)') && str_contains($c,'TinyCalendar.render(null)') && str_contains($c,'getFilter') && str_contains($c,'person_id'),$f);}
$examples=['render-all.js','render-filter.js','get-filter.js'];
foreach(['products/calendar/docs/examples/javascript','system/app/calendar_runtime/docs/examples/javascript'] as $base){foreach($examples as $e){$f=$root.'/'.$base.'/'.$e;ck('EXAMPLE_'.$e,is_file($f) && filesize($f)>20,$f);}}
$runtime=$root.'/products/calendar/assets/js/calendar.js';$c=is_file($runtime)?file_get_contents($runtime):'';ck('RUNTIME_RENDER',$c!=='' && str_contains($c,'render: function (filterObject)') && str_contains($c,'getFilter: function ()'));
$fail=0;foreach($checks as [$n,$r,$d]){echo $n."\t".$r.($d!==''?"\t".$d:'').PHP_EOL;if($r!=='PASS')$fail++;}
echo 'SUMMARY' . "\t" . ($fail===0?'PASS':'FAIL') . "\t" . (count($checks)-$fail).'/'.count($checks).PHP_EOL;
exit($fail===0?0:1);
