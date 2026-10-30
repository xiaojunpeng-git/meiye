<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\BalanceOrder007ReadServices;

/** 独立合同测试只注入不可变事实行，不访问或改写数据库。 */
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$scope = ['_report_scope' => ['mode' => 'stores'], 'start_date' => '2026-10-01', 'end_date' => '2026-10-30'];
$balance = static function (array $stores, array $range): array {
    check($stores === [1, 2] && $range === ['start' => '2026-10-01', 'end' => '2026-10-30'], '事实加载器门店/日期范围');
    return [
        ['fact_id' => 'b1', 'store_id' => 1, 'store_name_snapshot' => '一店', 'member_id' => 8,
            'member_name_snapshot' => '甲', 'business_date' => '2026-10-01', 'principal_delta_cents' => -6000, 'bonus_delta_cents' => -4000],
        ['fact_id' => 'b2', 'store_id' => 1, 'store_name_snapshot' => '一店', 'member_id' => 8,
            'member_name_snapshot' => '甲', 'business_date' => '2026-10-01', 'principal_delta_cents' => -10000, 'bonus_delta_cents' => 0],
        ['fact_id' => 'b3', 'store_id' => 1, 'store_name_snapshot' => '一店', 'member_id' => 8,
            'member_name_snapshot' => '甲', 'business_date' => '2026-10-02', 'principal_delta_cents' => -5000, 'bonus_delta_cents' => 0],
        ['fact_id' => 'b4', 'store_id' => 2, 'store_name_snapshot' => '二店', 'member_id' => 8,
            'member_name_snapshot' => '甲', 'business_date' => '2026-10-01', 'principal_delta_cents' => -7000, 'bonus_delta_cents' => 0],
    ];
};
$recharge = static function (): array {
    return [
        ['fact_id' => 'r1', 'store_id' => 1, 'member_id' => 8, 'business_date' => '2026-10-01', 'amount_cents' => 18000],
        ['fact_id' => 'r2', 'store_id' => 2, 'member_id' => 8, 'business_date' => '2026-10-01', 'amount_cents' => 3000],
        ['fact_id' => 'r3', 'store_id' => 2, 'member_id' => 9, 'business_date' => '2026-10-01', 'amount_cents' => 999999],
    ];
};
$covered = static fn(): array => ['balance_unmapped' => 0, 'recharge_unmapped' => 0];
$profiles = static fn(): array => [8=>['nickname'=>'当前昵称','delete_time'=>123]];
$reader = new BalanceOrder007ReadServices($balance, $recharge, $covered, $profiles);
$detail = $reader->read(BalanceOrder007ReadServices::DETAIL, [1, 2], $scope);
check($detail['complete'] && count($detail['rows']) === 3, '明细三天店会员行');
check(array_column($reader->definitions(BalanceOrder007ReadServices::DETAIL), 'label') === [
    '门店名称', '业务日期', '用户信息', '余额支付金额', '充值金额', '实际扣卡金额', '当天是否有充值', '门店ID', '会员ID', '稳定行键',
], '007 明细列顺序');
foreach (array_column($reader->definitions(BalanceOrder007ReadServices::DETAIL), 'key') as $key) {
    check(isset($detail['field_sources'][$key]), '明细字段来源覆盖 ' . $key);
}
$byKey = array_column($detail['rows'], null, 'row_id');
check((float)$byKey['balance-007:detail:1:8:2026-10-01']['balance_amount'] === 200.0
    && (float)$byKey['balance-007:detail:1:8:2026-10-01']['recharge_amount'] === 180.0
    && (float)$byKey['balance-007:detail:1:8:2026-10-01']['actual_deduct'] === 20.0,
    '同日两笔扣款只对照一次充值');
check((float)$byKey['balance-007:detail:2:8:2026-10-01']['recharge_amount'] === 30.0,
    '同会员跨门店充值不串算');
check($byKey['balance-007:detail:1:8:2026-10-02']['has_recharge'] === '否', '隔日充值不串算');
check($byKey['balance-007:detail:1:8:2026-10-02']['user_info'] === '当前昵称/8(已注销)',
    '用户信息优先当前昵称并标记注销');
$summary = $reader->read(BalanceOrder007ReadServices::SUMMARY, [1, 2], $scope);
check(count($summary['rows']) === 2, '门店汇总人口');
check((float)$summary['rows'][0]['actual_deduct'] === 70.0 && (float)$summary['rows'][1]['actual_deduct'] === 40.0,
    '门店汇总按会员周期余额减充值');
