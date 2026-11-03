<?php
declare(strict_types=1);

/**
 * 仅在本地 Ruihao 镜像库运行：真实生成超过一万来源会员的跨店快照，
 * 并核对两张表与逐店权威行一致。
 * 不接收数据库、门店或账号参数，避免被误作跨实例线上探针。
 */
require getcwd() . '/vendor/autoload.php';
(new think\App(getcwd() . '/'))->initialize();
$db = config('database.connections.mysql');
if (($db['hostname'] ?? '') !== 'mysql' || ($db['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('仅允许本地 Ruihao 镜像库');
}
$stores = [100, 115, 47, 53, 173];
foreach (['basic_unconsumed_summary', 'basic_unconsumed_detail'] as $code) {
    $context = app\services\report\BasicReportHostServices::context(
        $stores, 'stores', 999901, 'platform', false, 0, $code);
    $taskService = new app\services\report\BasicUnconsumedTaskServices();
    $payload = ['pageCode' => $code, 'start_date' => $context['query_cutoff_date'],
        'end_date' => $context['query_cutoff_date'], 'as_of_date' => $context['query_cutoff_date'],
        'page' => 1, 'limit' => 20];
    $historical = $payload;
    $historical['as_of_date'] = (new DateTimeImmutable($context['query_cutoff_date']))
        ->modify('-1 day')->format('Y-m-d');
    try {
        app\services\report\BasicReportHostServices::query($context, $historical);
        throw new RuntimeException('历史日期绕过当前权益口径');
    } catch (InvalidArgumentException $expectedDenied) { /* 后台任务与同步来源同样拒绝。 */ }
    $pending = app\services\report\BasicReportHostServices::query($context, $payload);
    if (empty($pending['async_report']['pending']) || isset($pending['rows'])) {
        throw new RuntimeException('生成中泄露部分报表');
    }
    $id = $pending['async_report']['task_id'];
    if (!$taskService->process($id)) throw new RuntimeException('后台生成失败：' . $code);
    $payload['unconsumed_task_id'] = $id;
    $result = app\services\report\BasicReportHostServices::query($context, $payload);
    $expected = 0;
    $expectedRights = '0';
    foreach ($stores as $store) {
        $source = (new app\services\report\Sidebar007UnconsumedV3ReadServices())->read(
            $code, $stores, ['_report_scope' => ['mode' => 'stores', 'operator_id' => 999901,
                'tenant_id' => '0', 'visible_store_ids' => $stores],
                'as_of_date' => $context['query_cutoff_date']], null, null, [$store]);
        $expected += count($source['rows']);
        foreach ($source['rows'] as $row) {
            if (isset($row['rights_amount'])) $expectedRights = bcadd($expectedRights, (string)$row['rights_amount'], 2);
        }
    }
    if (($result['pagination']['total'] ?? -1) !== $expected
        || count($result['rows'] ?? []) !== min(20, $expected)
        || ($result['unconsumed_task_id'] ?? '') !== $id) {
        throw new RuntimeException('全店结果与逐店权威行数不符：' . $code);
    }
    $actualRights = $result['summaries']['rights_amount:sum'] ?? null;
    if ($actualRights !== null && bccomp((string)$actualRights, $expectedRights, 2) !== 0) {
        throw new RuntimeException('完整权益金额合计与权威逐店结果不符：' . $code);
    }
    $other = app\services\report\BasicReportHostServices::context(
        $stores, 'stores', 999902, 'platform', false, 0, $code);
    try {
        $taskService->resolve($code, $stores, $other, $id);
        throw new RuntimeException('不同账号复用了私有报表');
    } catch (InvalidArgumentException $expectedDenied) { /* 任务身份隔离通过。 */ }
    $fields = array_column(array_filter($result['queryFields'], static fn($f) => $f['defaultVisible']), 'key');
    $file = $taskService->exportXlsx($context, $payload + ['export' => ['scope' => 'query', 'fields' => $fields]]);
    $zip=new ZipArchive();
    if($zip->open($file['path'])!==true)throw new RuntimeException('导出不是真正 XLSX');
    $xml=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();
    if($xml===false||substr_count($xml,'<row ')<$expected+2){
        throw new RuntimeException('XLSX 导出缺少完整查询结果：'.$code);
    }
    @unlink($file['path']);
    echo $code . ' PASS rows=' . $expected . ' page=' . count($result['rows']) . PHP_EOL;
}
