<?php

declare(strict_types=1);

/** 第22轮大单退款展示回归：只读本地瑞昊镜像，不写业务表。 */
require '/var/www/html/vendor/autoload.php';

use app\services\report\StoreLargeOrderInstallmentServices;
use app\services\report\StoreUnifiedReportPhaseTwoServices;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

function r22RefundDisplayAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ', $message, "\n";
}

$result = (new StoreUnifiedReportPhaseTwoServices())->query(
    'salesperson_large_order_statistics',
    [23],
    ['start' => '2026-10-01', 'end' => '2026-10-31'],
    ['month' => '2026-10', '_internal_all' => true]
);

$keys = array_column($result['columns'] ?? [], 'key');
$awardColumn = array_search('award_amount', $keys, true);
$refundColumn = array_search('refund_amount', $keys, true);
r22RefundDisplayAssert((string)($result['metric_version'] ?? '') === 'large-order-gross-cash-refund-display-v11',
    '报表返回退款仅展示口径版本');
r22RefundDisplayAssert($awardColumn !== false && $refundColumn === $awardColumn + 1,
    '退款金额紧跟达标月累计现金业绩');

// 本地瑞昊2026年10月已有同人员、会员、分类的成功退款。用真实行同时验证
// “退款能看见”和“达标本金、档位、期次、应计金额继续使用正向现金”两个边界。
$refundRows = array_values(array_filter($result['records'] ?? [], static function (array $row): bool {
    return (int)($row['refund_amount_cents'] ?? 0) > 0;
}));
r22RefundDisplayAssert($refundRows !== [], '本地真实退款进入新增退款金额列');
$refundColumnMeta = (array)($result['columns'][$refundColumn] ?? []);
r22RefundDisplayAssert((string)($refundColumnMeta['value_type'] ?? '') === 'money'
    && str_contains((string)($refundColumnMeta['source_explanation'] ?? ''), '不参与'),
    '退款列声明金额类型并明确只展示不参与计算');
foreach ($refundRows as $row) {
    $gross = (int)$row['award_amount_cents'];
    $refund = (int)$row['refund_amount_cents'];
    $offset = max(0, (int)$row['installment_number'] - 1);
    $grossSchedule = StoreLargeOrderInstallmentServices::schedule($gross, (string)$row['rule_type']);
    $netSchedule = StoreLargeOrderInstallmentServices::schedule($gross - $refund, (string)$row['rule_type']);
    r22RefundDisplayAssert($refund > 0 && (int)$row['due_amount_cents'] === (int)($grossSchedule[$offset] ?? -1),
        '真实退款行按正向现金业绩计算本月应计');
    r22RefundDisplayAssert((int)$row['due_amount_cents'] !== (int)($netSchedule[$offset] ?? -1),
        '真实退款未从达标本金或应计公式扣除');
}
$displayedRefundCents = array_sum(array_map(static function (array $row): int {
    return (int)round((float)($row['refund_amount'] ?? 0) * 100);
}, $result['records'] ?? []));
$summaryRefundCents = (int)round((float)($result['summary_row']['refund_amount'] ?? 0) * 100);
r22RefundDisplayAssert($summaryRefundCents === $displayedRefundCents,
    '退款金额合计与同一查询口径下的可见明细一致');

echo 'REAL_REFUND_ROWS=', count($refundRows), "\n";
echo 'ASSERT_FAILED=0', "\n";
