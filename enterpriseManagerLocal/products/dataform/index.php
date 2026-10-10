<?php
declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'runtime.php' . ($query !== '' ? '?' . $query : '');
header('X-EasyIT-DataForm-Compatibility-Entry: HF17');
header('Location: ' . $target, true, 302);
exit;
