<?php

require_once '/tests/cashier-v3/lib/boot-env.php';
require_once '/var/www/html/vendor/autoload.php';
require_once '/tests/cashier-v3/lib/_lib.php';

use app\services\employee\EmployeeTypeAuthorityServices;
use app\services\organization\OrganizationScopeService;

c1aBootThinkApp('/var/www/html/');
OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_ORGANIZATION);

$target = (string)(getenv('ETA_TARGET_TYPE') ?: 'partner');
$token = (string)(getenv('ETA_REQUEST_TOKEN') ?: '');
$admin = [
    'id' => 1,
    'level' => 0,
    'admin_type' => 0,
    'account' => 'admin_1',
    'real_name' => '管理员1',
];

try {
    $out = app()->make(EmployeeTypeAuthorityServices::class)->saveType(
        40,
        $target,
        1,
        $admin,
        ['body_token' => $token, 'operator_ip' => '127.0.0.1']
    );
    echo 'RESULT=SUCCESS TYPE=' . (string)$out['data']['employment_type_code']
        . ' VERSION=' . (int)$out['data']['employment_type_version'] . PHP_EOL;
} catch (\Throwable $exception) {
    if (strpos($exception->getMessage(), '刷新后重试') !== false) {
        echo 'RESULT=CONFLICT' . PHP_EOL;
        exit(0);
    }
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
