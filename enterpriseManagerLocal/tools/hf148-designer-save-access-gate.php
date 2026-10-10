<?php
$root=dirname(__DIR__);
$css=file_get_contents($root.'/products/dataform/assets/workspace.css');
$runtime=file_get_contents($root.'/products/dataform/runtime.php');
$checks=[
 'Inspector viewport max-height'=>str_contains($css,'max-height:calc(100vh - 2rem)'),
 'Inspector vertical scrolling'=>str_contains($css,'overflow-y:auto'),
 'Sticky save CSS'=>str_contains($css,'.df-field-save{position:sticky;bottom:0'),
 'Save button class'=>str_contains($runtime,'class="button df-field-save" type="submit">Eigenschaften speichern</button>'),
];
$ok=true;
foreach($checks as $name=>$pass){echo $name.': '.($pass?'PASS':'FAIL').PHP_EOL;$ok=$ok&&$pass;}
exit($ok?0:1);
