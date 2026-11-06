<?php
declare(strict_types=1);

use app\services\report\SixDimensionAnalysisDetail;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreReportXlsxWriter;
use think\facade\Db;

// 本地只读对账；STDIN 可传唯一正本的原始 Git blob，以比较修改前后的全部汇总值。
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
$db = config('database.connections.mysql');
if ($db['hostname'] !== 'mysql' || $db['database'] !== 'ruihao') throw new RuntimeException('LOCAL_ONLY');
Db::execute('SET TRANSACTION READ ONLY'); Db::startTrans();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++; echo 'PASS ' . $label . "\n";
};
try {
    $baseline = stream_get_contents(STDIN);
    $old = null;
    if ($baseline !== '') {
        eval(substr(str_replace('final class StoreUnifiedReportPhaseFourServices', 'final class R53OriginalPhaseFourServices', $baseline), 5));
        $old = new app\services\report\R53OriginalPhaseFourServices();
    }
    $service = new StoreUnifiedReportPhaseFourServices();
    $range = ['start' => '2026-10-01', 'end' => '2026-10-31'];
    $scope = [132, 84];
    $input = ['_internal_all' => true];
    $report = 'six_dimension_analysis';
    $summary = $service->query($report, $scope, $range, $input);
    $check(count($summary['records']) > 0, '真实本地数据存在');
    $columns = array_column($summary['columns'], null, 'key');
    if ($old) {
        $prior = $old->query($report, $scope, $range, $input);
        $strip = static function (array $rows): array {
            foreach ($rows as &$row) unset($row['detail_store_key']); unset($row); return $rows;
        };
        $check($strip($summary['records']) === $prior['records'] && $summary['summary_row'] === $prior['summary_row'],
            '所有汇总业务值与改前 Git 正本一致');
    }
    $queries = 0;
    foreach (array_merge($summary['records'], [$summary['summary_row']]) as $row) {
        foreach ($columns as $metric => $column) {
            if (in_array($metric, ['company_name','store_name'], true)) continue;
            $check(($column['drilldown']['report'] ?? '') === $report, $metric . ' 明确声明同源下钻');
            if (($row[$metric] ?? '-') === '-') continue;
            $detail = $service->query($report, $scope, $range, $input + [
                'metric_code' => $metric, 'detail_store_key' => $row['detail_store_key'] ?? 'all',
            ]);
            $queries++;
            $check(is_numeric($row[$metric]) ? abs((float)$detail['metric_value'] - (float)$row[$metric]) < 0.001
                : $detail['metric_value'] === $row[$metric], ($row['store_name'] ?? '合计') . ' ' . $metric . ' 明细还原原值');
            $check(count($detail['records']) === $detail['total'], $metric . ' 全量导出行数一致');
            foreach ($detail['records'] as $record) {
                $check(array_key_exists('member_phone', $record) && !array_key_exists('member_id', $record) && !array_key_exists('_amount', $record), $metric . ' 展示字段边界');
            }
        }
    }
    $row = $summary['records'][0];
    $filter = json_encode([['key'=>'store_name','op'=>'contains','value'=>$row['store_name']]], JSON_UNESCAPED_UNICODE);
    $filtered = $service->query($report, $scope, $range, $input + ['row_filters'=>$filter]);
    $detail = $service->query($report, $scope, $range, $input + ['row_filters'=>$filter,'metric_code'=>'all_received_performance','detail_store_key'=>'all']);
    $check((float)$detail['metric_value'] === (float)$filtered['summary_row']['all_received_performance'], '合计明细重放更多筛选');
    $empty = $service->query($report, $scope, $range, ['metric_code'=>'debt','detail_store_key'=>'all','detail_member'=>'不存在的测试姓名R53']);
    $check($empty['total'] === 0 && (float)$empty['metric_value'] === 0.0, '空结果不残留旧合计');
    foreach ([['metric_code'=>'invalid','detail_store_key'=>'all'], ['metric_code'=>'debt','detail_store_key'=>'forged'],
        ['metric_code'=>'debt','detail_store_key'=>'all','_report_scope'=>['mode'=>'none']],
        ['metric_code'=>'debt','detail_store_key'=>'all','_report_scope'=>['mode'=>'self_participant','employee_id'=>0]]] as $bad) {
        try { $service->query($report, $scope, $range, $bad); throw new RuntimeException('BAD_SCOPE_ACCEPTED'); }
        catch (InvalidArgumentException $expected) { $check(true, '非法指标或权限范围被拒绝'); }
    }
    // 纯贡献夹具不写库：同人多笔服务、新老两种身份、跨门店和超过20条明细。
    $parents = [];
    foreach (['A|1','B|2'] as $group) $parents[] = ['company_name'=>explode('|',$group)[0], 'store_name'=>$group,
        'detail_store_key'=>SixDimensionAnalysisDetail::rowKey($group)];
    $ledger = new SixDimensionAnalysisDetail('total_visit_people');
    for ($i=1;$i<=25;$i++) {
        $fact = ['member_id'=>0,'member_name'=>'会员'.$i,'business_date'=>'2026-10-01','document_no_snapshot'=>'FW'.$i];
        $ledger->add('A|1','new_visit_people',$fact,1,(string)$i);
        $ledger->add('A|1','new_visit_people',$fact+['unused'=>true],1,(string)$i);
    }
    $ledger->add('A|1','old_visit_people',['member_name'=>'会员1'],1,'1');
    $ledger->add('B|2','new_visit_people',['member_name'=>'会员1'],1,'1');
    $fixture = $ledger->result($parents, $summary['columns'], $range, ['detail_store_key'=>'all']);
    $check($fixture['total'] === 27 && $fixture['metric_value'] === 27 && count($fixture['records']) === 20, '跨组不去重、组内去重、第一页20条');
    $page2 = $ledger->result($parents, $summary['columns'], $range, ['detail_store_key'=>'all','page'=>2]);
    $check(count($page2['records']) === 7 && $page2['metric_value'] === 27, '第二页7条且合计全量');
    $search = $ledger->result($parents, $summary['columns'], $range, ['detail_store_key'=>'all','detail_order_no'=>'FW25']);
    $check($search['total'] === 1, '单号搜索覆盖所有页');
    $export = $ledger->result($parents, $summary['columns'], $range, ['detail_store_key'=>'all','_internal_all'=>true]);
    $check(count($export['records']) === 27, '导出完整匹配明细');
    $cash = new SixDimensionAnalysisDetail('all_received_performance');
    $cash->add('A|1','self_received_performance',['order_no_snapshot'=>'XS1'],10059);
    $cash->add('A|1','self_received_performance',['order_no_snapshot'=>'TK1'],-1034);
    $cash->add('B|2','partner_received_performance',['order_no_snapshot'=>'XS2'],20000);
    $cashResult = $cash->result($parents, $summary['columns'], $range, ['detail_store_key'=>'all','_internal_all'=>true]);
    $check($cashResult['metric_value'] === '290.25' && array_sum(array_column($cashResult['records'],'detail_value')) === 290.25,
        '正负收款与合作分项守恒且导出保留分精度');
    $ratio = new SixDimensionAnalysisDetail('new_deal_rate');
    $ratio->add('A|1','new_deal_people',[],1,'1');
    foreach (range(1,3) as $id) $ratio->add('A|1','new_visit_people',[],1,(string)$id);
    $ratioResult = $ratio->result($parents, $summary['columns'], $range, ['detail_store_key'=>'all']);
    $check($ratioResult['metric_value'] === '33.33%' && $ratioResult['summary_row']['detail_value'] === '-',
        '成交率显示分子分母且不累加不同统计项');
    $zeroRatio = (new SixDimensionAnalysisDetail('new_deal_rate'))->result($parents, $summary['columns'], $range, ['detail_store_key'=>'all']);
    $check($zeroRatio['metric_value'] === '-', '零分母不显示误导百分比');
    $xlsx = (new StoreReportXlsxWriter())->render($export, $export['title']);
    $check(substr($xlsx,0,2)==='PK', '明细统一 XLSX 可生成');
    echo json_encode(['result'=>'PASS','checks'=>$checks,'realDetailQueries'=>$queries],JSON_UNESCAPED_UNICODE)."\n";
} finally { Db::rollback(); }
