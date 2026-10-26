<?php
/**
 * Empty-room cashier intent and eventless hang preparation contract.
 * ThinkPHP and a database are intentionally not bootstrapped here.
 */

$backendRoot = __DIR__ . '/../../../后端代码';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResourceScope.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3OperatorScope.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3DataScopeContext.php';
require_once $backendRoot . '/app/services/cashier/v3/hang/CashierV3HangPreparationProvider.php';
require_once $backendRoot . '/app/services/cashier/v3/hang/CashierV3HangPreparationServices.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\hang\CashierV3HangPreparationProvider;
use app\services\cashier\v3\hang\CashierV3HangPreparationServices;

$passed = 0;
$failed = 0;

function roomHangAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function roomHangFailure(callable $callable): array
{
    try {
        $callable();
    } catch (CashierV3CommandException $exception) {
        return [
            'code' => $exception->getResultCode(),
            'status' => $exception->getResultStatus(),
            'detail' => $exception->getDetail(),
        ];
    } catch (\Throwable $exception) {
        return ['code' => get_class($exception), 'status' => 'unexpected', 'detail' => ['message' => $exception->getMessage()]];
    }
    return ['code' => '', 'status' => '', 'detail' => []];
}

final class RoomHangFakeProvider implements CashierV3HangPreparationProvider
{
    public $workspaceVersion = 9;
    public $candidates = [];
    public $observedStores = [];

    public function workspaceVersion(
        string $workspaceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): int {
        $this->observedStores[] = [$operatorScope->storeId(), $dataScope->forcedStoreId(), $workspaceId];
        return $this->workspaceVersion;
    }

    public function roomCandidates(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->observedStores[] = [$operatorScope->storeId(), $dataScope->forcedStoreId(), 'rooms'];
        return $this->candidates;
    }
}

function roomHangCandidate(bool $selectable = true, int $roomVersion = 17, int $slotVersion = 31): array
{
    return [
        'id' => 11,
        'roomId' => 11,
        'name' => '普通房 01',
        'categoryName' => '普通房间',
        'selectable' => $selectable,
        'canSelect' => $selectable,
        'disabledReason' => $selectable ? '' : '该房间当前已被占用',
        'revision' => $roomVersion,
        'roomVersion' => $roomVersion,
        'roomTimeSlotId' => 'open-service:11',
        'roomTimeSlotVersion' => $slotVersion,
        'commandContexts' => [
            ['kind' => 'room', 'id' => '11', 'expectedVersion' => $roomVersion],
            ['kind' => 'room_time_slot', 'id' => 'open-service:11', 'expectedVersion' => $slotVersion],
        ],
    ];
}

function roomHangDraft(string $stateContextId): array
{
    return [
        'workspaceId' => 'ws:7:21:' . $stateContextId,
        'stateContextId' => $stateContextId,
        'complete' => true,
        'lines' => [['id' => 'line-1', 'quantity' => 1, 'lineRole' => 'sale']],
        'lineFingerprint' => hash('sha256', 'line-1:1'),
    ];
}

$legacyRoomProviderSource = file_get_contents(
    $backendRoot . '/app/services/cashier/v3/hang/CashierV3LegacyRoomReadProvider.php'
);
roomHangAssert('room candidates merge active V3 guard occupations',
    is_string($legacyRoomProviderSource)
    && strpos($legacyRoomProviderSource, 'activeOccupancies($operatorScope, $dataScope)') !== false
    && strpos($legacyRoomProviderSource, '$v3Occupied = $occupation !== null') !== false
    && strpos($legacyRoomProviderSource, '$selectable = !$v3Occupied') !== false
);
roomHangAssert('room and time-slot contexts use the same guard authority version',
    is_string($legacyRoomProviderSource)
    && strpos($legacyRoomProviderSource, 'RoomOpenServiceGuardVersionProvider::KIND_ROOM') !== false
    && strpos($legacyRoomProviderSource, 'RoomOpenServiceGuardVersionProvider::KIND_SLOT') !== false
    && strpos($legacyRoomProviderSource, '$this->roomGuardVersions->discoverVersion(') !== false
    && strpos($legacyRoomProviderSource, "'occupancies' => \$activeOccupancies") === false
);

$operator = new CashierV3OperatorScope(7, 21, '3', '0');
$dataScope = new CashierV3DataScopeContext(
    21,
    121,
    7,
    '0',
    '3',
    [7],
    CashierV3DataScopeContext::MODE_STORES,
    [],
    false,
    '',
    'permission-v1',
    ['cashier.v3.room', 'cashier.v3.hang'],
    ['name' => '测试收银员']
);
$provider = new RoomHangFakeProvider();
$provider->candidates = [roomHangCandidate()];
$services = new CashierV3HangPreparationServices($provider);
$stateContextId = 'ctx-room-hang-0001';

echo "== empty room cashier intent ==\n";
$candidateBefore = json_encode($provider->candidates, JSON_UNESCAPED_UNICODE);
$intentPack = $services->prepareEmptyRoomCashier([
    'roomOpenIntentId' => 'ROOM_INTENT-00000001',
    'roomId' => 11,
    'roomVersion' => 17,
], $stateContextId, $operator, $dataScope);
$intent = $intentPack['data']['roomOpenIntent'] ?? [];
roomHangAssert('intent is eventless and does not occupy room',
    ($intent['eventless'] ?? false) === true
    && ($intent['roomOccupied'] ?? true) === false
    && ($intent['cartReset'] ?? true) === false
);
roomHangAssert('intent returns authoritative room and slot versions',
    ($intent['roomId'] ?? 0) === 11
    && ($intent['roomVersion'] ?? 0) === 17
    && ($intent['roomTimeSlotId'] ?? '') === 'open-service:11'
    && ($intent['roomTimeSlotVersion'] ?? 0) === 31
);
roomHangAssert('navigation only carries the validated room intent',
    ($intentPack['navigation']['routeName'] ?? '') === 'cashier-v3-cashier'
    && ($intentPack['navigation']['query']['roomOpenIntentSource'] ?? '') === CashierV3HangPreparationServices::INTENT_SOURCE
    && ($intentPack['navigation']['query']['preferredRoomId'] ?? '') === '11'
);
roomHangAssert('preparation does not mutate provider data',
    $candidateBefore === json_encode($provider->candidates, JSON_UNESCAPED_UNICODE)
);

