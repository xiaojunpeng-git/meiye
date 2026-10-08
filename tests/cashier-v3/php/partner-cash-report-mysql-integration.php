<?php
declare(strict_types=1);

// 本地 RH 副本专项回归；所有模拟跨日收款、卡项和作废均在同一事务中回滚，禁止用于生产。
if (getenv('MOHE_PARTNER_CASH_LOCAL_TEST') !== 'yes') {
    throw new RuntimeException('Explicit local transactional test opt-in required');
}
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\report\StoreUnifiedReportServices;
$app = new think\App('/var/www/html/');
$app->initialize();
// 框架的网页异常处理可能返回成功退出码；专项 CLI 测试必须明确失败，不能误报自测通过。
set_exception_handler(function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);
    exit(1);
});
$count = 0;
function checkPartnerCash(bool $ok, string $name): void {
    global $count;
    if (!$ok) throw new RuntimeException($name);
    ++$count;
    echo 'PASS '.$name.PHP_EOL;
}
function partnerCashReport(string $report, string $day = '2026-10-08', array $extra = []): array {
    return (new StoreUnifiedReportServices())->query([126], array_merge([
        'report'=>$report,'start_date'=>$day,'end_date'=>$day,'category_path_exact'=>'花园 / 花园产品','limit'=>100,
    ], $extra));
}
function cashTotal(array $result): string {
    return (string)($result['summary_row']['deal_amount'] ?? $result['summary_row']['sale_amount']);
}
$sale = Db::name('cashier_v3_sale_fact')->where('store_id',126)->where('order_no_snapshot','XS26100800196')->where('status','effective')->find();
if (!$sale || (int)$sale['sale_amount_cents'] !== 208600) throw new RuntimeException('local fixture missing');
$allocation = Db::name('cashier_v3_payment_sale_allocation_fact')->where('sale_fact_id',$sale['fact_id'])->where('status','effective')->find();
$payment = Db::name('cashier_v3_payment_fact')->where('fact_id',$allocation['payment_fact_id'])->find();
$cardTemplate = Db::name('cashier_v3_card_sale_category_allocation_fact')->order('id')->find();
if (!$cardTemplate || !$payment || (int)$allocation['amount_cents'] !== 10600) throw new RuntimeException('local payment fixture missing');
$before = Db::name('cashier_v3_payment_sale_allocation_fact')->where('store_id',126)->count();
Db::startTrans();
try {
    $summary = partnerCashReport('partner_item_summary');
    $detail = partnerCashReport('partner_item_detail');
    checkPartnerCash(cashTotal($summary)==='2490' && cashTotal($detail)==='2490','summary/detail exclude 1980 unpaid debt');
    checkPartnerCash($detail['total']===3 && $detail['records'][0]['deal_amount']==='106','partial-paid line remains one row with 106 yuan');
    // 汇总说明必须与实际计数及日期一致，且由查询和导出共用，不能退回前端通用套话。
    $summaryExplanations=array_column($summary['columns'],'source_explanation','key');
    $plainSummary=count($summaryExplanations)===9;
    foreach ($summaryExplanations as $text) {
        if ($text==='' || preg_match('/统一事实|销售事实|快照|SQL|Reader|tenant|同一口径/u',$text)) $plainSummary=false;
    }
    checkPartnerCash($plainSummary,'all summary columns have plain business explanations');
    checkPartnerCash(strpos($summaryExplanations['experience_count'],'购买数量累计')!==false
        && strpos($summaryExplanations['member_count'],'同一会员重复成交只算1人')!==false
        && strpos($summaryExplanations['member_count'],'不直接相加')!==false
        && $summary['summary_row']['member_count']==='-'
        && strpos($summaryExplanations['month'],'补交收款业务日期')!==false
        && strpos($summaryExplanations['sale_amount'],'成交2086元、已收106元、欠1980元')!==false,'summary explanations distinguish quantity member dedup and collection dates');
    $summaryExport=(new StoreUnifiedReportServices())->export([126],['report'=>'partner_item_summary','start_date'=>'2026-10-08','end_date'=>'2026-10-08','category_path_exact'=>'花园 / 花园产品']);
    checkPartnerCash(array_column($summaryExport['columns'],'source_explanation','key')===$summaryExplanations
        && $summaryExport['records']===$summary['records']
        && $summaryExport['summary_row']===$summary['summary_row'],'summary export shares explanations and unchanged business results');
    // 新列只展示原销售单号，不改现金口径；顺序、固定宽度和导出必须统一。
    $keys=array_column($detail['columns'],'key');
    $numberIndex=array_search('order_no_snapshot',$keys,true);
    checkPartnerCash($numberIndex!==false && $keys[$numberIndex+1]==='performance_type'
        && $detail['columns'][$numberIndex]['label']==='销售单号'
        && $detail['columns'][$numberIndex]['fixed']==='left'
        && $detail['columns'][$numberIndex]['fixed_width']===165,'sale number column fixed immediately before performance type');
    checkPartnerCash(array_column($detail['records'],'order_no_snapshot')===['XS26100800196','XS26100800170','XS26100800169']
        && $detail['summary_row']['order_no_snapshot']==='-','sale number snapshots shown without summing identifiers');
    // 每列都须给出可理解的实际来源；人工信息与自动统计分开，不能用技术术语或通用套话遮掩。
    $explanations=array_column($detail['columns'],'source_explanation','key');
    $plain=true;
    foreach ($explanations as $text) {
        if ($text==='' || preg_match('/统一事实|快照|SQL|Reader|tenant|同一口径/u',$text)) $plain=false;
    }
    checkPartnerCash(count($explanations)===count($detail['columns']) && $plain,'all detail columns have plain business explanations');
    $manual=true;
    foreach (['medical_elevation','medical_followup','expert_name','remark'] as $key) {
        if (strpos($explanations[$key],'人工填写')===false) $manual=false;
    }
    checkPartnerCash($manual,'manual report fields clearly identified');
    checkPartnerCash(strpos($explanations['deal_amount'],'成交2086元、已收106元、欠1980元')!==false
        && strpos($explanations['consumption'],'不是次数')!==false,'cash debt example and consumption units explicit');
    checkPartnerCash((int)$detail['summary_row']['quantity']===15 && (int)$summary['records'][0]['member_count']===2,'original activity counts and summary member dedup unchanged');
    // 移除列必须贯穿元数据、明细、合计和导出，不能只在页面隐藏。
    checkPartnerCash(!in_array('deal_headcount',$keys,true)
        && !array_key_exists('deal_headcount',$detail['summary_row'])
        && !array_filter($detail['records'],static function(array $row): bool {return array_key_exists('deal_headcount',$row);}), 'detail headcount removed from columns records and total');
    $export=(new StoreUnifiedReportServices())->export([126],['report'=>'partner_item_detail','start_date'=>'2026-10-08','end_date'=>'2026-10-08','category_path_exact'=>'花园 / 花园产品']);
    checkPartnerCash(count($export['records'])===3 && $export['summary_row']['deal_amount']==='2490','export shares detail population and total');
    checkPartnerCash(array_column($export['columns'],'key')===$keys
        && array_column($export['records'],'order_no_snapshot')===array_column($detail['records'],'order_no_snapshot'),'export shares sale number column order and values');
    checkPartnerCash(array_column($export['columns'],'source_explanation','key')===$explanations,'export uses the same plain column explanations');
    checkPartnerCash(!array_key_exists('deal_headcount',$export['summary_row'])
        && !array_filter($export['records'],static function(array $row): bool {return array_key_exists('deal_headcount',$row);}), 'export does not retain removed headcount field');
    $outside=(new StoreUnifiedReportServices())->query([23],['report'=>'partner_item_detail','start_date'=>'2026-10-08','end_date'=>'2026-10-08','category_path_exact'=>'花园 / 花园产品']);
    checkPartnerCash($outside['total']===0,'other store cannot read fixture');
    $personal=partnerCashReport('partner_item_detail','2026-10-08',['_report_scope'=>['mode'=>'self_participant','employee_id'=>2147483647]]);
    checkPartnerCash($personal['total']===0,'server participant scope guards sale and payment populations');
    $wrongCategory=partnerCashReport('partner_item_detail','2026-10-08',['category_path_exact'=>'花园 / 不存在']);
    checkPartnerCash($wrongCategory['total']===0,'exact drilldown does not include sibling category');
    $unfiltered=partnerCashReport('partner_item_detail','2026-10-08',['category_path_exact'=>'']);
    $categoryRows=array_filter($unfiltered['records'],static function(array $r): bool {return $r['category_path_snapshot']==='花园 / 花园产品';});
    checkPartnerCash(array_sum(array_column($categoryRows,'sale_amount_cents'))===249000,'unfiltered detail uses the same category cash population');
    Db::name('cashier_v3_payment_sale_allocation_fact')->where('id',$allocation['id'])->update(['status'=>'failed']);
    $unpaid=partnerCashReport('partner_item_detail');
    checkPartnerCash($unpaid['total']===3 && $unpaid['records'][0]['deal_amount']==='0' && (int)$unpaid['records'][0]['quantity']===7,'unpaid sale remains visible without counting receivables as cash');
    Db::name('cashier_v3_payment_sale_allocation_fact')->where('id',$allocation['id'])->update(['status'=>'effective']);
    Db::name('cashier_v3_sale_fact')->where('id',$sale['id'])->update(['tenant_id'=>'D2-FOREIGN-TENANT']);
    $foreign=partnerCashReport('partner_item_detail');
    checkPartnerCash($foreign['total']===2 && cashTotal($foreign)==='2384','foreign tenant cannot contribute sale activity or cash');
    Db::name('cashier_v3_sale_fact')->where('id',$sale['id'])->update(['tenant_id'=>'0']);
    $outsideOrganization=partnerCashReport('partner_item_detail','2026-10-08',['company_dimension_id'=>'D2-NONEXISTENT']);
    checkPartnerCash($outsideOrganization['total']===0,'organization filter cannot widen store scope');

    // 同一成交日多笔收款应合并，不重复展示数量；补交行金额不借用原成交额。
    $copy=$allocation;
    unset($copy['id']);
    $copy['allocation_fact_id']='D2-CASH-SPLIT';$copy['natural_key']='D2-CASH-SPLIT';
    $copy['amount_cents']=1000;
    Db::name('cashier_v3_payment_sale_allocation_fact')->where('id',$allocation['id'])->update(['amount_cents'=>9600]);
    Db::name('cashier_v3_payment_sale_allocation_fact')->insert($copy);
    $split=partnerCashReport('partner_item_detail');
    checkPartnerCash($split['total']===3 && $split['records'][0]['deal_amount']==='106' && cashTotal($split)==='2490','multiple same-day collections merge exactly once');

    // 旧销售日在查询期外时，收款仍应归入真实收款日。
    Db::name('cashier_v3_sale_fact')->where('id',$sale['id'])->update(['business_date'=>'2026-10-07']);
    $cross=partnerCashReport('partner_item_detail');
    checkPartnerCash(cashTotal($cross)==='2490' && $cross['records'][0]['quantity']===0,'prior-day sale collection survives original sale-date filter');
    $repayment=$payment;unset($repayment['id']);
    $repayment['fact_id']='D2-CASH-REPAYMENT';$repayment['natural_key']='D2-CASH-REPAYMENT';
    $repayment['order_id']='D2-CASH-REPAYMENT';$repayment['source_document_type']='debt_repayment';
    $repayment['amount_cents']=198000;$repayment['business_date']='2026-10-09';
    Db::name('cashier_v3_payment_fact')->insert($repayment);
    $later=$copy;$later['allocation_fact_id']='D2-CASH-LATER';$later['natural_key']='D2-CASH-LATER';
    $later['payment_fact_id']=$repayment['fact_id'];$later['business_date']='2026-10-09';$later['amount_cents']=198000;
    Db::name('cashier_v3_payment_sale_allocation_fact')->insert($later);
    $laterDetail=partnerCashReport('partner_item_detail','2026-10-09');
    checkPartnerCash(cashTotal($laterDetail)==='1980' && cashTotal(partnerCashReport('partner_item_summary','2026-10-09'))==='1980','debt repayment counted on repayment date in both reports');
    checkPartnerCash($laterDetail['total']===1 && (int)$laterDetail['summary_row']['quantity']===0
        && (int)partnerCashReport('partner_item_summary','2026-10-09')['records'][0]['member_count']===0,'repayment does not fabricate a new sale activity');
    checkPartnerCash($laterDetail['records'][0]['order_no_snapshot']==='XS26100800196','cross-day repayment still displays original sale number');
    checkPartnerCash(cashTotal(partnerCashReport('partner_item_detail'))==='2490','future repayment not counted in original day');
    Db::name('cashier_v3_payment_sale_allocation_fact')->where('allocation_fact_id',$later['allocation_fact_id'])->update(['status'=>'failed']);
    checkPartnerCash(partnerCashReport('partner_item_detail','2026-10-09')['total']===0,'failed collection excluded');
    Db::name('cashier_v3_payment_sale_allocation_fact')->where('allocation_fact_id',$later['allocation_fact_id'])->update(['status'=>'effective']);
    $void=Db::name('cashier_v3_order_center_void_operation')->order('id')->find();
    if (!$void) throw new RuntimeException('local void template missing');
    unset($void['id']);$void['operation_id']='D2-CASH-VOID';$void['natural_key']='D2-CASH-VOID';
    $void['operation_no']='D2-CASH-VOID';$void['command_idempotency_key']='D2-CASH-VOID';$void['record_id']='D2-CASH-VOID';
    $void['source_id']=$repayment['order_id'];$void['source_kind']='sales_supplement';$void['tenant_id']='0';$void['store_id']=126;$void['status']='succeeded';
    // 自然键字段随实际表结构保留，不允许往不存在的列插入。
    $columns=array_column(Db::query('SHOW COLUMNS FROM eb_cashier_v3_order_center_void_operation'),'Field');
    $void=array_intersect_key($void,array_flip($columns));
    Db::name('cashier_v3_order_center_void_operation')->insert($void);
    checkPartnerCash(partnerCashReport('partner_item_detail','2026-10-09')['total']===0,'voided repayment excluded');

    // 卡项收款按原组件权重分摊；精确分类下钻不泄漏另一组件金额。
    Db::name('cashier_v3_sale_fact')->where('id',$sale['id'])->update(['source_type'=>'card','business_date'=>'2026-10-08']);
    foreach ([['D2-CARD-A',26321,'花园 / 花园产品',60],['D2-CARD-B',26322,'花园 / 其他产品',40]] as $c) {
        $component=$cardTemplate;unset($component['id']);
        $component['allocation_fact_id']=$c[0];$component['natural_key']=$c[0];$component['tenant_id']='0';
        $component['store_id']=126;$component['sale_fact_id']=$sale['fact_id'];$component['source_line_id']=$sale['source_line_id'];
        $component['order_id']=$sale['order_id'];$component['category_id_snapshot']=$c[1];$component['category_path_snapshot']=$c[2];
        $component['partner_name_snapshot']=$c[2];$component['configured_amount_cents']=$c[3];$component['sale_amount_cents']=$c[3];$component['status']='effective';
        Db::name('cashier_v3_card_sale_category_allocation_fact')->insert($component);
    }
    $card=partnerCashReport('partner_item_detail');
    checkPartnerCash($card['records'][0]['deal_amount']==='64' && cashTotal($card)==='2448','card cash uses component allocation and integer display');
    $sibling=partnerCashReport('partner_item_detail','2026-10-08',['category_path_exact'=>'花园 / 其他产品']);
    checkPartnerCash($sibling['total']===1 && $sibling['records'][0]['sale_amount_cents']===4240,'sibling receives only its 40 percent cash share');
    $cardExport=(new StoreUnifiedReportServices())->export([126],['report'=>'partner_item_detail','start_date'=>'2026-10-08','end_date'=>'2026-10-08','category_path_exact'=>'花园 / 其他产品']);
    checkPartnerCash($cardExport['records'][0]['sale_amount_cents']===4240,'export retains cents precision');

    // 大于页面上限的稳定行集必须全部导出，页面合计仍覆盖完整结果。
    Db::name('cashier_v3_payment_sale_allocation_fact')->where('allocation_fact_id',$later['allocation_fact_id'])->delete();
    for ($i=0;$i<105;$i++) {
        $many=$copy;$many['allocation_fact_id']='D2-MANY-'.$i;$many['natural_key']='D2-MANY-'.$i;
        $many['business_date']=date('Y-m-d',strtotime('2026-10-01 +'.$i.' days'));$many['amount_cents']=100;
        Db::name('cashier_v3_payment_sale_allocation_fact')->insert($many);
    }
    $input=['report'=>'partner_item_detail','start_date'=>'2026-10-01','end_date'=>'2027-01-31','category_path_exact'=>'花园 / 其他产品','limit'=>10];
    $page=(new StoreUnifiedReportServices())->query([126],$input);
    $all=(new StoreUnifiedReportServices())->export([126],$input);
    checkPartnerCash($page['total']===105 && count($page['records'])===10 && count($all['records'])===105,'export not truncated by pagination or 100-row cap');
    checkPartnerCash($page['summary_row']['deal_amount']===$all['summary_row']['deal_amount'],'full summary independent of current page');
} finally {
    Db::rollback();
}
checkPartnerCash(Db::name('cashier_v3_payment_sale_allocation_fact')->where('store_id',126)->count()===$before,'all local simulation facts rolled back');
checkPartnerCash((int)Db::name('cashier_v3_sale_fact')->where('id',$sale['id'])->value('sale_amount_cents')===208600,'original sale amount unchanged');
echo 'partner cash report integration: '.$count.' checks passed'.PHP_EOL;
