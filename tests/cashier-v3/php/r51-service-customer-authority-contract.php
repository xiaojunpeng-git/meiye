<?php
/** 统一资格接入合同：防止报表自行判断计数开关或全局过滤劳动事实。 */
$root = dirname(__DIR__, 3);
$read = static function ($path) use ($root) { return file_get_contents($root.'/后端代码/app/services/'.$path); };
$two = $read('report/StoreUnifiedReportPhaseTwoServices.php');
$three = $read('report/StoreUnifiedReportPhaseThreeServices.php');
$four = $read('report/StoreUnifiedReportPhaseFourServices.php');
$employee = $read('query/metric/ServiceCustomerMetricReadServices.php');
$registered = $read('query/metric/RegisteredMetricReadServices.php');
$policy = $read('query/metric/ServiceCustomerEligibility.php');
$storePolicy = $read('query/metric/StoreCustomerVisitEligibility.php');
// 门店到店资格与员工人数/人次资格是两条独立事实投影，不能全局互相替代。
$marketStart = strpos($two, 'private function marketServiceVisitFacts(');
$marketEnd = strpos($two, 'private function marketCountableServiceVisits(', $marketStart ?: 0);
$marketVisitMethod = $marketStart !== false && $marketEnd !== false
    ? substr($two, $marketStart, $marketEnd - $marketStart) : '';
$checks = [
    'SQL eligibility exists only in central authority' => strpos($policy, '->where("{$alias}.friend_counts_as_customer", 1)') !== false
        && strpos($two, "service_object <> 'friend'") === false,
    'store visit authority uses frozen experience only' => strpos($storePolicy, "is_experience") !== false
        && strpos($storePolicy, 'friend_counts_as_customer') === false
        && $marketVisitMethod !== '' && strpos($marketVisitMethod, 'ServiceCustomerEligibility::apply') === false,
    'annual actual project mode preserves all service quantities' => strpos($two, "\$mode !== 'project'") !== false,
    'member visits and active-member metrics use store authority' => strpos($two, 'StoreCustomerVisitEligibility::apply($query') !== false
        && strpos($three, 'StoreCustomerVisitEligibility::allows($service)') !== false,
    'experience and consultation retain actual experience facts' => strpos($three, 'foreach ($this->completedServices($stores, $range, $categoryIds) as $service)') !== false
        && strpos($four, "\$services = \$this->serviceRows(") !== false,
    'salary alone uses personnel authority' => strpos($employee, 'ServiceCustomerEligibility::allows(') !== false
        && strpos($registered, "ServiceCustomerEligibility::apply(\$query, 's')") === false
        && strpos($registered, "StoreCustomerVisitEligibility::apply(\$query, 's')") !== false,
    'field customer visits and actual handwork facts remain separated' => strpos($two, 'StoreCustomerVisitEligibility::filter($allServices)') !== false
        && strpos($two, 'array_slice($allServices, 0, 3)') !== false,
    'cross-industry first visit uses store projection' => strpos($two, "StoreCustomerVisitEligibility::filter(\$services[\$id]??[])") !== false,
];
foreach ($checks as $label=>$ok) { echo ($ok?'PASS ':'FAIL ').$label."\n"; if (!$ok) exit(1); }
