<?php
/** Swoole workers must be recycled before long-lived memory growth takes down writes. */

$source = file_get_contents(__DIR__ . '/../../../后端代码/config/swoole.php');
if (!is_string($source)) {
    fwrite(STDERR, "cannot read swoole config\n");
    exit(1);
}

$checks = [
    'http options define a worker request recycle limit' => strpos($source, "'max_request'") !== false,
    'recycle limit is environment-overridable' => strpos($source, "env('SWOOLE_MAX_REQUEST', 300)") !== false,
    'recycle limit is applied inside the Swoole HTTP options' => strpos($source, "'package_max_length'") < strpos($source, "'max_request'")
        && strpos($source, "'max_request'") < strpos($source, "],\n        ],\n    ],")
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $failed += $ok ? 0 : 1;
}
exit($failed === 0 ? 0 : 1);
