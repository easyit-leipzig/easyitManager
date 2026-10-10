<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
$index=__DIR__.'/index.php';
$runtime=__DIR__.'/runtime.php';
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><title>HF17 Runtime Proof</title>
<style>
body{margin:0;padding:40px;font:16px/1.5 system-ui;background:#eef2f7;color:#10213b}
.card{max-width:1000px;margin:auto;background:#eafff1;border:2px solid #087443;border-radius:14px;padding:24px}
code{background:#fff;padding:2px 6px}.ok{color:#087443;font-weight:800}.test{display:inline-block;margin-top:16px;padding:10px 14px;background:#153e75;color:#fff;text-decoration:none;border-radius:8px}
</style></head><body><div class="card">
<h1>HF36 DATAFORM RUNTIME PROOF</h1>
<p class="ok">Apache/PHP liefert den HF36-Pfad aus.</p>
<dl>
<dt>__FILE__</dt><dd><code><?=htmlspecialchars(__FILE__)?></code></dd>
<dt>DOCUMENT_ROOT</dt><dd><code><?=htmlspecialchars((string)($_SERVER['DOCUMENT_ROOT']??''))?></code></dd>
<dt>SCRIPT_NAME</dt><dd><code><?=htmlspecialchars((string)($_SERVER['SCRIPT_NAME']??''))?></code></dd>
<dt>index.php SHA-256</dt><dd><code><?=is_file($index)?hash_file('sha256',$index):'MISSING'?></code></dd>
<dt>runtime.php realpath</dt><dd><code><?=htmlspecialchars((string)realpath($runtime))?></code></dd>
<dt>runtime.php SHA-256</dt><dd><code><?=is_file($runtime)?hash_file('sha256',$runtime):'MISSING'?></code></dd>
<dt>.htaccess</dt><dd><code><?=is_file(__DIR__.'/.htaccess')?'PRESENT':'MISSING'?></code></dd>
</dl>
<a class="test" href="runtime.php">runtime.php direkt öffnen</a>
</div></body></html>
