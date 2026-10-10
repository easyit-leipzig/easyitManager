<?php
declare(strict_types=1);
use DataForm5\Core\Configuration\Env;
return [
 'locale'=>Env::get('APP_LOCALE','de_DE'),
 'fallback_locale'=>Env::get('APP_FALLBACK_LOCALE','en_US'),
 'path'=>dirname(__DIR__).'/resources/lang',
];