check(array_column($reader->definitions(BalanceOrder007ReadServices::SUMMARY), 'label') === [
    '门店', '余额支付金额', '充值金额', '实际扣卡金额', '门店ID', '稳定行键',
], '007 汇总列顺序');
foreach (array_column($reader->definitions(BalanceOrder007ReadServices::SUMMARY), 'key') as $key) {
    check(isset($summary['field_sources'][$key]), '汇总字段来源覆盖 ' . $key);
}
check($summary['sum_fields'] === [], '007 无表头合计');

$negative = new BalanceOrder007ReadServices(static fn(): array => [
    ['fact_id' => 'b5', 'store_id' => 1, 'store_name_snapshot' => '一店', 'member_id' => 8,
        'member_name_snapshot' => '甲', 'business_date' => '2026-10-01', 'principal_delta_cents' => -10000, 'bonus_delta_cents' => 0],
    ['fact_id' => 'b6', 'store_id' => 1, 'store_name_snapshot' => '一店', 'member_id' => 8,
        'member_name_snapshot' => '甲', 'business_date' => '2026-10-02', 'principal_delta_cents' => -10000, 'bonus_delta_cents' => 0],
], static fn(): array => [
    ['fact_id' => 'r4', 'store_id' => 1, 'member_id' => 8, 'business_date' => '2026-10-01', 'amount_cents' => 12000],
], $covered, $profiles);
check(count($negative->read(BalanceOrder007ReadServices::DETAIL, [1], $scope)['rows']) === 1,
    '明细逐日排除负实际扣卡');
check((float)$negative->read(BalanceOrder007ReadServices::SUMMARY, [1], $scope)['rows'][0]['actual_deduct'] === 80.0,
    '汇总先跨日归集再执行非负条件');
$bad = new BalanceOrder007ReadServices(static fn(): array => [[
    'fact_id' => 'bad', 'store_id' => 1, 'store_name_snapshot' => '一店', 'member_id' => 8,
    'business_date' => '2026-10-01', 'principal_delta_cents' => 'not-money', 'bonus_delta_cents' => 0,
]], static fn(): array => [], $covered);
$gap = $bad->read(BalanceOrder007ReadServices::DETAIL, [1], $scope);
check(!$gap['complete'] && $gap['rows'] === [] && $gap['data_gaps'][0]['code'] === 'BALANCE_ORDER_FACT_INCOMPLETE',
    '来源不完整时不能返回零或部分金额');
$history = $reader->read(BalanceOrder007ReadServices::DETAIL, [1, 2], array_replace($scope, ['start_date' => '2026-08-01']));
check(!$history['complete'] && $history['rows'] === [] && $history['data_gaps'][0]['code'] === 'BALANCE_ORDER_HISTORY_NOT_IMPORTED',
    '早于事实覆盖期须返回缺口');
$unmappedBalance = new BalanceOrder007ReadServices($balance, $recharge,
    static fn(): array => ['balance_unmapped' => 763, 'recharge_unmapped' => 0]);
$balanceGap = $unmappedBalance->read(BalanceOrder007ReadServices::DETAIL, [1, 2], $scope);
check(!$balanceGap['complete'] && $balanceGap['rows'] === []
    && $balanceGap['data_gaps'][0]['code'] === 'BALANCE_ORDER_LEGACY_PAYMENT_NOT_MIGRATED',
    '未迁入旧余额订单时禁止显示部分金额');
$unmappedRecharge = new BalanceOrder007ReadServices($balance, $recharge,
    static fn(): array => ['balance_unmapped' => 0, 'recharge_unmapped' => 1]);
$rechargeGap = $unmappedRecharge->read(BalanceOrder007ReadServices::SUMMARY, [1, 2], $scope);
check(!$rechargeGap['complete'] && $rechargeGap['rows'] === []
    && $rechargeGap['data_gaps'][0]['code'] === 'BALANCE_ORDER_LEGACY_RECHARGE_NOT_MIGRATED',
    '同店同日充值未迁入时禁止计算实际扣卡');
$missingPaymentTime = new BalanceOrder007ReadServices($balance, $recharge,
    static fn(): array => ['balance_unmapped' => 0, 'recharge_unmapped' => 0, 'time_missing' => 1]);
$timeGap = $missingPaymentTime->read(BalanceOrder007ReadServices::DETAIL, [1, 2], $scope);
check(!$timeGap['complete'] && $timeGap['rows'] === []
    && $timeGap['data_gaps'][0]['code'] === 'BUSINESS_DATE_UNRESOLVED',
    '旧单无支付秒数时不以创建日冒充业务日');
try {
    $reader->read(BalanceOrder007ReadServices::DETAIL, [1], array_replace($scope, ['_report_scope' => ['mode' => 'self_participant']]));
    throw new RuntimeException('无财务权限意外成功');
} catch (InvalidArgumentException $e) {
    check(strpos($e->getMessage(), '权限') !== false, '权限失败原因');
}
echo "balance-order-007-reader PASS\n";
