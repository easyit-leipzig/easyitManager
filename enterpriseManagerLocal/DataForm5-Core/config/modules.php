<?php
declare(strict_types=1);
$coreModules=dirname(__DIR__).'/modules';
$enterpriseModules=dirname(__DIR__,2).'/modules';
return [
    'enabled'=>true,
    // `path` bleibt für ältere Aufrufer erhalten.
    'path'=>$coreModules,
    // RC1.7.4: Core- und Enterprise-Module werden gemeinsam entdeckt.
    'paths'=>[$coreModules,$enterpriseModules],
    // Phase F: Enterprise-Module werden hier als installierbare ZIP-Pakete abgelegt.
    'enterprise_install_path'=>$enterpriseModules,
    'package_registry'=>$enterpriseModules.'/.installed.json',
    'package_temp'=>dirname(__DIR__).'/storage/framework/module-packages',
];
