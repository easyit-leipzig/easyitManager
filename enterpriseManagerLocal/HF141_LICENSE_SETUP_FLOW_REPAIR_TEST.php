<?php
declare(strict_types=1);
$f=file_get_contents(__DIR__.'/setup.php');
$checks=[
 'canonical rebuild'=>str_contains($f,"return \$scheme.'://'.\$host.\$port.\$path"),
 'backslash normalization'=>str_contains($f,"preg_replace('~\\\\\\\\+~','/',\$url)"),
 'setup.php target'=>str_contains($f,"'setup_url'=>\$url.'/setup.php'"),
 'setup required guidance'=>str_contains($f,'Lizenzserver jetzt einrichten')&&str_contains($f,'Status neu prüfen'),
 'ready gate remains'=>str_contains($f,'Abschluss gesperrt – Lizenzserver meldet noch SETUP_REQUIRED'),
];
foreach($checks as $n=>$ok){echo ($ok?'PASS':'FAIL')." - $n\n"; if(!$ok)exit(1);} 
