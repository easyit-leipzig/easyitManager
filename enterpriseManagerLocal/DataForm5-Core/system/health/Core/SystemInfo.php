<?php
declare(strict_types=1);
namespace DataForm5\Health\Core;
final class SystemInfo
{
    public function collect():array{return ['php_version'=>PHP_VERSION,'php_sapi'=>PHP_SAPI,'os'=>PHP_OS_FAMILY,'architecture'=>PHP_INT_SIZE*8,'memory_limit'=>(string)ini_get('memory_limit'),'extensions'=>['pdo'=>extension_loaded('pdo'),'zip'=>extension_loaded('zip'),'intl'=>extension_loaded('intl'),'mbstring'=>extension_loaded('mbstring')],'time_utc'=>gmdate(DATE_ATOM)];}
}
