<?php
/**
 * C5 会员真实 MySQL 5.6 集成门禁。
 *
 * 业务权威源：user + store_user；会员建档成功与 member.created 统一事件同事务。
 * 测试只替换旧 UserServices 写适配，V3 模块、Gateway、锁、编号及事件均为生产实现。
 */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';
require __DIR__ . '/../lib/TestGraphFactory.php';
require __DIR__ . '/../lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use think\facade\Db;

$app = c1aBootThinkApp('/var/www/html/');
MemberIntegrationFixture::bindTestAdapters();

function c5Section(string $title): void
{
    echo "\n== {$title} ==\n";
}

function c5CommandCode(callable $callable, array &$detail = []): string
{
    try {
        $callable();
    } catch (CashierV3CommandException $exception) {
        $detail = $exception->getDetail();
        return $exception->getResultCode();
    } catch (\Throwable $throwable) {
        $detail = [
            'class' => get_class($throwable),
            'message' => $throwable->getMessage(),
        ];
        return 'UNEXPECTED_THROWABLE';
    }
    return '';
}

function c5Projection($dispatcher, string $action, array $payload, array $session): array
{
    $body = array_merge($payload, [
        'action' => $action,
        'correlationId' => 'CORR-' . MemberIntegrationFixture::uuid(),
        'clientSessionId' => $session['client_session_id'],
    ]);
    $envelope = $dispatcher->dispatch($body, $session);
    return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
}

function c5WorkerResult(string $output): array
{
    if (preg_match('/^C5_WORKER_RESULT=(\{.*\})$/m', $output, $matches)) {
        $decoded = json_decode($matches[1], true);
        return is_array($decoded) ? $decoded : ['status' => 'invalid_json', 'raw' => $output];
    }
    return ['status' => 'missing_result', 'raw' => $output];
}

function c5StartWorker(array $payload, int $operatorId, string $idempotencyKey, array $extraEnv = []): array
{
    $encoded = base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $command = implode(' ', [
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        '--worker',
        escapeshellarg($encoded),
        (string)$operatorId,
        escapeshellarg($idempotencyKey),
    ]);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = array_merge((array)getenv(), $extraEnv);
    $process = proc_open($command, $descriptors, $pipes, null, $environment);
    if (!is_resource($process)) {
        return ['process' => null, 'pipes' => [], 'command' => $command];
    }
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes, 'command' => $command];
}

function c5CollectWorker(array $worker): array
{
    if (!is_resource($worker['process'] ?? null)) {
        return ['exit' => -1, 'output' => '', 'error' => 'proc_open_failed'];
    }
    $output = stream_get_contents($worker['pipes'][1]);
    $error = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    return ['exit' => $exit, 'output' => (string)$output, 'error' => (string)$error];
}

