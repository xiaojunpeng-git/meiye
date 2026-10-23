<?php
declare(strict_types=1);

/** Read-only production verification. No start/end/color commands or real entitlement/fund writes. */
$site = $argv[1] ?? '';
$sites = ['rh' => ['/www/wwwroot/rh.cc3798.com', 'ruihao', '3306'], '008' => ['/www/wwwroot/008.cc3798.com', 'mohe_008', '3307'], '010' => ['/www/wwwroot/010.cc3798.com', 'mohe_010_app', '3307']];
if (!isset($sites[$site])) throw new RuntimeException('Explicit instance required');
[$root, $database, $port] = $sites[$site];
require $root . '/vendor/autoload.php';
(new think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $e): void { file_put_contents('php://stderr', $e->getMessage() . PHP_EOL); exit(1); });
$config = config('database.connections.mysql');
if ($config['database'] !== $database || (string)$config['hostport'] !== $port) throw new RuntimeException('Wrong instance');
// 新目录读取及岗位树只能包含可授权业务项；不把报表叶子写进岗位规则。
$catalog=new app\services\organization\StorePermissionCatalogServices();
$snapshot=$catalog->snapshot();if(count($snapshot['rules'])!==91)throw new RuntimeException('CATALOG_INCOMPLETE');
$scan=function($nodes)use(&$scan){foreach($nodes as $node){if(!empty($node['navigation_key']))throw new RuntimeException('DISPLAY_NODE_IN_GRANTS');$scan($node['children']??[]);}};$scan($catalog->menuTree());
// 当前实际员工解析必须成功；仅统计，不输出姓名/电话/授权明细。
$resolver=new app\services\cashier\v3\permission\CashierV3FeatureResolver();$checked=0;
foreach(think\facade\Db::name('system_store_staff')->where('status',1)->where('is_del',0)->select()->toArray() as $staff){$resolver->resolveGrantedFeatures($staff);$checked++;}
echo json_encode(['site'=>$site,'catalogRows'=>91,'staffPermissionReads'=>$checked,'result'=>'PASS']),PHP_EOL;
date_default_timezone_set('Asia/Shanghai');
think\facade\Db::startTrans();
try {
    $dispatcher = app\services\cashier\v3\bootstrap\CashierV3Bootstrap::dispatcher();
    $check = app\services\cashier\v3\bootstrap\CashierV3Bootstrap::lastSelfCheck();
    if (empty($check['ok']) || !in_array('mark-reservation-color', $check['activated'], true)) throw new RuntimeException('New command not activated');
    $policy = $dispatcher->policies()->requirePolicy('end-reservation-service');
    if ($policy->staticRequiredKinds() !== ['reservation']) throw new RuntimeException('End-service concurrency contract changed');
    // Rejection occurs before row lookup, and thus proves the deployed whitelist
    // without writing a marker to any real appointment.
    $method = new ReflectionMethod(app\services\cashier\v3\reservation\CashierV3ReservationModule::class, 'markColor');
    $method->setAccessible(true);
    try { $method->invoke(null, ['payload' => ['color' => 'orange']]); throw new RuntimeException('Invalid color accepted'); }
    catch (app\services\cashier\v3\CashierV3CommandException $e) { if (strpos($e->getMessage(), '紫') === false) throw new RuntimeException('Five-color validation missing'); }
    $row = think\facade\Db::name('cashier_v3_reservation')->alias('r')->whereIn('r.status', ['PENDING_CONFIRMATION','UNSTARTED','IN_SERVICE','COMPLETED'])
        ->whereExists(function ($query) { $query->table('eb_system_store_staff')->whereRaw('store_id=r.store_id')->where('is_del', 0)->where('status', 1); })
        ->order('r.id desc')->find();
    $records = 0; $blocks = 0; $catalogCount = 0;
    if ($row) {
        $store = (int)$row['store_id'];
        $staff = think\facade\Db::name('system_store_staff')->where('store_id', $store)->where('is_del', 0)->where('status', 1)->find();
        $org = (string)think\facade\Db::name('organization_store')->where('store_id', $store)->value('org_id');
        $operator = new app\services\cashier\v3\CashierV3OperatorScope($store, (int)$staff['id'], $org, (string)$row['tenant_id']);
        $scope = new app\services\cashier\v3\CashierV3DataScopeContext((int)$staff['id'], (int)$staff['employee_id'], $store, (string)$row['tenant_id'], $org, [$store], 'stores', [], false, '', 'r27-readonly', ['cashier.v3.reservation'], $staff);
        $part = (new app\services\cashier\v3\reservation\CashierV3ReservationPartitionProvider())->readPartition('', '', $operator, $scope, ['calendarDate' => date('Y-m-d', (int)$row['appointment_start_at']), 'pageSize' => 100]);
        $records = count($part['payload']['records']); $blocks = count($part['payload']['calendar']['blocks']);
        foreach ($part['payload']['calendar']['blocks'] as $block) {
            $header = think\facade\Db::name('cashier_v3_reservation')->where('id', (int)$block['reservationId'])->find();
            if ((string)$block['colorMark'] !== (string)$header['calendar_color'] || !in_array($block['colorMark'], ['', 'red','yellow','blue','green','purple'], true)) throw new RuntimeException('Calendar marker projection mismatch');
        }
        // Exercise the existing reservation project-catalog dependency as well
        // as loading the calendar, to catch a shared-module dependency regression.
        $catalogCount = count((new app\services\cashier\v3\cashier\CashierV3SaleCatalogServices())->reservationProjects($operator, $scope));
    }
    $contract = app\services\cashier\v3\manifest\CashierV3ActionManifest::eventContractFor('correct-service-order-attribution');
    if (empty($contract['required_event_types'])) throw new RuntimeException('Existing service attribution contract lost');
    echo json_encode(['result' => 'PASS', 'site' => $site, 'productionGraph' => true, 'fiveColorGuard' => true,
        'reservationRecords' => $records, 'calendarBlocks' => $blocks, 'existingReservationProjects' => $catalogCount,
        'existingServiceAttributionContract' => true, 'businessWrites' => 0], JSON_THROW_ON_ERROR), PHP_EOL;
} finally { think\facade\Db::rollback(); }
