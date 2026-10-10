<?php
declare(strict_types=1);
use DataForm5\Core\Configuration\Env;
return ['enabled'=>Env::get('HEALTH_ENABLED',true),'storage_path'=>Env::get('HEALTH_STORAGE_PATH','storage')];
