<?php
declare(strict_types=1);

/*
 * Real V3 command-path probe for migrated card 104566.  It borrows an already
 * authorised staff session at the card's fixed store, never changes credentials,
 * and deliberately keeps the session token out of output.
 */
$phase = in_array('--staff', $argv, true) ? 'staff' : (in_array('--create-keep', $argv, true) ? 'create-keep' : (in_array('--create-void', $argv, true) ? 'create-void' : (in_array('--void', $argv, true) ? 'void' : (in_array('--selector', $argv, true) ? 'selector' : (in_array('--probe', $argv, true) ? 'probe' : '')))));
if ($phase === '') throw new RuntimeException('use --probe, --staff, --selector, --create-keep, --create-void or --void');
$execute = in_array('--execute', $argv, true);
$allowRuihao = in_array('--allow-ruihao', $argv, true);
if (in_array($phase, ['create-keep', 'create-void', 'void'], true) && (!$execute || !$allowRuihao)) {
    throw new RuntimeException('refuse: real ruihao test write requires --allow-ruihao --execute');
}
$root = getenv('MOHE_RH_APP_ROOT') ?: '/www/wwwroot/rh.cc3798.com';
require $root.'/vendor/autoload.php';
$app = new think\App();
$app->initialize();

$storeId = 123;
$staffRows = think\facade\Db::name('system_store_staff')->where('store_id',$storeId)->where('status',1)->where('is_del',0)->order('id','asc')->select()->toArray();
$token = '';
$staffId = 0;
$attempts = [];
$login = app()->make(\app\services\cashier\LoginServices::class);
foreach ($staffRows as $staff) {
    try {
        $session = $login->getLoginResult((int)$staff['id'], 'cashier_v3');
        if (!empty($session['token'])) {
            $token = (string)$session['token'];
            $staffId = (int)$staff['id'];
            break;
        }
    } catch (Throwable $error) {
        $attempts[] = ['staff_id'=>(int)$staff['id'],'reason'=>$error->getMessage()];
    }
}
if ($token === '') throw new RuntimeException('no authorised cashier-v3 staff at store 123: '.json_encode($attempts, JSON_UNESCAPED_UNICODE));
$sessionId = 'SESSION-c1a3d2e4-1045-4a66-8b77-000000000001';
$post = static function (array $payload) use ($token): array {
    $curl = curl_init('https://rh.cc3798.com/cashierapi/v3/workbenches/actions');
    curl_setopt_array($curl, [
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>[
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Source: f76d38d0ee4f854f',
            'Authori-zation: Bearer '.$token,
        ],
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT=>30,
    ]);
    $raw = curl_exec($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    $response = json_decode((string)$raw, true);
    return ['http_status'=>$httpStatus, 'curl_error'=>$curlError, 'response'=>is_array($response) ? $response : ['raw'=>(string)$raw]];
};
$bootstrap = $post([
    'action'=>'open-cashier-workbench',
    'clientSessionId'=>$sessionId,
    'correlationId'=>'CORR-104566000-0000-4000-8000-000000000001',
    'contextSwitchEpoch'=>1,
    'contextSwitchToken'=>'TIMECARD-104566-PROBE',
]);
$bootstrapData = (array)($bootstrap['response']['data'] ?? []);
$stateContextId = (string)($bootstrapData['stateContextId'] ?? '');
$bootstrapStatus = (string)($bootstrapData['result']['status'] ?? '');
if ($bootstrapStatus !== 'success' || $stateContextId === '') {
    echo json_encode(['status'=>$phase,'store_id'=>$storeId,'staff_id'=>$staffId,'bootstrap'=>$bootstrap], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(1);
}
if ($phase === 'probe') {
    echo json_encode([
        'status'=>'probe',
        'store_id'=>$storeId,
        'staff_id'=>$staffId,
        'workbench_ready'=>!empty($bootstrapData['data']['bootstrap']['workbenchReady']),
        'state_context_id'=>$stateContextId,
        'workspace_id'=>(string)($bootstrapData['state']['workspace']['id'] ?? ''),
        'workspace_revision'=>(int)($bootstrapData['state']['workspace']['revision'] ?? 0),
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}
if ($phase === 'staff') {
    $rows = think\facade\Db::name('system_store_staff')->alias('ss')
        ->join('employee e', 'e.id=ss.employee_id')
        ->where('ss.store_id', $storeId)->where('ss.status', 1)->where('ss.is_del', 0)
        ->where('ss.cashier_craftsman_enabled', 1)->where('e.status', 1)->where('e.is_del', 0)
        ->field('ss.id,ss.employee_id,ss.staff_name,ss.craftsman_performance_type,e.name as employee_name,e.employment_type_code,e.employment_type_version')
        ->order('ss.id', 'asc')->select()->toArray();
    echo json_encode(['status'=>'staff','store_id'=>$storeId,'staff'=>$rows], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}
if ($phase === 'void') {
    // The "void" test is deliberately the second, explicitly labelled service fact.
    // Re-read its immutable source identity before entering the command path.
    $fact = (array)think\facade\Db::name('cashier_v3_entitlement_service_fact')
        ->where('id', 1585)->where('store_id', $storeId)->where('member_id', 1035667)
        ->where('project_id', 598789)->where('service_status', 'completed')->find();
    if (!$fact || (string)($fact['checkout_request_id'] ?? '') !== 'CKR-c3e0013d7c64a5e6b4b99e9fd82a0e34be6be5ae') {
        throw new RuntimeException('expected test service fact 1585 is no longer eligible to void');
    }
    $void = $post([
        'action'=>'void-service-record',
        'clientSessionId'=>$sessionId,
        'stateContextId'=>$stateContextId,
        'correlationId'=>'CORR-c1a3d2e4-1045-4a66-8b77-000000000009',
        'serviceFactId'=>1585,
        'reason'=>'长期卡 104566 回归测试：按测试方案作废本笔服务记录',
        'command'=>[
            'action'=>'void-service-record',
            'idempotencyKey'=>'SERVICE_ACTION-c1a3d2e4-1045-4a66-8b77-000000000009',
            'contexts'=>[],
        ],
    ]);
    $voidData = (array)($void['response']['data'] ?? []);
    echo json_encode([
        'status'=>'void',
        'store_id'=>$storeId,
        'staff_id'=>$staffId,
        'service_fact_id'=>1585,
        'result'=>$voidData['result'] ?? [],
        'service_record_void'=>(array)($voidData['data']['serviceRecordVoid'] ?? []),
        'http_status'=>$void['http_status'],
        'curl_error'=>$void['curl_error'],
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}
if (in_array($phase, ['create-keep', 'create-void'], true)) {
    $test = $phase === 'create-keep'
        ? ['suffix'=>'keep','uuid'=>'c1a3d2e4-1045-4a66-8b77-000000000007']
        : ['suffix'=>'void','uuid'=>'c1a3d2e4-1045-4a66-8b77-000000000008'];
    $now = time();
    $checkoutSnapshot = [
        'businessType'=>'sale',
        'memberId'=>1035667,
        'customerMode'=>'member',
        'occurredAt'=>$now,
        'businessDate'=>date('Y-m-d', $now),
        'businessDateReason'=>'time_card_import_regression_test',
        'orderNote'=>'长期卡 104566 真实核销回归测试（'.$test['suffix'].'）',
        'lines'=>[[
            'lineId'=>'timecard-104566-test-'.$test['suffix'],
            'lineRole'=>'entitlement_service',
            'entitlementInstanceId'=>1071196,
            'entitlementSourceDetailId'=>2419417,
            'projectId'=>598789,
            'quantity'=>1,
            'actualAmount'=>'0.00',
            'entitlementSourceKind'=>'time_card',
            'entitlementSourceName'=>'长期卡',
            'fullCardNo'=>'104566',
            'name'=>'长期项目',
            'craftsmen'=>[[
                'staffId'=>524,
                'laborWeight'=>100,
                'performanceAmountCents'=>0,
                'performanceAmountManual'=>false,
                'isPointCustomer'=>false,
                'craftsmanPerformanceType'=>'commission_labor',
                'laborFeeCents'=>0,
            ]],
            // 收银端请求契约的内部枚举；页面展示层再映射为“本人”。
            'serviceObject'=>'self',
            'friendCountsAsCustomer'=>true,
            'isExperience'=>false,
        ]],
        'source'=>[
            'primarySourceId'=>0,
            'primarySourceNameSnapshot'=>'',
            'secondarySourceId'=>0,
            'secondarySourceNameSnapshot'=>'',
            'displayNameSnapshot'=>'',
            'rewardAmountCents'=>0,
        ],
        'supplement'=>[],
    ];
    $submission = $post([
        'action'=>'submit-checkout',
        'clientSessionId'=>$sessionId,
        'stateContextId'=>$stateContextId,
        'correlationId'=>'CORR-'.$test['uuid'],
        'checkoutSnapshot'=>$checkoutSnapshot,
        'command'=>[
            'action'=>'submit-checkout',
            'idempotencyKey'=>'CHECKOUT-'.$test['uuid'],
            'contexts'=>[],
        ],
    ]);
    $submissionData = (array)($submission['response']['data'] ?? []);
    $body = (array)($submissionData['data'] ?? []);
    $checkout = (array)($body['checkoutSubmission'] ?? []);
    echo json_encode([
        'status'=>$phase,
        'store_id'=>$storeId,
        'staff_id'=>$staffId,
        'result'=>$submissionData['result'] ?? [],
        'checkout_submission'=>$checkout,
        'http_status'=>$submission['http_status'],
        'curl_error'=>$submission['curl_error'],
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}
$selector = $post([
    'action'=>'open-add-card-service-project',
    'clientSessionId'=>$sessionId,
    'stateContextId'=>$stateContextId,
    'correlationId'=>'CORR-104566000-0000-4000-8000-000000000002',
    'memberId'=>1035667,
    'selectorRequestId'=>'ENTITLEMENT_SELECTOR-c1a3d2e4-1045-4a66-8b77-000000000002',
]);
$selectorData = (array)($selector['response']['data'] ?? []);
$selectorPayload = (array)($selectorData['data']['entitlementSelector'] ?? []);
$matched = [];
foreach ((array)($selectorPayload['sources'] ?? []) as $source) {
    if (!is_array($source)) continue;
    $cardNo = (string)($source['fullCardNo'] ?? $source['cardNo'] ?? $source['card_no'] ?? '');
    if ($cardNo !== '104566') continue;
    $matched[] = [
        'source'=>[
            'keys'=>array_keys($source),
            'id'=>$source['id'] ?? null,
            'cardHolderId'=>$source['cardHolderId'] ?? null,
            'entitlementInstanceId'=>$source['entitlementInstanceId'] ?? null,
            'name'=>$source['name'] ?? null,
            'fullCardNo'=>$cardNo,
            'sourceKind'=>$source['sourceKind'] ?? null,
            'status'=>$source['status'] ?? null,
            'selectable'=>$source['selectable'] ?? null,
        ],
        'projects'=>array_values((array)($source['projects'] ?? [])),
    ];
}
echo json_encode([
    'status'=>'selector',
    'store_id'=>$storeId,
    'staff_id'=>$staffId,
    'result'=>$selectorData['result'] ?? [],
    'member'=>$selectorPayload['member'] ?? [],
    'matched_sources'=>$matched,
], JSON_UNESCAPED_UNICODE).PHP_EOL;