$staleIntent = roomHangFailure(function () use ($services, $stateContextId, $operator, $dataScope): void {
    $services->prepareEmptyRoomCashier([
        'roomOpenIntentId' => 'ROOM_INTENT-00000002',
        'roomId' => 11,
        'roomVersion' => 16,
    ], $stateContextId, $operator, $dataScope);
});
roomHangAssert('stale room card is a conflict',
    $staleIntent['code'] === CashierV3ResultCode::RESOURCE_VERSION_CONFLICT
    && $staleIntent['status'] === CashierV3ResultCode::STATUS_CONFLICT,
    json_encode($staleIntent, JSON_UNESCAPED_UNICODE)
);

echo "== eventless hang preparation ==\n";
$draft = roomHangDraft($stateContextId);
$draftBefore = json_encode($draft, JSON_UNESCAPED_UNICODE);
$hangPack = $services->prepareHangOrder([
    'preparationRequestId' => 'HANG_PREPARE-00000001',
    'roomOpenIntentSource' => CashierV3HangPreparationServices::INTENT_SOURCE,
    'roomOpenIntentId' => 'ROOM_INTENT-00000001',
    'preferredRoomId' => 11,
    'preferredRoomVersion' => 17,
    'preferredRoomTimeSlotId' => 'open-service:11',
    'preferredRoomTimeSlotVersion' => 31,
], $draft, $stateContextId, $operator, $dataScope);
$snapshot = $hangPack['data']['hangOrderPreparation'] ?? [];
roomHangAssert('preferred room defaults to start service',
    ($snapshot['preferredMode'] ?? '') === 'start_service'
    && ($snapshot['preferredRoomId'] ?? 0) === 11
    && ($snapshot['startServiceAvailable'] ?? false) === true
);
roomHangAssert('workspace context is server verified',
    ($snapshot['commandContexts'][0]['kind'] ?? '') === 'cashier_workspace'
    && ($snapshot['commandContexts'][0]['id'] ?? '') === 'ws:7:21:' . $stateContextId
    && ($snapshot['commandContexts'][0]['expectedVersion'] ?? 0) === 9
);
roomHangAssert('all business effects remain false before submit',
    ($snapshot['eventless'] ?? false) === true
    && ($snapshot['cartPreserved'] ?? false) === true
    && count(array_filter((array)($snapshot['businessEffects'] ?? []))) === 0
);
roomHangAssert('hang preparation preserves the draft byte-for-byte',
    $draftBefore === json_encode($draft, JSON_UNESCAPED_UNICODE)
);
roomHangAssert('versions include workspace, room and room time slot',
    array_column($hangPack['versions'] ?? [], 'kind') === ['cashier_workspace', 'room', 'room_time_slot'],
    json_encode($hangPack['versions'] ?? [], JSON_UNESCAPED_UNICODE)
);
roomHangAssert('provider receives forced store DataScope',
    count(array_filter($provider->observedStores, function (array $row): bool {
        return $row[0] !== 7 || $row[1] !== 7;
    })) === 0
);

$provider->candidates = [roomHangCandidate(false, 18, 32)];
$occupied = roomHangFailure(function () use ($services, $draft, $stateContextId, $operator, $dataScope): void {
    $services->prepareHangOrder([
        'preparationRequestId' => 'HANG_PREPARE-00000002',
        'roomOpenIntentSource' => CashierV3HangPreparationServices::INTENT_SOURCE,
        'roomOpenIntentId' => 'ROOM_INTENT-00000001',
        'preferredRoomId' => 11,
        'preferredRoomVersion' => 17,
        'preferredRoomTimeSlotId' => 'open-service:11',
        'preferredRoomTimeSlotVersion' => 31,
    ], $draft, $stateContextId, $operator, $dataScope);
});
roomHangAssert('room taken after opening conflicts and leaves draft untouched',
    $occupied['code'] === CashierV3ResultCode::RESOURCE_VERSION_CONFLICT
    && $occupied['status'] === CashierV3ResultCode::STATUS_CONFLICT
    && $draftBefore === json_encode($draft, JSON_UNESCAPED_UNICODE),
    json_encode($occupied, JSON_UNESCAPED_UNICODE)
);

$emptyDraft = roomHangDraft($stateContextId);
$emptyDraft['lines'] = [];
$empty = roomHangFailure(function () use ($services, $emptyDraft, $stateContextId, $operator, $dataScope): void {
    $services->prepareHangOrder([
        'preparationRequestId' => 'HANG_PREPARE-00000003',
    ], $emptyDraft, $stateContextId, $operator, $dataScope);
});
roomHangAssert('empty cart cannot prepare a hang order',
    $empty['code'] === CashierV3ResultCode::INVALID_COMMAND_CONTEXT
    && $empty['status'] === CashierV3ResultCode::STATUS_FAILED
);

echo "\nroom-hang-intent-contract: {$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
