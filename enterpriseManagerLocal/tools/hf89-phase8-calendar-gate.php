<?php
declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];
$checks=[
 ['system/app/product_modules.php',"'calendar'=>["],
 ['app/projects/create.php','enterprise_available_project_products'],
 ['app/projects/register.php','enterprise_available_project_products'],
 ['products/calendar/index.php','easyit_calendar_project_id'],
];
foreach($checks as [$f,$needle]){$s=@file_get_contents($root.'/'.$f);if($s===false||!str_contains($s,$needle))$errors[]="$f: $needle fehlt";}
foreach(['app/projects/create.php','app/projects/register.php'] as $f){$s=file_get_contents($root.'/'.$f);if(str_contains($s,"product_type']!=='dataform")||str_contains($s,'nur DataForm'))$errors[]="$f enthält DataForm-Hardcodierung";}
if($errors){fwrite(STDERR,"HF89 Phase 8 Gate: FAIL\n- ".implode("\n- ",$errors)."\n");exit(1);}echo "HF89 Phase 8 Gate: PASS\n";
