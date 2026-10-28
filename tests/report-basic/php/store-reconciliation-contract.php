<?php
/** 真实统一查询执行器验证动态列、完整导出、精确下钻与边界；不依赖线上数据库。 */
error_reporting(E_ALL & ~E_DEPRECATED);
$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($backend): void {
    if (strpos($class, 'app\\') !== 0) return;
    $file = $backend . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
}, true, true);

use app\services\report\StoreReconciliationReportServices as Report;

$checks = 0;
function r11check(bool $condition, string $label): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($label);
}
function r11reject(callable $action, string $label): void {
    try { $action(); } catch (InvalidArgumentException | \app\services\query\UnifiedQueryException $e) { r11check(true, $label); return; }
    throw new RuntimeException('未拒绝：' . $label);
}
$stores = [['id'=>2,'name'=>'门店乙'],['id'=>1,'name'=>'门店甲']];
$methods = [['code'=>'legacy_1','name'=>'银联扫码'],['code'=>'brush_dianping','name'=>'大众刷单']];
for ($i=1;$i<=23;$i++) $methods[]=['code'=>'channel_'.$i,'name'=>'通道'.$i];
$receipts = [];
for($i=1;$i<=25;$i++) $receipts[]=['receipt_id'=>'r'.$i,'store_id'=>1,'business_date'=>'2026-09-30','method_code'=>'legacy_1',
    'amount_cents'=>101,'document_no'=>'单-'.$i,'source_kind'=>'历史订单收款','combined'=>false];
$receipts[]=['receipt_id'=>'brush','store_id'=>2,'business_date'=>'2026-09-01','method_code'=>'brush_dianping','amount_cents'=>32601,'document_no'=>'登记-1','source_kind'=>'刷单登记','combined'=>false];
$receipts[]=['receipt_id'=>'negative-combination','store_id'=>1,'business_date'=>'2026-09-29','method_code'=>'legacy_1','amount_cents'=>-1,'document_no'=>'组合负项','source_kind'=>'历史组合收款','combined'=>true];
$source = static function ($ids,$range) use ($stores,$methods,$receipts) { return ['stores'=>array_values(array_filter($stores,static fn($s)=>in_array($s['id'],$ids,true))),
    'methods'=>$methods,'receipts'=>array_values(array_filter($receipts,static fn($r)=>in_array($r['store_id'],$ids,true)&&$r['business_date']>=$range['start_date']&&$r['business_date']<=$range['end_date']))]; };
