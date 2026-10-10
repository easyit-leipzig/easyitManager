<?php
declare(strict_types=1);
namespace DataForm5\Console\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};
final class AboutCommand extends AbstractCommand
{public function name():string{return 'about';}public function description():string{return 'Zeigt Build- und Laufzeitinformationen.';}public function execute(Input $input,Output $output):int{$output->table(['Eigenschaft','Wert'],[['Projekt','DataForm5-Core'],['Build','0023'],['PHP',PHP_VERSION],['SAPI',PHP_SAPI]]);return 0;}}
