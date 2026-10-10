<?php
declare(strict_types=1);
namespace DataForm5\Mail\Core;
use DataForm5\Mail\Exceptions\MailException;
final class TemplateRenderer
{
 public function render(string $file,array $data=[]):string { if(!is_file($file)) throw new MailException("Mail-Template nicht gefunden: {$file}"); extract($data,EXTR_SKIP); ob_start(); try{require $file; return (string)ob_get_clean();}catch(\Throwable $e){ob_end_clean();throw $e;} }
}