$report = new Report($source);
// 真实集团102家门店：完整矩阵和导出都必须保留，不能因执行器100字段上限截掉门店。
$wideStores = [];for($i=1;$i<=102;$i++)$wideStores[]=['id'=>$i,'name'=>'集团门店'.$i];
$wideReport = new Report(static function()use($wideStores){return ['stores'=>$wideStores,'methods'=>[['code'=>'legacy_1','name'=>'银联扫码']],'receipts'=>[]];});
$wideContext=['tenant_id'=>'0','visible_store_ids'=>range(1,102),'basic_scope_mode'=>'all','basic_actor_key'=>'platform:1','query_cutoff_date'=>'2026-10-28'];
$wideQuery=['pageCode'=>Report::CODE,'start_date'=>'2026-09-01','end_date'=>'2026-09-30'];
$wide=$wideReport->query($wideContext,$wideQuery);
r11check(count($wide['queryFields'])===104,'102门店及两个固定字段完整展示');
$wideFields=array_column($wide['queryFields'],'key');
$wideExport=$wideReport->query($wideContext,$wideQuery+['export'=>['scope'=>'query','fields'=>$wideFields]]);
r11check(count($wideExport['exportRows'])===1&&count($wideExport['exportRows'][0])===104,'宽表导出无截断或辅助身份列');
r11check($wideExport['exportRows'][0]['store_102']==='0.00','最后门店原值保留');
r11reject(static fn()=>$wideReport->query($wideContext,$wideQuery+['export'=>['scope'=>'query','fields'=>array_merge($wideFields,['store_999'])]]),'宽表分列仍验证越权字段');
$context=['tenant_id'=>'0','visible_store_ids'=>[1,2],'basic_scope_mode'=>'all','basic_actor_key'=>'platform:1','query_cutoff_date'=>'2026-10-28','data_as_of'=>1793145600];
$query=['pageCode'=>Report::CODE,'start_date'=>'2026-09-01','end_date'=>'2026-09-30'];
$first=$report->query($context,$query);
r11check(count($first['rows'])===20&&$first['pagination']['total']===25,'固定20条与完整数量');
r11check(array_column($first['queryFields'],'label')===['支付方式','合计','门店乙','门店甲'],'后端动态列和稳定门店顺序');
r11check($first['rows'][0]['store_1']==='25.25'&&$first['rows'][0]['total_amount']==='25.25','分精度及非正组合收款沿用原口径');
r11check($first['rows'][1]['store_2']==='326.01'&&$first['rows'][1]['store_1']==='0.00','刷单独立行和零值列');
$second=$report->query($context,$query+['page'=>2]);
r11check(count($second['rows'])===5&&$second['pagination']['total']===25,'最后一页余数');
$export=$report->query($context,$query+['page'=>2,'export'=>['scope'=>'query','fields'=>['payment_name','total_amount','store_2','store_1']]]);
r11check(count($export['exportRows'])===25&&$export['exportRows'][0]['total_amount']==='25.25','导出全量且精度不受分页影响');
$detail=$report->query($context,$query+['detail'=>['method_code'=>'legacy_1','column_key'=>'store_1']]);
r11check(count($detail['rows'])===20&&$detail['pagination']['total']===25,'精确下钻20条/全量25');
r11check((string)$detail['summaries']['amount:sum']==='25.250000','下钻合计逐分解释原格');
$detail2=$report->query($context,$query+['detail'=>['method_code'=>'legacy_1','column_key'=>'store_1'],'detail_page'=>2]);
r11check(count($detail2['rows'])===5,'明细第二页');
$daily=$report->query($context,$query+['reconciliation_mode'=>'days','reconciliation_store_id'=>1,'month'=>'2026-09']);
r11check(count($daily['queryFields'])===32&&$daily['rows'][0]['day_30']==='25.25','单店30日完整展开');
r11check($daily['rows'][0]['day_1']==='0.00','零收款日期保留');
$leap=$report->query($context,['start_date'=>'2024-02-01','end_date'=>'2024-02-29','reconciliation_mode'=>'days','reconciliation_store_id'=>1,'month'=>'2024-02']);
r11check(count($leap['queryFields'])===31,'闰年29日');
$filtered=$report->query($context,$query+['filters'=>[['field'=>'payment_name','operator'=>'eq','value'=>'大众刷单']]]);
r11check(count($filtered['rows'])===1&&$filtered['rows'][0]['total_amount']==='326.01','统一更多筛选');
$verified=$report->query($context,$query+['expected_fingerprint'=>$first['receipt_fingerprint'],'detail'=>['method_code'=>'legacy_1','column_key'=>'store_1']]);
r11check($verified['pagination']['total']===25,'未变更的查询可精确下钻');
r11reject(static fn()=>$report->query($context,$query+['expected_fingerprint'=>str_repeat('0',64),'detail'=>['method_code'=>'legacy_1','column_key'=>'store_1']]),'已变化下钻要求刷新');
r11reject(static fn()=>$report->query($context,$query+['expected_fingerprint'=>str_repeat('0',64),'export'=>['scope'=>'query','fields'=>['payment_name','total_amount']]]),'已变化导出要求刷新');
r11reject(static fn()=>$report->query($context,$query+['export'=>['scope'=>'query','fields'=>['store_999']]]),'越权动态列');
r11reject(static fn()=>$report->query($context,$query+['reconciliation_mode'=>'days','reconciliation_store_id'=>999,'month'=>'2026-09']),'越权单店');
r11reject(static fn()=>$report->query($context,$query+['detail'=>['method_code'=>'legacy_1','column_key'=>'store_999']]),'越权下钻');
r11reject(static fn()=>$report->query($context,$query+['filters'=>[['field'=>'payment_name','operator'=>'eq','value'=>'大众刷单']], 'detail'=>['method_code'=>'legacy_1','column_key'=>'store_1']]),'下钻保留已提交筛选');
r11reject(static fn()=>$report->query($context,$query+['filters'=>[['field'=>'not_registered','operator'=>'eq','value'=>'x']]]),'未知查询字段');
r11reject(static fn()=>$report->query($context,$query+['reconciliation_mode'=>'days','reconciliation_store_id'=>1,'month'=>'2026-13']),'无效月份');
r11reject(static fn()=>$report->query($context,$query+['reconciliation_mode'=>'days','reconciliation_store_id'=>1,'month'=>'2026-11']),'未来月份');
$empty=$report->query($context,['start_date'=>'2026-08-01','end_date'=>'2026-08-31']);
r11check($empty['rows'][0]['total_amount']==='0.00'&&$empty['pagination']['total']===25,'空期间保留支付目录');
echo json_encode(['checks'=>$checks,'passed'=>true],JSON_UNESCAPED_UNICODE)."\n";
