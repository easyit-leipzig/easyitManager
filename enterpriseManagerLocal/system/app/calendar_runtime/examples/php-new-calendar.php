<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$calendar = new Calendar();
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>easyITCalendar PHP-Beispiel</title>
</head>
<body>
<?php $calendar->render(); ?>
</body>
</html>
