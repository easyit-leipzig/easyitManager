<?php
declare(strict_types=1);
return ['default'=>getenv('MAIL_MAILER')?:'log','from'=>['address'=>getenv('MAIL_FROM_ADDRESS')?:'noreply@example.test','name'=>getenv('MAIL_FROM_NAME')?:'DataForm5 Core']];
