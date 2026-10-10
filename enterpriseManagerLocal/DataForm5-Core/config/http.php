<?php
declare(strict_types=1);
return ['base_url'=>getenv('APP_URL')?:'http://localhost','trusted_proxies'=>[],'debug'=>filter_var(getenv('APP_DEBUG')?:'0',FILTER_VALIDATE_BOOL)];
