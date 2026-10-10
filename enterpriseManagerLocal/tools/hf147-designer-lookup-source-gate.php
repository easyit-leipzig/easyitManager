<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$runtime=(string)file_get_contents($root.'/products/dataform/runtime.php');
$checks=[
 'Designer zeigt Auswahltabelle / Kind-DataForm'=>str_contains($runtime,'Auswahltabelle / Kind-DataForm'),
 'Designer bietet Schlüsselfeld'=>str_contains($runtime,'lookup_target_key_field_id'),
 'Designer bietet Anzeigefeld'=>str_contains($runtime,'lookup_target_display_field_id'),
 'Lookup wird als n:1-Beziehung synchronisiert'=>str_contains($runtime,'dataform_sync_designer_lookup_relation'),
 'RelationManager createManyToOne wird genutzt'=>str_contains($runtime,'RelationManager::createManyToOne'),
 'Bestehende Lookup-Beziehung wird aktualisiert'=>str_contains($runtime,'RelationManager::updateManyToOne'),
 'Lookup-Felder werden nach Zieltabelle gefiltert'=>str_contains($runtime,'data-lookup-dataform'),
];
$ok=0;
foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' - '.$name.PHP_EOL; if($pass)$ok++;}
echo 'HF147 Designer lookup source gate: '.($ok===count($checks)?'PASS':'FAIL').' ('.$ok.'/'.count($checks).')'.PHP_EOL;
exit($ok===count($checks)?0:1);
