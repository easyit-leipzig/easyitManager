<?php
declare(strict_types=1);
$target=rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/setup.php'))),'/').'/setup/';
header('Location: '.$target, true, 302);
exit;
