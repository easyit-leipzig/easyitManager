<?php
declare(strict_types=1);
$path=dirname(__DIR__).'/src/Admin/AdminAuth.php';
$s=(string)file_get_contents($path);
$errors=[];
if (preg_match('/public function logout\(\): void\s*\{(?:(?!public function).)*\$this->user\(\)/s',$s)) $errors[]='logout() must not call user()';
if (!str_contains($s,"$u=\$_SESSION['admin_user']??null;")) $errors[]='logout() must read session identity directly';
if (!str_contains($s,'$this->logout();')) $errors[]='idle timeout logout missing';
if ($errors) { fwrite(STDERR,"HF94 Admin Auth Recursion Gate: FAIL\n- ".implode("\n- ",$errors)."\n"); exit(1); }
echo "HF94 Admin Auth Recursion Gate: PASS\n";
