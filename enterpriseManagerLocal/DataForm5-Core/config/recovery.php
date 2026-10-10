<?php
declare(strict_types=1);
return ['driver'=>'file','path'=>getenv('RECOVERY_PATH') ?: 'storage/recovery','retain'=>(int)(getenv('RECOVERY_RETAIN') ?: 10)];
