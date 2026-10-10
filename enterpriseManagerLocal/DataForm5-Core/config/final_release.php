<?php
declare(strict_types=1);
return [
    'build'=>'build0045',
    'report_path'=>'storage/releases/final-report.json',
    'manifest_path'=>'storage/releases/final-manifest.json',
    'required_files'=>[
        'VERSION','BUILD.txt','README.md','bootstrap/app.php','bin/dataform',
        'docs/BUILD_0045.md','docs/FINAL_RELEASE_1_0_0.md','docs/LTS_POLICY.md','docs/PRODUCT_HANDOFF.md',
    ],
    'required_directories'=>['system','config','tests','storage','resources','docs'],
    'excluded_paths'=>['storage/releases/final-report.json','storage/releases/final-manifest.json','storage/logs','storage/framework/cache'],
];
