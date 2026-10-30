<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../后端代码/vendor/autoload.php';
require_once __DIR__ . '/../../../后端代码/app/services/report/Sidebar007ParityPlanServices.php';
require_once __DIR__ . '/../../../后端代码/app/services/report/Sidebar007PartnerAllocationReadServices.php';
require_once __DIR__ . '/../../../后端代码/app/services/report/Sidebar007PkEfficiencyV3ReadServices.php';

use app\services\report\Sidebar007PkEfficiencyV3ReadServices;

/** 只验独立投影及权限合同；模拟基础数不是线上 007/V3 对账证据。 */
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject(callable $fn, string $message): void
{
    try { $fn(); } catch (InvalidArgumentException|LogicException $e) { return; }
    throw new RuntimeException($message);
}

$reader = new Sidebar007PkEfficiencyV3ReadServices();
$stores = [['id'=>7,'name'=>'万象城店'], ['id'=>8,'name'=>'滨北店']];
$coverage = static fn(array $ids, string $start, string $end): array => [
    ['store_id'=>7,'payment_fact_rows'=>'14'],
];
$partner = static fn(array $ids, array $period): array => [
    ['store_id'=>7,'partner_amount_cents'=>'10000','outsourced_amount_cents'=>'5000',
        'refund_deduction_cents'=>'-3000','unclassified_rows'=>'0','unsigned_reversal_rows'=>'0'],
];
$atomic = static function(string $code,array $ids,array $period):array {
    return array_map(static fn(int $id):array=>['store_id'=>$id,'metric_value'=>$id*100],$ids);
};
$payments=static fn():array=>[];
$legacy=static fn():array=>[['store_id'=>8,'source_order_id'=>'80','expected_cents'=>100]];
$goals=static fn():array=>[];
$pk = $reader->readPk([7,8], $stores, '2026-10',
    [7=>['goal_cents'=>100000,'bottom_cents'=>60000,'month_cash_cents'=>110000]],
    $coverage, $partner, $atomic,$payments,$legacy,$goals);
check(array_column($pk['columns'],'label') === ['门店','本月目标','本月底标','本月业绩','业绩分成款',
    '本月实际分成','本月增长业绩','完成率'], '007 PK 字段顺序或产品重命名错误');
check(count($pk['rows']) === 2 && $pk['rows'][0]['cells']['sharing_cents'] === 10000
    && $pk['rows'][0]['cells']['actual_cents'] === 100000
    && $pk['rows'][0]['cells']['growth_cents'] === 40000,
    '合作方实配和本表专用现金减partner公式错误');
check($pk['partner_allocation']['separate_facts'][7]['outsourced_amount_cents'] === 5000
    && $pk['partner_allocation']['separate_facts'][7]['refund_deduction_cents'] === -3000,
    'outsourced 与退款扣减不可混入 partner 分成');
check(strpos($pk['field_sources']['actual_cents'],'本月实际分成')!==false,
    '重命名后的取值来源未同步');
check($pk['rows'][0]['cells']['completion_percent'] === 100 && !$pk['complete'],
    '007 先截整数倍再乘100的完成率口径错误');
$underGoal=$reader->readPk([7,8],$stores,'2026-10',
    [7=>['goal_cents'=>200000,'month_cash_cents'=>110000]],
    $coverage,$partner,$atomic,$payments,$legacy,$goals);
check($underGoal['rows'][0]['cells']['completion_percent']===0,
    '007 未达整倍目标时 bcdiv 默认 scale=0 应显示0%');
check($pk['rows'][1]['cells']['goal_cents'] === 0 && $pk['coverage'][1]['payment_fact_rows'] === 0,
    '007 无手录目标记录显示 0，空事实仍保留覆盖诊断');
check($pk['original_cash']['by_store'][8]['amount_cents']===null,
    '缺 V3 原单收款的门店不能认证现金');
check($pk['v3_atoms']['by_store'][7]['sales_collected_amount']===700
    && $pk['rows'][1]['cells']['month_cash_cents']===null,
    '已登记 V3 销售收款须接线但不能冒充 007 原单月现金');
check($pk['columns'][1]['manual'] === true && $pk['rows'][0]['row_id'] === 'sales_pk:2026-10:7',
    '手录目标必须绑定稳定门店月份键');
$autoCash=$reader->readPk([7,8],$stores,'2026-10',[], $coverage,$partner,$atomic,
    static fn():array=>[['fact_id'=>'s7','store_id'=>7,'order_id'=>'RCH:7','source_line_id'=>'pay-s7',
        'source_document_type'=>'recharge','amount_cents'=>110000,'eligible'=>true]],$legacy,$goals);
check($autoCash['rows'][0]['cells']['month_cash_cents']===110000
    &&$autoCash['rows'][0]['cells']['actual_cents']===100000,
    '正式 V3 原充值现金须自动接入 PK 并减 partner 实配');
$cutoffResult=$reader->readPk([7,8],$stores,'2026-10',[], $coverage,$partner,$atomic,
    $payments,$legacy,$goals,'2026-10-15');
check($cutoffResult['scope']['date_range']['end']==='2026-10-15',
    'PK 本月事实必须截到查询业务日');
