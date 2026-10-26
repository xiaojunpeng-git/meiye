<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$start = strpos($source, 'private function marketDetail');
$end = strpos($source, 'private function memberVisitAnalysis', $start);
$marketDetail = substr($source, $start, $end - $start);

$checks = [
    'member name comes from the payment fact snapshot' => 'MAX(p.member_name_snapshot) member_name_snapshot',
    'phone comes from the matching member record' => "leftJoin('user market_detail_member', 'market_detail_member.uid = p.member_id')",
    'phone is returned beside the member' => 'MAX(market_detail_member.phone) member_phone',
    'member column follows the store column' => "'store_name_snapshot'=>'门店名称','member_name_snapshot'=>'会员','member_phone'=>'手机'",
    'guest order displays a business label instead of a blank member' => "if ((int)(\$row['member_id'] ?? 0) <= 0) \$row['member_name_snapshot'] = '游客'",
    'member source guide explains the guest label' => '没有会员身份时显示“游客”',
];

$passed = 0;
foreach ($checks as $name => $needle) {
    $haystack = $name === 'member source guide explains the guest label' ? $source : $marketDetail;
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL {$name}\n");
        continue;
    }
    echo "PASS {$name}\n";
    $passed++;
}

echo "market detail member columns contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
