<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\report\OtherReports007ReadServices;

/** 独立合同测试以冻结来源验证列、门店边界、聚合和缺口，不访问数据库。 */
function check007(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$scope = ['_report_scope' => ['mode' => 'stores'], 'start_date' => '2026-10-01',
    'end_date' => '2026-10-30', 'as_of_date' => '2026-10-30'];
$reader = new OtherReports007ReadServices(
    static function (array $stores, array $range): array {
        check007($stores === [1, 2] && $range === ['start' => '2026-10-01', 'end' => '2026-10-30'], '对账授权范围');
        return ['methods' => [['code' => 'legacy_1', 'name' => '银联扫码'],
                ['code' => 'brush_douyin', 'name' => '抖音刷单']],
            'receipts' => [
                ['receipt_id' => 'a', 'store_id' => 1, 'business_date' => '2026-10-01',
                    'legacy_order_add_date' => '2026-09-29', 'method_code' => 'legacy_1', 'amount_cents' => 12000],
                ['receipt_id' => 'b', 'store_id' => 2, 'business_date' => '2026-10-01', 'method_code' => 'legacy_1', 'amount_cents' => 3000],
                ['receipt_id' => 'c', 'store_id' => 1, 'business_date' => '2026-10-30', 'method_code' => 'brush_douyin', 'amount_cents' => 2500],
            ]];
    },
    static fn(): array => ['rows' => [[
        'fact_id' => 'sale1', 'store_id' => 1, 'store_name' => '一店', 'business_date' => '2026-10-15',
        'legacy_order_add_date' => '2026-10-12',
        'customer_name' => '甲', 'project_name' => '项目甲', 'quantity' => '2', 'amount_cents' => '32000',
        'followup_staff_id' => 71, 'followup_name' => '乙',
    ]]],
    static fn(): array => ['complete' => true, 'as_of_date' => '2026-10-30', 'rows' => [
        ['asset_type' => '储值', 'member_id' => 8, 'store_id' => 1,
            'principal_amount' => 150, 'gift_amount' => 20],
        ['asset_type' => '卡内项目', 'member_id' => 8, 'store_id' => 1, 'store_name' => '一店',
            'customer_name' => '甲', 'phone' => '13900000008', 'card_holder_id' => 10,
            'project_detail_id' => 55, 'category_name' => '护理', 'card_name' => 'A卡',
            'project_name' => '项目甲', 'remaining_times' => 2, 'order_no' => 'A001'],
        ['asset_type' => '卡内项目', 'member_id' => 8, 'store_id' => 1, 'store_name' => '一店',
            'customer_name' => '甲', 'phone' => '13900000008', 'card_holder_id' => 10,
            'project_detail_id' => 56, 'category_name' => '护理', 'card_name' => 'A卡',
            'project_name' => '项目乙', 'remaining_times' => 0, 'order_no' => 'A001'],
        ['asset_type' => '卡内项目', 'member_id' => 8, 'store_id' => 1, 'store_name' => '一店',
            'customer_name' => '甲', 'phone' => '13900000008', 'card_holder_id' => 10,
            'project_detail_id' => 57, 'category_name' => '护理', 'card_name' => '赠送卡',
            'project_name' => '项目丙', 'remaining_times' => 3, 'order_no' => 'A001'],
    ]],
    static fn(): array => ['rows' => [[
        'fact_id' => 'native-sale-1', 'store_id' => 2, 'store_name' => '二店',
        'business_date' => '2026-10-16', 'customer_name' => '丙', 'project_name' => '项目乙',
        'quantity' => '1', 'amount_cents' => 9000,
        'followup_staff_id' => 72, 'followup_name' => '丁',
    ]]],
    static fn(): array => [55=>['member_id'=>8,'store_id'=>1]]
);

$dailyFields = $reader->definitions(OtherReports007ReadServices::DAILY);
check007(count(array_filter($dailyFields, static fn(array $f): bool => $f['defaultVisible'])) === 33,
    '每日对账 007 支付方式/合计/31 日列');
check007(array_slice(array_column($dailyFields, 'label'), 0, 4) === ['支付方式', '合计', '1号', '2号'],
    '每日列顺序');
$daily = $reader->read(OtherReports007ReadServices::DAILY, [1, 2], $scope);
check007(count($daily['rows']) === 2 && (float)$daily['rows'][0]['total_amount'] === 150.0
    && (float)$daily['rows'][0]['day_1'] === 150.0 && (float)$daily['rows'][1]['day_30'] === 25.0,
    '跨日旧单按 V3 业务日入桶，不同店同日归一支付方式，刷单独立');

$followup = $reader->read(OtherReports007ReadServices::FOLLOWUP, [1, 2], $scope);
check007(array_slice(array_column($followup['fields'], 'label'), 0, 7) ===
    ['门店', '日期', '客户名称', '项目', '次数', '金额', '跟单人员'], '跟单七列');
check007((float)$followup['rows'][1]['amount'] === 320.0 && (float)$followup['rows'][1]['quantity'] === 2.0,
    '跟单金额与数量取逐行事实');
check007($followup['rows'][1]['business_date'] === '2026-10-15',
    '跟单展示 V3 业务日，不用跨日原单创建日');
check007($followup['complete'] && count($followup['rows']) === 2
    && $followup['rows'][0]['business_date'] === '2026-10-16'
    && $followup['rows'][0]['followup_name'] === '丁',
    '正式凭据可证的新销售跟单按业务日进入七列结果');

$rights = $reader->read(OtherReports007ReadServices::RIGHTS, [1, 2], $scope);
check007(array_slice(array_column($rights['fields'], 'label'), 0, 10) ===
    ['门店', '姓名', '电话', '本金', '赠金', '分类', '卡名称', '项目', '剩余次数', '订单号'], '权益十列');
check007(count($rights['rows']) === 1 && $rights['rows'][0]['principal_amount'] === 150
    && $rights['rows'][0]['remaining_times'] === 2 && $rights['sum_fields'] === [],
    '只显示符合 007 原单资格的非赠送项目，重复余额不可合计');
$missingEligible = new OtherReports007ReadServices(null, null,
    static fn(): array => ['complete'=>true,'rows'=>[['asset_type'=>'储值','member_id'=>8,'store_id'=>1,
        'principal_amount'=>150,'gift_amount'=>20]]], null,
    static fn(): array => [55=>['member_id'=>8,'store_id'=>1]]);
$missingEligibleResult = $missingEligible->read(OtherReports007ReadServices::RIGHTS, [1], $scope);
check007(!$missingEligibleResult['complete'] && $missingEligibleResult['rows'] === []
    && $missingEligibleResult['data_gaps'][0]['code'] === 'RIGHTS_PROJECT_NOT_IN_CURRENT_AUTHORITY',
    '007 合格原项目未进入现权益权威必须阻止部分清单');
foreach ([$daily, $followup, $rights] as $result) foreach ($result['fields'] as $field) {
    check007(isset($result['field_sources'][$field['key']]), '字段来源完整 ' . $field['key']);
}

$invalid = new OtherReports007ReadServices(static fn(): array => ['methods' => [
    ['code' => 'legacy_1', 'name' => '银联扫码']], 'receipts' => [
        ['receipt_id' => 'a', 'store_id' => 1, 'business_date' => '2026-10-01', 'method_code' => 'legacy_1', 'amount_cents' => 100],
        ['receipt_id' => 'a', 'store_id' => 1, 'business_date' => '2026-10-01', 'method_code' => 'legacy_1', 'amount_cents' => 100],
    ]]);
$gap = $invalid->read(OtherReports007ReadServices::DAILY, [1], $scope);
check007(!$gap['complete'] && $gap['rows'] === [] && $gap['data_gaps'][0]['code'] === 'DAILY_RECEIPT_INVALID',
    '重复来源不能双算');
$missing = new OtherReports007ReadServices(static fn(): array => ['methods' => [], 'receipts' => [],
    'data_gaps' => [['code' => 'DAILY_LEGACY_PAYMENT_NOT_MIGRATED', 'message' => '旧订单未迁入']]],
    static fn(): array => ['rows' => [['fact_id' => 'would_be_partial']], 'unmapped_legacy_count' => 1]);
$dailyGap = $missing->read(OtherReports007ReadServices::DAILY, [1], $scope);
$followupGap = $missing->read(OtherReports007ReadServices::FOLLOWUP, [1], $scope);
check007(!$dailyGap['complete'] && $dailyGap['rows'] === []
    && $dailyGap['data_gaps'][0]['code'] === 'DAILY_LEGACY_PAYMENT_NOT_MIGRATED',
    '未迁入旧收款时禁止部分合计');
check007(!$followupGap['complete'] && $followupGap['rows'] === []
    && $followupGap['data_gaps'][0]['code'] === 'FOLLOWUP_LEGACY_SALE_NOT_MIGRATED',
    '未迁入旧跟单销售时禁止部分明细');
$unverifiedNative = new OtherReports007ReadServices(null,
    static fn(): array => ['rows' => []], null,
    static function (): array { throw new RuntimeException('签发凭据缺失'); });
$nativeGap = $unverifiedNative->read(OtherReports007ReadServices::FOLLOWUP, [1], $scope);
check007(!$nativeGap['complete'] && $nativeGap['rows'] === []
    && $nativeGap['data_gaps'][0]['code'] === 'FOLLOWUP_NATIVE_SOURCE_UNVERIFIED',
    '已识别跟单凭据无法核实时整表拒绝部分结果');
$partialRights = new OtherReports007ReadServices(null, null,
    static fn(): array => ['complete' => false, 'rows' => [
        ['asset_type' => '卡内项目', 'member_id' => 8, 'store_id' => 1,
            'card_holder_id' => 10, 'project_detail_id' => 55, 'remaining_times' => 2]],
        'data_gaps' => [['code' => 'ENTITLEMENT_AUTHORITY_INCOMPLETE', 'message' => '权益不完整']]]);
$rightsGap = $partialRights->read(OtherReports007ReadServices::RIGHTS, [1], $scope);
check007(!$rightsGap['complete'] && $rightsGap['rows'] === []
    && $rightsGap['data_gaps'][0]['code'] === 'ENTITLEMENT_AUTHORITY_INCOMPLETE',
    '权益权威不完整时不能展示局部项目');
$unknownStore = new OtherReports007ReadServices(null, null,
    static function (): array { throw new RuntimeException('ENTITLEMENT_HOLDER_STORE_UNRESOLVED'); });
$unknownStoreResult = $unknownStore->read(OtherReports007ReadServices::RIGHTS, [1], $scope);
check007(!$unknownStoreResult['complete'] && $unknownStoreResult['rows'] === []
    && $unknownStoreResult['sum_fields'] === []
    && $unknownStoreResult['data_gaps'][0]['code'] === 'ENTITLEMENT_HOLDER_STORE_UNRESOLVED'
    && !empty($unknownStoreResult['data_gaps'][0]['label'])
    && count($unknownStoreResult['fields']) === count($rights['fields']),
    '持卡来源门店无凭据时整表为空并保留同一列契约');
try {
    (new OtherReports007ReadServices(null, null,
        static function (): array { throw new RuntimeException('ENTITLEMENT_UNEXPECTED_FAILURE'); }))
        ->read(OtherReports007ReadServices::RIGHTS, [1], $scope);
    throw new RuntimeException('未知权益错误被误吞');
} catch (RuntimeException $error) {
    check007($error->getMessage() === 'ENTITLEMENT_UNEXPECTED_FAILURE', '未知权益错误必须继续抛出');
}
$undated = new OtherReports007ReadServices(
    static fn(): array => ['methods' => [], 'receipts' => [], 'unresolved_legacy_count' => 1],
    static fn(): array => ['rows' => [], 'unresolved_legacy_count' => 1]
);
foreach ([OtherReports007ReadServices::DAILY, OtherReports007ReadServices::FOLLOWUP] as $code) {
    $undatedGap = $undated->read($code, [1], $scope);
    check007(!$undatedGap['complete'] && $undatedGap['rows'] === []
        && $undatedGap['data_gaps'][0]['code'] === 'BUSINESS_DATE_UNRESOLVED',
        '缺失旧单支付时间时不能按创建日填入业务日');
}
try {
    $reader->read(OtherReports007ReadServices::RIGHTS, [1], array_replace($scope,
        ['_report_scope' => ['mode' => 'self_participant']]));
    throw new RuntimeException('财务/权益范围意外放宽');
} catch (InvalidArgumentException $error) {
    check007(strpos($error->getMessage(), '权限') !== false, '权限错误');
}
echo "other-reports-007-reader PASS\n";
