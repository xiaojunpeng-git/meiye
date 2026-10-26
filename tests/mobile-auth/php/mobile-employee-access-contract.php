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
$failed = 0;
$assert = static function (string $id, bool $ok) use (&$failed): void {
    if ($ok) {
        echo "PASS {$id}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$id}\n");
};

$policy = $read('后端代码/app/services/organization/JobPositionPolicyServices.php');
$complete = $read('后端代码/app/services/employee/EmployeePersonCompleteWriteServices.php');
$jobs = $read('后端代码/app/services/organization/StaffJobPositionServices.php');
$entry = $read('后端代码/app/services/organization/MerchantEntryServices.php');
$session = $read('后端代码/app/services/mobile/merchant/MobileMerchantSessionServices.php');
$customer = $read('后端代码/app/controller/mobile/merchant/Customer.php');

$assert('MOBILE-EMPLOYEE-01',
    str_contains($policy, 'MobileMerchantCapabilityCatalog::class')
    && str_contains($policy, "'mobile_menus' => \$mobileCatalog->menuTree()")
    && str_contains($policy, 'normalizeMobileRuleIds')
);
$assert('MOBILE-EMPLOYEE-02',
    str_contains($complete, "array_key_exists('mobile_enabled', \$input)")
    && str_contains($complete, 'setEmployeeMobileAccessInTx')
    && str_contains($complete, "'mobile_enabled' => \$mobileAccessOut")
);
$assert('MOBILE-EMPLOYEE-03',
    str_contains($jobs, 'function setEmployeeMobileAccessInTx')
    && str_contains($jobs, 'CHANNEL_MOBILE => $enabled')
    && str_contains($jobs, 'projectMobileAuthRules($employeeId, $enabled)')
    && str_contains($jobs, 'afterEmployeeAuthChanged($employeeId)')
);
$assert('MOBILE-EMPLOYEE-04',
    !str_contains($entry, 'legacyMobileOk')
    && str_contains($entry, '$mobileAuthOn')
    && str_contains($entry, 'if (!$entryOn)')
);
$assert('MOBILE-EMPLOYEE-05',
    str_contains($session, 'defaultEligibleManagerOperationContext')
    && str_contains($session, "'staff_id' => 0")
    && !str_contains($session, "where('channel', JobPositionPolicyServices::CHANNEL_MOBILE)->where('status', 1)")
);
$assert('MOBILE-EMPLOYEE-06',
    substr_count($customer, "assertAction(\$context, 'CUSTOMER_VIEW')") >= 2
    && substr_count($customer, "assertAction(\$context, 'CUSTOMER_AUDIENCE_MANAGE')") >= 3
    && str_contains($customer, "assertAction(\$context, 'CUSTOMER_AUDIENCE_VIEW')")
);

exit($failed === 0 ? 0 : 1);
