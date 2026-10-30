<?php
declare(strict_types=1);

require_once __DIR__.'/../../../后端代码/vendor/autoload.php';
require_once __DIR__.'/../../../后端代码/app/services/report/Sidebar007PartnerAllocationReadServices.php';

use app\services\report\Sidebar007PartnerAllocationReadServices;

function check(bool $yes,string $message): void { if (!$yes) throw new RuntimeException($message); }
function reject(callable $fn,string $message): void
{
    try { $fn(); } catch (InvalidArgumentException|LogicException $e) { return; }
    throw new RuntimeException($message);
}

$reader=new Sidebar007PartnerAllocationReadServices();
$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
$result=$reader->read([7,8],$range,static fn(array $ids,array $period): array => [
    ['store_id'=>'7','partner_amount_cents'=>'34000','outsourced_amount_cents'=>'9000',
        'refund_deduction_cents'=>'-4000','unclassified_rows'=>'0','unsigned_reversal_rows'=>'0'],
    ['store_id'=>'8','partner_amount_cents'=>'12000','outsourced_amount_cents'=>'0',
        'refund_deduction_cents'=>'0','unclassified_rows'=>'2','unsigned_reversal_rows'=>'0'],
]);
check($result['by_store'][7]===34000 && $result['by_store'][8]===null,
    '已证实 partner 分配与未知类型缺口必须区分');
check(!$result['complete'] && count($result['gaps'])===1,
    '缺人员类型快照不得以部分聚合冒充完整结果');
check($result['separate_facts'][7]['outsourced_amount_cents']===9000
    && $result['separate_facts'][7]['refund_deduction_cents']===-4000
    && $result['by_store'][7]===34000,
    'partner、outsourced 与退款人员扣减须独立，不能混入合作方分成');
$empty=$reader->read([7],$range,static fn(): array => []);
check($empty['by_store'][7]===0 && $empty['complete'],
    '完整的授权事实查询无命中时才可返回真零');
$badReversal=$reader->read([7],$range,static fn(): array => [[
    'store_id'=>7,'partner_amount_cents'=>100,'outsourced_amount_cents'=>0,'refund_deduction_cents'=>0,
    'unclassified_rows'=>0,'unsigned_reversal_rows'=>1]]);
check($badReversal['by_store'][7]===null && !$badReversal['complete'],
    '正额 reversal 方向异常必须留缺口');
reject(static fn()=>$reader->read([7],$range,
    static fn(): array => [['store_id'=>8,'partner_amount_cents'=>0,'outsourced_amount_cents'=>0,
        'refund_deduction_cents'=>0,'unclassified_rows'=>0,'unsigned_reversal_rows'=>0]]),
    '越权门店结果须拒绝');
reject(static fn()=>$reader->read([7],$range,
    static fn(): array => [['store_id'=>7,'partner_amount_cents'=>'1.2','outsourced_amount_cents'=>0,
        'refund_deduction_cents'=>0,'unclassified_rows'=>0,'unsigned_reversal_rows'=>0]]),
    '金额必须使用整数分');
reject(static fn()=>$reader->read([7],['start'=>'2026-07-01','end'=>'2026-07-30'],
    static fn(): array => []),'V3 覆盖前不能把缺历史事实当零');

echo "sidebar-007-partner-allocation-contract PASS\n";