reject(static fn()=>$reader->readPk([7,8],$stores,'2026-11',[], $coverage,$partner,$atomic,
    $payments,$legacy,$goals,'2026-10-30'),'未来 PK 月份须拒绝');
$zeroGoal = $reader->readPk([7,8], $stores, '2026-10',
    [7=>['goal_cents'=>0,'month_cash_cents'=>10000]], $coverage, $partner, $atomic,$payments,$legacy,$goals);
check($zeroGoal['rows'][0]['cells']['completion_percent'] === 0,
    '007 已在真人页面核实的零目标完成率须显示 0%');
$unknownPartner = $reader->readPk([7,8], $stores, '2026-10',
    [7=>['goal_cents'=>100000,'bottom_cents'=>60000,'month_cash_cents'=>110000]],
    $coverage, static fn(): array => [[
        'store_id'=>7,'partner_amount_cents'=>10000,'outsourced_amount_cents'=>5000,
        'refund_deduction_cents'=>-3000,'unclassified_rows'=>1,'unsigned_reversal_rows'=>0]],
    $atomic,$payments,$legacy,$goals);
check($unknownPartner['rows'][0]['cells']['sharing_cents']===null
    && $unknownPartner['rows'][0]['cells']['actual_cents']===null,
    '人员类型来源不完整时，PK 分成及其派生结果必须同时留缺口');

$beds = static fn(array $ids): array => [['id'=>7,'space_num'=>'6'], ['id'=>8,'space_num'=>'0']];
$staff = static fn(array $ids,array $range):array=>[['store_id'=>7,'staff_count'=>7],
    ['store_id'=>8,'staff_count'=>0]];
$consumptionGap=static fn():array=>['by_store'=>[7=>null,8=>null],
    'complete'=>false,'gaps'=>[['code'=>'LEGACY_WRITEOFF_SOURCE_UNVERIFIED']]];
$serviceOrderGap=static fn():array=>['by_store'=>[],'complete'=>false,
    'gaps'=>[['code'=>'LEGACY_SERVICE_ORDER_QUALIFICATION_UNVERIFIED']]];
$serviceOrderFull=static fn():array=>['by_store'=>[
    7=>['consumption_amount_cents'=>62300,'service_customer_count'=>623],
    8=>['consumption_amount_cents'=>0,'service_customer_count'=>0]],'complete'=>true,'gaps'=>[]];
$eff = $reader->readEfficiency([7,8], $stores, '2026-10-01', '2026-10-30',
    [7=>['consumption_amount_cents'=>62300,'service_customer_count'=>623,'staff_count'=>7]],
    $beds,$staff,$consumptionGap,$serviceOrderGap);
check(count($eff['rows']) === 10 && $eff['days'] === 29 && $eff['rows'][4]['cells']['store_7'] === 6,
    '007 门店效能字段或床位/日期读取错误');
check($eff['rows'][3]['cells']['store_7'] === 21 && $eff['rows'][5]['cells']['store_7'] === 3,
    '007 日均与床位计算错误');
check($eff['rows'][9]['cells']['store_7'] === 0 && !$eff['complete'],
    '007 人效使用率须按 bcdiv 默认 scale=0 显示整数');
check($eff['rows'][0]['cells']['store_8'] === null && $eff['rows'][4]['cells']['store_8'] === 0,
    '缺少事实与确证零床位须区分');
$consumptionFull=static fn():array=>['by_store'=>[7=>62300,8=>0],
    'complete'=>true,'gaps'=>[]];
$autoStaff=$reader->readEfficiency([7,8],$stores,'2026-10-01','2026-10-30',[],
    $beds,$staff,$consumptionFull,$serviceOrderFull);
check($autoStaff['rows'][7]['cells']['store_7']===7
    &&$autoStaff['rows'][7]['cells']['store_8']===0,
    'V3 点客服务人员数应自动接入效能员工数');
check($autoStaff['rows'][0]['cells']['store_7']===62300
    &&$autoStaff['rows'][1]['cells']['store_7']===623
    &&$autoStaff['consumption_source']['by_store'][7]===62300,
    '服务子单身份桥应填金额和客数，项目消耗仅留作对账候选');

reject(static fn() => $reader->readPk([7], $stores, '2026-10', [], $coverage, $partner,$atomic,$payments,$legacy,$goals),
    'PK 越权门店须拒绝');
reject(static fn() => $reader->readPk([7,8], $stores, '2026-10', [],
    static fn(): array => [['store_id'=>9,'payment_fact_rows'=>1]], $partner,$atomic,$payments,$legacy,$goals), 'PK 事实越权须拒绝');
reject(static fn() => $reader->readPk([7,8], $stores, '2026-10', [7=>['goal_cents'=>1.2]], $coverage, $partner,$atomic,$payments,$legacy,$goals),
    'PK 手录金额须为整数分');
reject(static fn() => $reader->readEfficiency([7,8], $stores, '2026-10-01', '2026-10-30', [],
    static fn(): array => [['id'=>9,'space_num'=>2]],$staff,$consumptionGap,$serviceOrderGap), '床位来源越权须拒绝');

echo "sidebar-007-pk-efficiency-v3-read-contract PASS\n";
