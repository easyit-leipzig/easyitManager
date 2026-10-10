<?php
declare(strict_types=1);
return [
 'defaults'=>['enabled'=>false],
 'flags'=>[
   'core.context_help'=>['enabled'=>true,'environments'=>['development','testing','production'],'percentage'=>100],
   'core.experimental_api'=>['enabled'=>false,'environments'=>['development','testing'],'percentage'=>0],
 ],
];