// 并发 worker：主进程已建立并播种测试表，worker 只执行单条真实领域命令。
if (($argv[1] ?? '') === '--worker') {
    try {
        $payload = json_decode((string)base64_decode((string)($argv[2] ?? ''), true), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('WORKER_PAYLOAD_INVALID');
        }
        $operatorId = max(1, (int)($argv[3] ?? 1));
        $idempotencyKey = (string)($argv[4] ?? '');
        $workerDispatcher = MemberIntegrationFixture::dispatcher();
        $request = MemberIntegrationFixture::commandRequest(
            $workerDispatcher,
            'create-member',
            $payload,
            $operatorId,
            $idempotencyKey
        );
        $result = $workerDispatcher->dispatch($request['body'], $request['session']);
        echo 'C5_WORKER_RESULT=' . json_encode([
            'status' => 'success',
            'member' => $result['data']['member'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (CashierV3CommandException $exception) {
        echo 'C5_WORKER_RESULT=' . json_encode([
            'status' => 'command_error',
            'code' => $exception->getResultCode(),
            'detail' => $exception->getDetail(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (\Throwable $throwable) {
        echo 'C5_WORKER_RESULT=' . json_encode([
            'status' => 'unexpected_error',
            'class' => get_class($throwable),
            'message' => $throwable->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
}

try {
    c5Section('C5 migration readiness');
    MemberIntegrationFixture::ensureLegacySchema();
    $missingTables = [];
    foreach (array_merge(
        MemberIntegrationFixture::REQUIRED_C5_TABLES,
        MemberIntegrationFixture::REQUIRED_EVENT_TABLES
    ) as $table) {
        if (!MemberIntegrationFixture::tableExists($table)) {
            $missingTables[] = $table;
        }
    }
    ok(
        'C5 四张会员表与统一事件四表均已就绪',
        $missingTables === [],
        implode(',', $missingTables),
        'C5-MEM-00'
    );
    $numberSequenceReady = $missingTables === []
        && (bool)Db::name('cashier_v3_member_number_sequence')
            ->where('sequence_key', 'member_bar_code')
            ->whereBetween('current_value', [99999999, 999999999])
            ->find();
    ok(
        '会员编号序列使用生产代码约定键并已初始化',
        $numberSequenceReady,
        json_encode(Db::name('cashier_v3_member_number_sequence')->select()->toArray()),
        'C5-MEM-00'
    );
    if ($missingTables !== [] || !$numberSequenceReady) {
        finish('C5-member-integration');
    }

    MemberIntegrationFixture::resetAndSeed();

    c5Section('selector fixtures');
    MemberIntegrationFixture::seedMember(101, '排序-本店', '13100000101', 8);
    MemberIntegrationFixture::seedMember(102, '排序-同组织', '13100000102', 9);
    MemberIntegrationFixture::seedMember(103, '排序-上级组织', '13100000103', 10);
    MemberIntegrationFixture::seedMember(104, '排序-集团', '13100000104', 11);
    MemberIntegrationFixture::seedMember(105, '排序-其他事业部', '13100000105', 12);
    MemberIntegrationFixture::seedMember(106, '状态-已停用', '13100000106', 8, 0, 0, null);
    MemberIntegrationFixture::seedMember(107, '状态-已注销', '13100000107', 9, 0, 1, '2026-07-28 00:00:00');
    MemberIntegrationFixture::seedMember(108, '展示门店-多关系', '13100000108', 11);
    Db::name('store_user')->insert([
        'store_id' => 8,
        'uid' => 108,
        'label_id' => '',
        'status' => 1,
        'add_time' => time(),
    ]);
    MemberIntegrationFixture::seedMember(201, '范围-本店会员', '13100000201', 8);
    MemberIntegrationFixture::seedMember(202, '范围-越权会员', '13100000202', 9);
    MemberIntegrationFixture::seedMember(203, '范围-失效门店关系', '13100000203', 8);
    Db::name('store_user')->where(['uid' => 203, 'store_id' => 8])->update(['status' => 0]);

    $dispatcher = MemberIntegrationFixture::dispatcher();
    $projectionSession = MemberIntegrationFixture::projectionSession(1);

    c5Section('full-group selector pagination and organization priority');
    $ordered = [];
    $totals = [];
    for ($page = 1; $page <= 3; $page++) {
        $data = c5Projection($dispatcher, 'query-member-selector', [
            'selectorEntry' => 'cashier',
            'keyword' => '排序',
            'page' => $page,
            'pageSize' => 2,
        ], $projectionSession);
        $totals[] = (int)($data['total'] ?? -1);
        foreach ((array)($data['records'] ?? []) as $record) {
            $ordered[] = (int)($record['memberId'] ?? 0);
        }
    }
    ok(
        '选择器全集团分页且按本店、当前组织、逐级上级、其他组织排序',
        $totals === [5, 5, 5] && $ordered === [101, 102, 103, 105, 104],
        json_encode(['totals' => $totals, 'ordered' => $ordered], JSON_UNESCAPED_UNICODE),
        'C5-MEM-01'
    );

    c5Section('disabled and cancelled members remain visible but not selectable');
    $statusData = c5Projection($dispatcher, 'query-member-selector', [
        'selectorEntry' => 'cashier',
        'keyword' => '状态',
        'page' => 1,
        'pageSize' => 20,
    ], $projectionSession);
    $statusById = [];
    foreach ((array)($statusData['records'] ?? []) as $record) {
        $statusById[(int)($record['memberId'] ?? 0)] = $record;
    }
    ok(
        '停用会员显示为已停用且不可选',
        ($statusById[106]['status'] ?? '') === '已停用'
            && ($statusById[106]['selectable'] ?? true) === false,
        json_encode($statusById[106] ?? null, JSON_UNESCAPED_UNICODE),
        'C5-MEM-02'
    );
    ok(
        '注销会员显示为已注销且不可选',
        ($statusById[107]['status'] ?? '') === '已注销'
            && ($statusById[107]['selectable'] ?? true) === false,
        json_encode($statusById[107] ?? null, JSON_UNESCAPED_UNICODE),
        'C5-MEM-02'
    );
    foreach ([
        106 => 'member_disabled',
        107 => 'member_cancelled',
    ] as $blockedMemberId => $expectedReason) {
        $blockedDetail = [];
        $blockedCode = c5CommandCode(function () use ($dispatcher, $blockedMemberId) {
            MemberIntegrationFixture::directCommand($dispatcher, 'select-cashier-member', [
                'selectorEntry' => 'cashier',
                'memberId' => $blockedMemberId,
            ]);
        }, $blockedDetail);
        ok(
            '后端拒绝选择非正常会员 ' . $blockedMemberId,
            $blockedCode === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE
                && (string)($blockedDetail['reason'] ?? '') === $expectedReason,
            json_encode(['code' => $blockedCode, 'detail' => $blockedDetail], JSON_UNESCAPED_UNICODE),
            'C5-MEM-02'
        );
    }

    c5Section('member center obeys DataScope while selector does not narrow to it');
    $memberCenter = c5Projection($dispatcher, 'query-members', [
        'keyword' => '范围',
        'page' => 1,
        'pageSize' => 20,
    ], $projectionSession);
    $centerIds = array_map('intval', array_column((array)($memberCenter['records'] ?? []), 'memberId'));
    ok(
        '会员中心查询由后端 DataScope 裁剪到本店',
        (int)($memberCenter['total'] ?? -1) === 1 && $centerIds === [201],
        json_encode(['total' => $memberCenter['total'] ?? null, 'ids' => $centerIds]),
        'C5-MEM-03'
    );
    $scopeSelector = c5Projection($dispatcher, 'query-member-selector', [
        'selectorEntry' => 'cashier',
        'keyword' => '范围',
        'page' => 1,
        'pageSize' => 20,
    ], $projectionSession);
    $selectorIds = array_map('intval', array_column((array)($scopeSelector['records'] ?? []), 'memberId'));
    sort($selectorIds);
    ok(
        '前台选择器不被员工档案门店 DataScope 裁掉集团会员',
        (int)($scopeSelector['total'] ?? -1) === 3 && $selectorIds === [201, 202, 203],
        json_encode(['total' => $scopeSelector['total'] ?? null, 'ids' => $selectorIds]),
        'C5-MEM-03'
    );

    c5Section('display store is stable across selector and selected member');
    $multiStoreData = c5Projection($dispatcher, 'query-member-selector', [
        'selectorEntry' => 'cashier',
        'keyword' => '展示门店',
        'page' => 1,
        'pageSize' => 20,
    ], $projectionSession);
    $multiStoreRecord = (array)(($multiStoreData['records'] ?? [])[0] ?? []);
    $selectedMultiStore = MemberIntegrationFixture::directCommand($dispatcher, 'select-cashier-member', [
        'selectorEntry' => 'cashier',
        'memberId' => 108,
    ]);
    ok(
        '多门店会员按账号组织优先级展示本店且选中后不跳回旧归属门店',
        (int)($multiStoreRecord['storeId'] ?? 0) === 8
            && (int)($selectedMultiStore['data']['member']['storeId'] ?? 0) === 8,
        json_encode([
            'selector' => $multiStoreRecord,
            'selected' => $selectedMultiStore['data']['member'] ?? null,
        ], JSON_UNESCAPED_UNICODE),
        'C5-MEM-03'
    );

    c5Section('guest is cashier-only');
    $guest = MemberIntegrationFixture::directCommand($dispatcher, 'set-guest-order', [
        'selectorEntry' => 'cashier',
        'selectorContext' => 'cashier',
    ]);
    ok(
        '游客允许从结账收款入口选择',
        ($guest['data']['customerMode'] ?? '') === 'guest'
            && array_key_exists('member', (array)($guest['data'] ?? []))
            && $guest['data']['member'] === null,
        json_encode($guest, JSON_UNESCAPED_UNICODE),
        'C5-MEM-09'
    );
    foreach (['writeoff', 'reservation'] as $invalidEntry) {
        $detail = [];
        $code = c5CommandCode(function () use ($dispatcher, $invalidEntry) {
            MemberIntegrationFixture::directCommand($dispatcher, 'set-guest-order', [
                'selectorEntry' => $invalidEntry,
                'selectorContext' => $invalidEntry,
            ]);
        }, $detail);
        ok(
            '游客拒绝 ' . $invalidEntry . ' 入口',
            $code === CashierV3ResultCode::PERMISSION_DENIED
                && (string)($detail['reason'] ?? '') === 'selector_entry_mismatch',
            json_encode(['code' => $code, 'detail' => $detail], JSON_UNESCAPED_UNICODE),
            'C5-MEM-09'
        );
    }

    c5Section('create member transaction, unified event and idempotent replay');
    $labelColumns = array_map(function (array $row): string {
        return (string)($row['Field'] ?? $row['field'] ?? '');
    }, Db::query('SHOW COLUMNS FROM `eb_user_label_relation`'));
    $labelAdapterClass = get_class(app()->make(\app\services\user\label\UserLabelRelationServices::class));
    ok(
        '标签关系测试表与旧服务适配器就绪',
        in_array('label_id', $labelColumns, true)
            && $labelAdapterClass === \C1A\CashierV3\Test\MemberTestUserLabelRelationServices::class,
        json_encode(['columns' => $labelColumns, 'adapter' => $labelAdapterClass]),
        'C5-MEM-07'
    );
    $profileIdempotency = 'CMD-' . MemberIntegrationFixture::uuid();
    $profilePayload = [
        'name' => '完整建档会员',
        'phone' => '13800001001',
        'memberLevelId' => 1,
        'memberTagIds' => [11, 12],
        'exclusiveServicePersonId' => 20,
        'note' => 'C5 integration',
    ];
    $profileRequest = MemberIntegrationFixture::commandRequest(
        $dispatcher,
        'create-member',
        $profilePayload,
        1,
        $profileIdempotency
    );
    $created = $dispatcher->dispatch($profileRequest['body'], $profileRequest['session']);
    $replayed = $dispatcher->dispatch($profileRequest['body'], $profileRequest['session']);
    $member = (array)($created['data']['member'] ?? []);
    $memberId = (int)($member['memberId'] ?? 0);
    $memberNo = (string)($member['memberNo'] ?? '');
    $createdCount = (int)Db::name('user')->where('phone', $profilePayload['phone'])->count();
    $eventRows = Db::name('cashier_v3_business_event')
        ->where('event_type', 'member.created')
        ->where('aggregate_type', 'member')
        ->where('aggregate_id', (string)$memberId)
        ->select()->toArray();
    $memberOutboxCount = $eventRows
        ? (int)Db::name('cashier_v3_outbox')->where('event_id', (int)$eventRows[0]['id'])->count()
        : -1;
    ok(
        '同幂等键只建一名会员并重放第一次结果',
        ($created['result']['status'] ?? '') === CashierV3ResultCode::STATUS_SUCCESS
            && empty($created['replay'])
            && !empty($replayed['replay'])
            && (int)($replayed['data']['member']['memberId'] ?? 0) === $memberId
            && $createdCount === 1
            && count($eventRows) === 1
            && $memberOutboxCount === 0,
        json_encode([
            'created' => $created,
            'replayed' => $replayed,
            'memberCount' => $createdCount,
            'eventCount' => count($eventRows),
            'outboxCount' => $memberOutboxCount,
        ], JSON_UNESCAPED_UNICODE),
        'C5-MEM-05'
    );

    $levelRow = Db::name('user_level')->where(['uid' => $memberId, 'status' => 1, 'is_del' => 0])->find();
    $tagIds = array_map('intval', Db::name('user_label_relation')->where('uid', $memberId)->order('label_id', 'asc')->column('label_id'));
    $exclusive = Db::name('member_exclusive_service')->where('member_id', $memberId)->find();
    $exclusiveChange = Db::name('member_exclusive_service_change')->where('member_id', $memberId)->find();
    $storeRelation = Db::name('store_user')->where(['uid' => $memberId, 'store_id' => 8])->find();
    $eventPayload = json_decode((string)($eventRows[0]['payload'] ?? ''), true);
    ok(
        '等级、标签与专属服务人随会员同事务落库',
        (int)($levelRow['level_id'] ?? 0) === 1
            && $tagIds === [11, 12]
            && (int)($storeRelation['status'] ?? 0) === 1
            && (string)($storeRelation['label_id'] ?? '') === ''
            && (int)($exclusive['staff_id'] ?? 0) === 20
            && (int)($exclusive['employee_id'] ?? 0) === 20
            && (int)($exclusive['store_id'] ?? 0) === 8
            && (string)($exclusive['staff_name'] ?? '') === '专属服务人甲'
            && (int)($exclusive['status'] ?? 0) === 1
            && (int)($exclusive['version'] ?? 0) === 1
            && (string)($exclusive['source_type'] ?? '') === 'member_create'
            && (string)($exclusive['source_business_type'] ?? '') === 'member'
            && (string)($exclusive['source_business_id'] ?? '') === (string)$memberId
            && trim((string)($exclusive['reason'] ?? '')) !== ''
            && (int)($exclusive['bound_at'] ?? 0) > 0
            && (int)($exclusive['operator_id'] ?? 0) === 1
            && (string)($exclusive['idempotency_key'] ?? '') === $profileIdempotency
            && (int)($exclusiveChange['previous_staff_id'] ?? -1) === 0
            && (int)($exclusiveChange['previous_employee_id'] ?? -1) === 0
            && (int)($exclusiveChange['current_staff_id'] ?? 0) === 20
            && (int)($exclusiveChange['current_employee_id'] ?? 0) === 20
            && (int)($exclusiveChange['current_store_id'] ?? 0) === 8
            && (string)($exclusiveChange['current_staff_name'] ?? '') === '专属服务人甲'
            && (string)($exclusiveChange['change_key'] ?? '') === 'exclusive_service:' . $memberId . ':' . $profileIdempotency
            && (int)($exclusiveChange['operator_id'] ?? 0) === 1
            && trim((string)($exclusiveChange['reason'] ?? '')) !== ''
            && (string)($exclusiveChange['source_type'] ?? '') === 'member_create'
            && (string)($exclusiveChange['source_business_type'] ?? '') === 'member'
            && (string)($exclusiveChange['source_business_id'] ?? '') === (string)$memberId
            && (string)($exclusiveChange['idempotency_key'] ?? '') === $profileIdempotency
            && (int)($exclusiveChange['occurred_at'] ?? 0) > 0
            && (int)($exclusiveChange['recorded_at'] ?? 0) > 0,
        json_encode([
            'level' => $levelRow,
            'tags' => $tagIds,
            'exclusive' => $exclusive,
            'change' => $exclusiveChange,
        ], JSON_UNESCAPED_UNICODE),
        'C5-MEM-07'
    );
    ok(
        'member.created 统一事件保存追溯快照且四类时间完整',
        is_array($eventPayload)
            && (string)($eventRows[0]['event_key'] ?? '') === 'member.created:member:' . $memberId . ':1'
            && (string)($eventRows[0]['command_idempotency_key'] ?? '') === $profileIdempotency
            && (int)($eventRows[0]['aggregate_version'] ?? 0) === 1
            && (string)($eventRows[0]['organization_path'] ?? '') === '1/2/3'
            && (string)($eventRows[0]['organization_name_snapshot'] ?? '') === '当前组织'
            && (string)($eventRows[0]['operator_name_snapshot'] ?? '') === '操作员一'
            && (int)($eventRows[0]['occurred_at'] ?? 0) > 0
            && (int)($eventRows[0]['settled_at'] ?? 0) > 0
            && (int)($eventRows[0]['recorded_at'] ?? 0) > 0
            && trim((string)($eventRows[0]['business_date'] ?? '')) !== ''
            && (int)($eventPayload['member_id'] ?? 0) === $memberId
            && (string)($eventPayload['member_no'] ?? '') === $memberNo
            && (int)($eventPayload['level_id'] ?? 0) === 1
            && array_map('intval', (array)($eventPayload['tag_ids'] ?? [])) === [11, 12]
            && (int)($eventPayload['exclusive_service_person_id'] ?? 0) === 20
            && (int)($eventPayload['exclusive_service_person_employee_id'] ?? 0) === 20,
        json_encode(['event' => $eventRows[0] ?? null, 'payload' => $eventPayload], JSON_UNESCAPED_UNICODE),
        'C5-MEM-07'
    );

    c5Section('required event integrity on idempotent replay');
    $missingEventKey = 'CMD-' . MemberIntegrationFixture::uuid();
    $missingEventRequest = MemberIntegrationFixture::commandRequest($dispatcher, 'create-member', [
        'name' => '事件对账会员',
        'phone' => '13800001008',
    ], 1, $missingEventKey);
    $missingEventCreated = $dispatcher->dispatch($missingEventRequest['body'], $missingEventRequest['session']);
    $missingEventMemberId = (int)($missingEventCreated['data']['member']['memberId'] ?? 0);
    Db::name('cashier_v3_business_event')
        ->where('command_idempotency_key', $missingEventKey)
        ->delete();
    $missingReplayCode = '';
    $missingReplayStatus = '';
    $missingReplayDetail = [];
    try {
        $dispatcher->dispatch($missingEventRequest['body'], $missingEventRequest['session']);
    } catch (CashierV3CommandException $exception) {
        $missingReplayCode = $exception->getResultCode();
        $missingReplayStatus = $exception->getResultStatus();
        $missingReplayDetail = $exception->getDetail();
    }
    ok(
        '成功回执存在但 required event 缺失时重放不伪造成功也不现场补事件',
        $missingEventMemberId > 0
            && $missingReplayCode === CashierV3ResultCode::COMMAND_RESULT_UNKNOWN
            && $missingReplayStatus === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
            && (string)($missingReplayDetail['reason'] ?? '') === 'required_event_replay_inconsistent'
            && (int)Db::name('cashier_v3_business_event')
                ->where('command_idempotency_key', $missingEventKey)
                ->count() === 0
            && (int)Db::name('cashier_v3_command_receipt')
                ->where('idempotency_key', $missingEventKey)
                ->where('status', 1)
                ->count() === 1,
        json_encode([
            'code' => $missingReplayCode,
            'status' => $missingReplayStatus,
            'detail' => $missingReplayDetail,
        ], JSON_UNESCAPED_UNICODE),
        'EO-15-08'
    );

    c5Section('nine-digit member number uniqueness');
    $secondRequest = MemberIntegrationFixture::commandRequest($dispatcher, 'create-member', [
        'name' => '编号会员二',
        'phone' => '13800001002',
    ], 1);
    $secondCreated = $dispatcher->dispatch($secondRequest['body'], $secondRequest['session']);
    $secondNumber = (string)($secondCreated['data']['member']['memberNo'] ?? '');
    ok(
        '新会员立即获得唯一九位纯数字编号',
        preg_match('/^\d{9}$/', $memberNo) === 1
            && preg_match('/^\d{9}$/', $secondNumber) === 1
            && $memberNo !== $secondNumber
            && (int)Db::name('user')->whereIn('bar_code', [$memberNo, $secondNumber])->count() === 2,
        json_encode(['first' => $memberNo, 'second' => $secondNumber]),
        'C5-MEM-06'
    );

    c5Section('invalid birthday is rejected before any member write');
    $birthdayPhone = '13800001009';
    $birthdaySequenceBefore = Db::name('cashier_v3_member_number_sequence')
        ->where('sequence_key', 'member_bar_code')
        ->value('current_value');
    $birthdayDetail = [];
    $birthdayCode = c5CommandCode(function () use ($dispatcher, $birthdayPhone) {
        MemberIntegrationFixture::directCommand($dispatcher, 'create-member', [
            'name' => '非法生日会员',
            'phone' => $birthdayPhone,
            'birthday' => '2026-02-30',
        ], 1, 'CMD-' . MemberIntegrationFixture::uuid());
    }, $birthdayDetail);
    $birthdaySequenceAfter = Db::name('cashier_v3_member_number_sequence')
        ->where('sequence_key', 'member_bar_code')
        ->value('current_value');
    ok(
        '非法生日不创建会员且不推进会员编号序列',
        $birthdayCode === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE
            && isset($birthdayDetail['fieldErrors']['birthday'])
            && (int)Db::name('user')->where('phone', $birthdayPhone)->count() === 0
            && (string)$birthdaySequenceAfter === (string)$birthdaySequenceBefore,
        json_encode([
            'code' => $birthdayCode,
            'detail' => $birthdayDetail,
            'before' => $birthdaySequenceBefore,
            'after' => $birthdaySequenceAfter,
        ], JSON_UNESCAPED_UNICODE),
        'C5-MEM-06'
    );

    c5Section('unified event failure rolls back every member write');
    $rollbackPhone = '13800001003';
    // Workspace/context preparation is a prior, independently committed step.
    // Freeze it before the command rollback snapshot so the assertion measures
    // only the Gateway business transaction.
    $rollbackRequest = MemberIntegrationFixture::commandRequest($dispatcher, 'create-member', [
        'name' => '应整体回滚会员',
        'phone' => $rollbackPhone,
        'memberLevelId' => 1,
        'memberTagIds' => [11, 12],
        'exclusiveServicePersonId' => 20,
    ], 1, 'CMD-' . MemberIntegrationFixture::uuid());
    $rollbackTables = [
        'user', 'store_user', 'user_level', 'user_label_relation',
        'member_exclusive_service', 'member_exclusive_service_change',
        'cashier_v3_member_phone_lock',
        'cashier_v3_business_event', 'cashier_v3_outbox',
        'cashier_v3_command_receipt', 'cashier_v3_resource_version',
    ];
    $rollbackOrderFields = [
        'user' => 'uid',
        'cashier_v3_member_phone_lock' => 'phone',
    ];
    $countsBefore = [];
    $rowsBefore = [];
    foreach ($rollbackTables as $table) {
        $countsBefore[$table] = (int)Db::name($table)->count();
        $rowsBefore[$table] = Db::name($table)
            ->order((string)($rollbackOrderFields[$table] ?? 'id'), 'asc')
            ->select()->toArray();
    }
    $sequenceBefore = Db::name('cashier_v3_member_number_sequence')->order('sequence_key', 'asc')->select()->toArray();
    Db::execute("CREATE TRIGGER `eb_c5_member_event_fail` BEFORE INSERT ON `eb_cashier_v3_business_event`
      FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='C5_TEST_EVENT_INSERT_FAILED'");
    $rollbackDetail = [];
    try {
        $rollbackCode = c5CommandCode(function () use ($dispatcher, $rollbackRequest) {
            $dispatcher->dispatch($rollbackRequest['body'], $rollbackRequest['session']);
        }, $rollbackDetail);
    } finally {
        Db::execute('DROP TRIGGER IF EXISTS `eb_c5_member_event_fail`');
    }
    $countsAfter = [];
    $rowsAfter = [];
    foreach ($rollbackTables as $table) {
        $countsAfter[$table] = (int)Db::name($table)->count();
        $rowsAfter[$table] = Db::name($table)
            ->order((string)($rollbackOrderFields[$table] ?? 'id'), 'asc')
            ->select()->toArray();
    }
    $sequenceAfter = Db::name('cashier_v3_member_number_sequence')->order('sequence_key', 'asc')->select()->toArray();
    ok(
        '统一事件不可写时会员、关系、编号、档案选择项全部回滚',
        $rollbackCode === CashierV3ResultCode::COMMAND_EVENT_PERSISTENCE_INCOMPLETE
            && $countsAfter === $countsBefore
            && $rowsAfter === $rowsBefore
            && $sequenceAfter === $sequenceBefore
            && (int)Db::name('user')->where('phone', $rollbackPhone)->count() === 0,
        json_encode([
            'code' => $rollbackCode,
            'detail' => $rollbackDetail,
            'before' => $countsBefore,
            'after' => $countsAfter,
            'rowSetsEqual' => $rowsAfter === $rowsBefore,
            'sequenceBefore' => $sequenceBefore,
            'sequenceAfter' => $sequenceAfter,
        ], JSON_UNESCAPED_UNICODE),
        'C5-MEM-08'
    );

    c5Section('same phone concurrent create is serialized by database lock');
    $concurrentPhone = '13800001004';
    $signal = '/tmp/c1a/c5-member-lock-' . MemberIntegrationFixture::uuid() . '.signal';
    $workerA = c5StartWorker([
        'name' => '并发会员甲',
        'phone' => $concurrentPhone,
    ], 1, 'CMD-' . MemberIntegrationFixture::uuid(), [
        'C5_MEMBER_HOLD_US' => '1200000',
        'C5_MEMBER_LOCK_SIGNAL' => $signal,
    ]);
    $signalSeen = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        clearstatcache(true, $signal);
        if (is_file($signal)) {
            $signalSeen = true;
            break;
        }
        usleep(50000);
    }
    $workerB = c5StartWorker([
        'name' => '并发会员乙',
        'phone' => $concurrentPhone,
    ], 2, 'CMD-' . MemberIntegrationFixture::uuid());
    $workerAResult = c5CollectWorker($workerA);
    $workerBResult = c5CollectWorker($workerB);
    if (is_file($signal)) {
        unlink($signal);
    }
    $decodedA = c5WorkerResult($workerAResult['output']);
    $decodedB = c5WorkerResult($workerBResult['output']);
    $statuses = [$decodedA['status'] ?? '', $decodedB['status'] ?? ''];
    sort($statuses);
    $errors = [];
    foreach ([$decodedA, $decodedB] as $decoded) {
        if (($decoded['status'] ?? '') === 'command_error') {
            $errors[] = $decoded;
        }
    }
    $concurrentUser = Db::name('user')->where('phone', $concurrentPhone)->find();
    $concurrentUid = (int)($concurrentUser['uid'] ?? 0);
    ok(
        '同手机号并发只有一次成功且另一请求识别已有会员',
        $signalSeen
            && $workerAResult['exit'] === 0
            && $workerBResult['exit'] === 0
            && $statuses === ['command_error', 'success']
            && count($errors) === 1
            && ($errors[0]['code'] ?? '') === CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT
            && (string)($errors[0]['detail']['reason'] ?? '') === 'phone_exists'
            && (int)Db::name('user')->where('phone', $concurrentPhone)->count() === 1
            && (int)Db::name('cashier_v3_business_event')
                ->where('event_type', 'member.created')
                ->where('aggregate_id', (string)$concurrentUid)
                ->count() === 1,
        json_encode([
            'signalSeen' => $signalSeen,
            'workerA' => $workerAResult,
            'workerB' => $workerBResult,
            'decodedA' => $decodedA,
            'decodedB' => $decodedB,
            'user' => $concurrentUser,
        ], JSON_UNESCAPED_UNICODE),
        'C5-MEM-04'
    );
} catch (\Throwable $throwable) {
    ok(
        'C5 会员集成套件无未处理异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage()
            . ' @ ' . $throwable->getFile() . ':' . $throwable->getLine()
            . "\n" . $throwable->getTraceAsString()
    );
}

finish('C5-member-integration');
