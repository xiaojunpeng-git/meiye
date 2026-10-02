<?php
/** 统一资格接入合同：防止报表回退到各自写朋友开关判断或全局过滤劳动事实。 */
$root = dirname(__DIR__, 3);
$read = static function ($path) use ($root) { return file_get_contents($root.'/后端代码/app/services/'.$path); };
$two = $read('report/StoreUnifiedReportPhaseTwoServices.php');
$three = $read('report/StoreUnifiedReportPhaseThreeServices.php');
$four = $read('report/StoreUnifiedReportPhaseFourServices.php');
$employee = $read('query/metric/ServiceCustomerMetricReadServices.php');
$registered = $read('query/metric/RegisteredMetricReadServices.php');
$policy = $read('query/metric/ServiceCustomerEligibility.php');
$checks = [
    'SQL eligibility exists only in central authority' => strpos($policy, "service_object <> 'friend'") !== false
        && strpos($two, "service_object <> 'friend'") === false,
    'market/member visit scopes use central qualification' => strpos($two, "ServiceCustomerEligibility::apply(\$query, 'sv')") !== false
        && strpos($two, "if (\$customerMetric) ServiceCustomerEligibility::apply") !== false,
    'annual actual project mode preserves all service quantities' => strpos($two, "\$mode !== 'project'") !== false,
    'experience and active-member metrics use central projection' => substr_count($three, 'ServiceCustomerEligibility::filter($this->completedServices') === 2,
    'consultation metrics use central projection' => strpos($four, 'ServiceCustomerEligibility::filter($this->serviceRows') !== false,
    'salary and registered customer visits use same authority' => strpos($employee, 'ServiceCustomerEligibility::allows(') !== false
        && strpos($registered, "ServiceCustomerEligibility::apply(\$query, 's')") !== false,
    'field customer visits and actual handwork facts remain separated' => strpos($two, 'ServiceCustomerEligibility::filter($allServices)') !== false
        && strpos($two, 'array_slice($allServices, 0, 3)') !== false,
    'cross-industry first visit uses eligible projection' => strpos($two, "array_map([ServiceCustomerEligibility::class, 'filter'], \$services)") !== false,
];
foreach ($checks as $label=>$ok) { echo ($ok?'PASS ':'FAIL ').$label."\n"; if (!$ok) exit(1); }
