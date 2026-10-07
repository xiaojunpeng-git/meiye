<?php

/** R29 门店到店横向契约：员工人数开关不得进入门店资格，真实服务/金额不得被整体隐藏。 */
$root = dirname(__DIR__, 3) . '/后端代码/app/services/';
$files = [
    'market/member/field/cross reports' => 'report/StoreUnifiedReportPhaseTwoServices.php',
    'phase-three active members' => 'report/StoreUnifiedReportPhaseThreeServices.php',
    'phase-four operations visits' => 'report/StoreUnifiedReportPhaseFourServices.php',
    'customer analytics visit charts' => 'report/CustomerAnalyticsServices.php',
    'customer active status' => 'report/StoreUnifiedReportServices.php',
    'phase-six acquisition and health' => 'report/StoreUnifiedReportPhaseSixServices.php',
    'group source dashboard' => 'report/GroupManagementDashboardServices.php',
    'member visit bands' => 'report/MemberManagementDashboardServices.php',
    'lifecycle visits' => 'report/CustomerLifecycleNormalReadServices.php',
    'member list and filter' => 'query/provider/MemberUnifiedQueryProvider.php',
    'registered member visit metric' => 'query/metric/RegisteredMetricReadServices.php',
    'member detail overview' => 'cashier/v3/member/CashierV3MemberDetailQueryServices.php',
    'mobile audience latest visit' => 'mobile/customer/MobileCustomerAudienceOverviewServices.php',
];
foreach ($files as $label => $path) {
    $content = file_get_contents($root . $path);
    if ($content === false || !str_contains($content, 'StoreCustomerVisitEligibility::')) {
        throw new RuntimeException($label . ' does not use the common store-visit eligibility');
    }
    if (str_contains($content, 'ServiceCustomerEligibility::') && $path !== 'query/metric/RegisteredMetricReadServices.php') {
        throw new RuntimeException($label . ' still uses the personnel eligibility');
    }
    echo 'PASS ' . $label . PHP_EOL;
}
$staff = file_get_contents($root . 'query/metric/ServiceCustomerMetricReadServices.php');
$policy = file_get_contents($root . 'query/metric/StoreCustomerVisitEligibility.php');
$phaseTwo = file_get_contents($root . 'report/StoreUnifiedReportPhaseTwoServices.php');
$phaseSix = file_get_contents($root . 'report/StoreUnifiedReportPhaseSixServices.php');
$registered = file_get_contents($root . 'query/metric/RegisteredMetricReadServices.php');
if (!str_contains((string)$staff, 'ServiceCustomerEligibility::allows(')
    || str_contains((string)$policy, 'friend_counts_as_customer')
    || !str_contains((string)$policy, 'is_experience')
    || !str_contains((string)$phaseTwo, 'member_service.is_experience')
    || !str_contains((string)$phaseSix, 'array_keys($categoryConsumption)')
    || !str_contains((string)$registered, 's.quantity,s.is_experience')) {
    throw new RuntimeException('personnel and store qualifications are not independent');
}
echo "PASS personnel eligibility remains employee-only\n";
