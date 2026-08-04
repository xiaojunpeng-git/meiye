<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) {
        throw new RuntimeException('missing:' . $path);
    }
    return $value;
};

$revocation = $read('后端代码/app/services/mobile/merchant/MobileAuthRevocationServices.php');
$jobs = $read('后端代码/app/services/organization/StaffJobPositionServices.php');
$failed = 0;
$assert = static function (string $id, bool $ok) use (&$failed): void {
    if ($ok) {
        echo "PASS {$id}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$id}\n");
};

$assert(
    'MOBILE-AUTH-INIT-01',
    str_contains($revocation, "Db::name('employee_mobile_auth_state')->insert")
    && str_contains($revocation, "'phone_binding_version' => 1")
    && str_contains($revocation, "'merchant_session_epoch' => 1")
    && str_contains($revocation, '员工认证状态初始化失败')
);
$assert(
    'MOBILE-AUTH-INIT-02',
    str_contains($revocation, "Db::name('employee_mobile_auth_state')->where('employee_id', \$employeeId)->lock(true)->find()")
    && str_contains($revocation, "'auth_version' => \$authVersion")
    && str_contains($revocation, "'merchant_session_epoch' => \$epoch")
);
$assert(
    'MOBILE-AUTH-INIT-03',
    str_contains($jobs, 'setEmployeeMobileAccessInTx')
    && str_contains($jobs, "revokeAllInCurrentTransaction(\$employeeId, 'AUTH_CHANGED')")
    && str_contains($jobs, '在同一事务内')
);

exit($failed === 0 ? 0 : 1);
