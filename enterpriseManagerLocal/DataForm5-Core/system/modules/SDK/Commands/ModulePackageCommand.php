<?php
declare(strict_types=1);

namespace DataForm5\Modules\SDK\Commands;

use DataForm5\Console\Core\{AbstractCommand,Input,Output};
use DataForm5\Modules\SDK\ModulePackager;

final class ModulePackageCommand extends AbstractCommand
{
    public function __construct(private readonly ModulePackager $packager) {}
    public function name(): string { return 'module:package'; }
    public function description(): string { return 'Validiert und paketiert ein Modul als installationsfähiges ZIP.'; }
    public function arguments(): array { return ['module'=>'Modul-Slug']; }
    public function options(): array
    {
        return ['output'=>['description'=>'Ausgabeverzeichnis','default'=>null,'requires_value'=>true]];
    }

    public function execute(Input $input,Output $output): int
    {
        $dir=$this->option($input,'output');
        $result=$this->packager->package(
            (string)($this->argument($input,'module')??''),
            is_string($dir)?$dir:null
        );
        $output->success('Paket erzeugt: '.$result['zip']);
        $output->line('Dateien: '.$result['files']);
        $output->line('Validation Score: '.$result['validation_score'].'%');
        $output->line('ZIP-Backend: '.$result['backend']);
        $output->line('SHA-256: '.$result['sha256']);
        return 0;
    }
}
