<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/products/dataform/system/DerivedMultiEnumManager.php';
$src = file_get_contents($file);
if ($src === false) {
    fwrite(STDERR, "FAIL - DerivedMultiEnumManager.php fehlt\n");
    exit(1);
}

$checks = [
    'Metadaten-Schlüssel werden unabhängig von PDO::ATTR_CASE normalisiert' => str_contains($src, 'array_change_key_case($row, CASE_LOWER)'),
    'column_name ist gegen fehlende Keys abgesichert' => str_contains($src, "(\$row['column_name'] ?? '')"),
    'data_type ist gegen fehlende Keys abgesichert' => str_contains($src, "(\$row['data_type'] ?? '')"),
    'column_type ist gegen fehlende Keys abgesichert' => str_contains($src, "(\$row['column_type'] ?? '')"),
    'is_nullable ist gegen fehlende Keys abgesichert' => str_contains($src, "(\$row['is_nullable'] ?? 'NO')"),
    'column_key ist gegen fehlende Keys abgesichert' => str_contains($src, "(\$row['column_key'] ?? '')"),
    'extra ist gegen fehlende Keys abgesichert' => str_contains($src, "(\$row['extra'] ?? '')"),
];

$ok = true;
$passed = 0;
foreach ($checks as $label => $pass) {
    echo ($pass ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if ($pass) { $passed++; }
    $ok = $ok && $pass;
}

echo 'HF146 DerivedMultiEnum metadata case gate: ' . ($ok ? 'PASS' : 'FAIL') . ' (' . $passed . '/' . count($checks) . ')' . PHP_EOL;
exit($ok ? 0 : 1);
