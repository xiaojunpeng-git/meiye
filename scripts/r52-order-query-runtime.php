<?php
/** Actual deployed provider reads under one real store-admin scope and enforced read-only PDO; never log in/write/export tasks. */
$site=$argv[1]??''; $names=['rh'=>'ruihao','008'=>'mohe_008','010'=>'mohe_010_app'];
if(!isset($names[$site])) throw new RuntimeException('EXPLICIT_INSTANCE_REQUIRED');
$root='/www/wwwroot/'.($site==='rh'?'rh.cc3798.com':$site.'.cc3798.com');
require $root.'/vendor/autoload.php'; (new think\App($root.'/'))->initialize();
if(config('database.connections.mysql.database')!==$names[$site]) throw new RuntimeException('INSTANCE_DATABASE_MISMATCH');
$codeSources=[];
foreach(['AbstractCashierV3OrderCenterUnifiedQueryProvider','CashierV3OrderCenterUnifiedQueryContract'] as $class) {
    $relative='app/services/cashier/v3/order/'.$class.'.php';
    $loaded=realpath((new ReflectionClass('app\\services\\cashier\\v3\\order\\'.$class))->getFileName());
    if($loaded!==realpath($root.'/'.$relative) || strpos($loaded,$root.'/')!==0) throw new RuntimeException('INSTANCE_CODE_ROOT_MISMATCH');
    $codeSources[$relative]=hash_file('sha256',$loaded);
}
$connection=think\facade\Db::connect(); $connection->connect(); $connection->query('SELECT 1'); $pdo=$connection->getPdo();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
if((int)$pdo->query('SELECT @@session.tx_read_only')->fetchColumn()!==1) throw new RuntimeException('READ_ONLY_REQUIRED');
$admins=think\facade\Db::name('system_store_staff')->alias('a')->join('system_store b','a.store_id=b.id')->where('a.is_del',0)->where('a.status',1)->where('a.employee_id','>',0)->where('b.is_del',0)->where('a.store_id','>',0)->field('a.*')->order('a.id asc')->limit(300)->select()->toArray();
$staff=null; $latest='';
$featureResolver=app()->make(app\services\cashier\v3\permission\CashierV3FeatureResolver::class);
// Select only an actual assignment whose current position grants order reads; level=0 alone is not V3 authorization.
foreach($admins as $candidate) {
    if(!in_array('cashier.v3.order_center',$featureResolver->resolveGrantedFeatures($candidate),true)) continue;
    if($staff===null) $staff=$candidate;
    $store=(int)$candidate['store_id'];
    foreach(['cashier_v3_sales_order','cashier_v3_entitlement_service_fact'] as $table) {
        $day=(string)think\facade\Db::name($table)->where('store_id',$store)->order('business_date desc')->value('business_date');
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day) && $day>$latest) { $latest=$day; $staff=$candidate; }
    }
}
$platformOnly=$staff===null; $storeFilter=[];
if($staff) {
    // Match the actual login/worker model accessors and role normalization; raw SQL role JSON is not a session profile.
    $staff=app\model\store\SystemStoreStaff::where('id',(int)$staff['id'])->find()->toArray();
    $staff['roles']=app\services\cashier\v3\CashierV3PermissionSnapshotServices::normalizeRoleIds($staff['roles']??[]);
    $operator=(new app\services\cashier\v3\CashierV3ScopeResolver())->operatorScope((int)$staff['store_id'],(int)$staff['id']);
    $scope=app()->make(app\services\cashier\v3\CashierV3DataScopeFactory::class)->build($operator->storeId(),$operator->operatorId(),$staff,'0',$operator->organizationId());
} else {
    // Do not manufacture V3 position grants. Reuse the existing platform read-only authority when no staff sample is authorized.
    $admin=think\facade\Db::name('system_admin')->where('status',1)->where('is_del',0)->where('level',0)->order('id asc')->find();
    if(!$admin || (int)($admin['type']??0)!==0 || (int)($admin['admin_type']??0)!==0) throw new RuntimeException('ACTUAL_READ_IDENTITY_REQUIRED');
    $bridge=app()->make(app\services\order\PlatformOrderCenterReadServices::class);
    $method=new ReflectionMethod($bridge,'context'); $method->setAccessible(true); $trusted=$method->invoke($bridge,$admin);
    if(!$trusted) throw new RuntimeException('ACTUAL_PLATFORM_SCOPE_REQUIRED');
    [$operator,$scope]=$trusted;
    $stores=$scope->visibleStoreIds();
    $query=think\facade\Db::name('system_store')->where('is_del',0)->where('name','<>','总部');
    if($stores!==null) $query->whereIn('id',$stores);
    $sample=(int)$query->order('id asc')->value('id'); if(!$sample) throw new RuntimeException('AUTHORIZED_STORE_SAMPLE_REQUIRED');
    $storeFilter=[$sample];
    foreach(['cashier_v3_sales_order','cashier_v3_entitlement_service_fact'] as $table) {
        $day=(string)think\facade\Db::name($table)->where('store_id',$sample)->order('business_date desc')->value('business_date');
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day) && $day>$latest) $latest=$day;
    }
}
$runtime=app\services\cashier\v3\query\UnifiedQueryModule::runtime(); $results=[];
$to=$latest!==''?$latest:date('Y-m-d'); $from=date('Y-m-d',strtotime($to.' -6 days'));
try {
foreach(app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE as $type=>$pageCode) {
    $stage='list';
    $payload=['pageCode'=>$pageCode,'recordType'=>$type,'page'=>1,'limit'=>20,'pageSize'=>20,'dateFrom'=>$from,'dateTo'=>$to,'dataScope'=>'all','businessStatus'=>'','topFilters'=>[['field'=>'business_date','operator'=>'gte','value'=>$from],['field'=>'business_date','operator'=>'lte','value'=>$to]]];
    if($storeFilter) $payload['storeIds']=$storeFilter;
    $context=$runtime['contextFactory']->make($operator,$scope,$payload);
    $provider=clone $runtime['providers']->resolve($pageCode);
    $page=$provider->queryPage($context,$payload,$operator,$scope);
    $definition=app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract::definition($pageCode);
    $key=$definition['keywordFields'][0];
    $negative=$payload; $negative['topFilters'][]=['field'=>$key,'operator'=>'contains','value'=>'__R52_IMPOSSIBLE_7b3bba__'];
    $stage='negative_list'; $none=$provider->queryPage($context,$negative,$operator,$scope);
    if((int)$none['total']!==0) throw new RuntimeException('FILTER_NOT_APPLIED_'.$type);
    $pagination='not_applicable';
    if((int)$page['total']>20) {
        $next=$payload; $next['page']=2; $second=$provider->queryPage($context,$next,$operator,$scope);
        $ids=static function($rows){return array_map(static function($row){return ($row['recordType']??'').':'.($row['id']??'').':'.($row['record_id']??'');},$rows);};
        if(array_intersect($ids($page['records']),$ids($second['records']))) throw new RuntimeException('PAGINATION_OVERLAP_'.$type);
        $pagination='passed';
    }
    // Execute the frozen export plan in memory through the same actual provider; no export task/file is created.
    $planPayload=array_intersect_key($negative,array_flip(['pageCode','page','limit','topFilters','dataScope','businessStatus']));
    $stage='negative_export'; $plan=$runtime['execution']->validatedPlan($pageCode,[],$planPayload,$context);
    if(!$platformOnly) {
        $export=$provider->executeFrozenPlan($context,$plan,'query',[$key]);
        if((int)$export['pagination']['total']!==0 || count($export['exportRows'])!==0) throw new RuntimeException('EXPORT_FILTER_MISMATCH_'.$type);
    }
    $positive='empty_sample';
    if($page['records']) {
        $selected=$payload; $selected['topFilters'][]=['field'=>$key,'operator'=>'eq','value'=>$page['records'][0][$key]];
        $stage='positive_list'; $matched=$provider->queryPage($context,$selected,$operator,$scope);
        $selectedPlan=$runtime['execution']->validatedPlan($pageCode,[],array_intersect_key($selected,array_flip(['pageCode','page','limit','topFilters','dataScope','businessStatus'])),$context);
        if((int)$matched['total']<1) throw new RuntimeException('POSITIVE_FILTER_MISMATCH_'.$type);
        if(!$platformOnly) {
            $stage='positive_export'; $matchedExport=$provider->executeFrozenPlan($context,$selectedPlan,'query',[$key]);
            if((int)$matchedExport['pagination']['total']!==(int)$matched['total'] || !count($matchedExport['exportRows'])) throw new RuntimeException('POSITIVE_FILTER_EXPORT_MISMATCH_'.$type);
        }
        $positive=$platformOnly?'positive_list_passed_export_not_authorized':'passed';
    }
    $results[]=['type'=>$type,'rows'=>count($page['records']),'total'=>(int)$page['total'],'sourceStatus'=>$page['dataStatus']??'unknown','negativeFilter'=>'passed','positiveFilterAndExport'=>$positive,'frozenExport'=>$platformOnly?'not_authorized_platform_read_only':'passed','pagination'=>$pagination];
}
} catch(Throwable $error) {
    $code=method_exists($error,'getErrorCode')?$error->getErrorCode():(preg_match('/^[A-Z_]+$/D',$error->getMessage())?$error->getMessage():get_class($error));
    echo json_encode(['site'=>$site,'result'=>'FAIL','type'=>$type??'','stage'=>$stage??'','code'=>$code,'previousResults'=>$results,'businessWrites'=>0]),PHP_EOL; exit(1);
}
echo json_encode(['site'=>$site,'result'=>'PASS','readOnly'=>true,'codeSources'=>$codeSources,'period'=>[$from,$to],'scope'=>$platformOnly?'existing actual platform read-only authority; staff identity validation unavailable':'one actual active staff assignment with current order-read position grant','storeId'=>$storeFilter?$storeFilter[0]:$operator->storeId(),'authorizationMode'=>$scope->authorizationMode(),'results'=>$results,'businessWrites'=>0,'exportTasksCreated'=>0],JSON_UNESCAPED_SLASHES),PHP_EOL;
