<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$s=(string)file_get_contents($root.'/setup.php');
$checks=[
 'INSTALL_TEXT'=>str_contains($s,'Installation vollständig abschließen'),
 'INSTALL_SKIP_REGISTRY'=>str_contains($s,'class="button setup-install-submit" data-button-skip type="submit"'),
 'NO_INSTALL_IMAGE_REGISTRY'=>!str_contains($s,"easyit_button_attributes('bestaetigen', 'install')"),
 'LOCKED_TEXT'=>str_contains($s,'Abschluss gesperrt – Lizenzserver meldet noch SETUP_REQUIRED'),
 'LOCKED_SKIP_REGISTRY'=>str_contains($s,'secondary setup-install-submit" data-button-skip type="button" disabled'),
 'POST_ACTION_INSTALL'=>str_contains($s,'name="setup_action" value="install"'),
 'VISIBLE_CSS'=>str_contains($s,'button.setup-install-submit{display:inline-flex'),
];
$fail=0;foreach($checks as $n=>$ok){echo $n.' '.($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
