<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void{$p='EasyIT\\Enterprise\\Licensing\\';if(!str_starts_with($class,$p))return;$f=__DIR__.'/'.str_replace('\\','/',substr($class,strlen($p))).'.php';if(is_file($f))require $f;});
