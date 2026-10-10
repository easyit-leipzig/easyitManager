<?php
declare(strict_types=1);
return [
 'default'=>getenv('LOG_CHANNEL') ?: 'app',
 'channels'=>[
  'app'=>['driver'=>'file','path'=>'storage/logs/app.log','level'=>getenv('LOG_LEVEL') ?: 'debug','max_bytes'=>(int)(getenv('LOG_MAX_BYTES') ?: 5242880),'retained_files'=>(int)(getenv('LOG_RETAINED_FILES') ?: 5)],
  'security'=>['driver'=>'file','path'=>'storage/logs/security.log','level'=>'notice','max_bytes'=>5242880,'retained_files'=>10],
  'null'=>['driver'=>'null'],
 ],
];
