<?php
declare(strict_types=1);
$root=__DIR__;
$checks=[];
$runtime=file_get_contents($root.'/products/dataform/runtime.php');
$records=file_get_contents($root.'/products/dataform/records.php');
$bridge=file_get_contents($root.'/products/calendar/system/CalendarDataFormBridge.php');
$checks['designer_persists_target']=str_contains($runtime,"lookup_target_dataform_id']=(int)");
$checks['designer_persists_key']=str_contains($runtime,"lookup_target_key_field_id']=(int)");
$checks['designer_persists_display']=str_contains($runtime,"lookup_target_display_field_id']=(int)");
$checks['runtime_config_fallback']=str_contains($records,'field_config_lookup_');
$checks['runtime_builds_options']=str_contains($records,'RelationManager::dataformLookupOptions($pdo,$runtimeRelation)');
$checks['calendar_resource_lookup']=str_contains($bridge,"'resource'=>['label'=>'Ressource','type'=>'lookup'");
$checks['calendar_category_lookup']=str_contains($bridge,"'category'=>['label'=>'Kategorie','type'=>'lookup'");
$checks['calendar_block_category_lookup']=str_contains($bridge,"'category_id'=>['label'=>'Kategorie','type'=>'lookup'");
$ok=true;foreach($checks as $n=>$v){echo $n.': '.($v?'PASS':'FAIL').PHP_EOL;$ok=$ok&&$v;}
echo 'HF149: '.($ok?'PASS':'FAIL').' '.count(array_filter($checks)).'/'.count($checks).PHP_EOL;exit($ok?0:1);
