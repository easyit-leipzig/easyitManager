<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
 'signer'=>file_get_contents($root.'/src/Security/ServerSigner.php'),
 'responder'=>file_get_contents($root.'/src/Http/ApiResponder.php'),
 'verifier'=>file_get_contents($root.'/src/Security/RequestVerifier.php'),
 'migration'=>file_get_contents($root.'/src/Database/MigrationRunner.php'),
 'token'=>file_get_contents($root.'/src/Service/DataFormTokenService.php'),
 'cli'=>file_get_contents($root.'/bin/server-key-rotation.php'),
 'app'=>file_get_contents($root.'/src/App/Application.php'),
 'doc'=>file_get_contents($root.'/docs/HF86_PHASE11.md'),
];
$ok=true;$c=function(string $label,bool $pass)use(&$ok):void{echo($pass?'PASS ':'FAIL ').$label.PHP_EOL;if(!$pass)$ok=false;};
$c('server keyring is optional/backward compatible',str_contains($files['signer'],'server-keyring')===false && str_contains($files['app'],'server-keyring.json'));
$c('signer supports active staged retiring revoked',str_contains($files['signer'],"['active','staged','retiring','revoked']"));
$c('response exposes signed trust bundle',str_contains($files['responder'],"'server_trust'")&&str_contains($files['responder'],'trustBundle()'));
$c('response supports retiring alternate signature',str_contains($files['responder'],'X-EasyIT-Server-Alt-Key-ID')&&str_contains($files['signer'],'alternateSignatures'));
$c('installation trust acknowledgement schema exists',str_contains($files['migration'],'installation_server_key_trust'));
$c('request records trusted server keys',str_contains($files['verifier'],'x-easyit-trusted-server-keys')&&str_contains($files['verifier'],'recordTrustedServerKeys'));
$c('dataform token issuance key is tracked',str_contains($files['migration'],'token_last_issued_key_id')&&str_contains($files['token'],'token_last_issued_key_id'));
$c('retiring token key remains verifiable',str_contains($files['token'],'verifyByKeyId')&&str_contains($files['signer'],"['active','retiring']"));
$c('rotation prepare command exists',str_contains($files['cli'],'$cmd===\'prepare\''));
$c('activation requires active installation acknowledgement',str_contains($files['cli'],'SERVER_KEY_NOT_ACKNOWLEDGED_BY_NONTERMINAL_INSTALLATIONS'));
$c('revocation requires migrated dataform tokens',str_contains($files['cli'],'SERVER_KEY_REVOKE_NOT_READY')&&str_contains($files['cli'],'token_last_issued_key_id'));
$c('rotation actions are audited',str_contains($files['cli'],'SERVER_KEY_ROTATION_PREPARED')&&str_contains($files['cli'],'SERVER_KEY_ROTATION_ACTIVATED')&&str_contains($files['cli'],'SERVER_KEY_ROTATION_REVOKED'));
$c('rotation documentation exists',str_contains($files['doc'],'Schlüssel A')&&str_contains($files['doc'],'Schlüssel B'));
echo 'HF86_PHASE11_SERVER_GATE '.($ok?'PASS':'FAIL').PHP_EOL;exit($ok?0:1);
