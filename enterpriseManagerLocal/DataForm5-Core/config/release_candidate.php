<?php
declare(strict_types=1);
return [
    'build' => 'build0044',
    'report_path' => 'storage/releases/rc-report.json',
    'required_files' => [
        'VERSION','BUILD.txt','README.md','bootstrap/app.php','bin/dataform',
        'docs/BUILD_0044.md','docs/RELEASE_CANDIDATE_POLICY.md','docs/CORE_1_0_ROADMAP.md',
    ],
    'required_directories' => ['system','config','tests','storage','resources'],
];
