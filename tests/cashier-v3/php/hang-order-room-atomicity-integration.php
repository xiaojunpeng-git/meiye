<?php
declare(strict_types=1);

require '/tests/cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/cashier-v3/lib/_lib.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use app\services\room\guard\RoomOpenServiceGuardAuthority;
use app\services\room\guard\RoomOpenServiceGuardException;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

$passed = 0;
$failed = 0;

function hangRoomOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}

function hangRoomScopes(): array
{
    $operator = new CashierV3OperatorScope(8, 1, '3', '0');
    $dataScope = new CashierV3DataScopeContext(
        1,
        1,
        8,
        '0',
        '3',
        [8],
        CashierV3DataScopeContext::MODE_STORES,
        [],
        false,
        '',
        'hang-room-test-permission-v1',
        ['cashier.v3.hang', 'cashier.v3.room'],
        ['id' => 1, 'employee_id' => 1]
    );
    return [$operator, $dataScope];
}

function hangRoomDraft(string $stateContextId, string $lineSeed): array
{
    $lineId = 'sale:' . substr(hash('sha256', $lineSeed), 0, 48);
    return [
        'workspaceId' => 'ws:8:1:' . $stateContextId,
        'stateContextId' => $stateContextId,
        'customerMode' => 'guest',
        'memberId' => 0,
        'status' => 'editing',
        'lineFingerprint' => hash('sha256', 'workspace:' . $lineSeed),
        'complete' => true,
        'lines' => [[
            'id' => $lineId,
            'lineRole' => 'sale',
            'memberId' => 0,
            'productId' => 501,
            'productVersion' => 3,
            'skuId' => 1501,
            'skuVersion' => 4,
            'projectId' => 0,
            'quantity' => 1,
            'lineAmountCents' => 8800,
            'serviceObject' => '',
            'craftsmen' => [],
            'isExperience' => false,
            'kind' => 'product',
            'name' => '原子挂单测试产品',
        ]],
    ];
}

function hangRoomGuardFingerprint(array $locked): string
{
    $payload = [
        'contractVersion' => (string)$locked['contractVersion'],
        'roomId' => (int)$locked['roomId'],
        'roomVersion' => (int)$locked['roomVersion'],
        'slotKey' => (string)$locked['slotKey'],
        'slotVersion' => (int)$locked['slotVersion'],
        'occupied' => (bool)$locked['occupied'],
        'ownerKind' => (string)$locked['ownerKind'],
        'ownerId' => (string)$locked['ownerId'],
    ];
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function hangRoomCommand(string $seed, array $locked): array
{
    return [
        'commandIdempotencyKey' => 'HANG-COMMAND-' . $seed,
        'preparationRequestId' => 'HANG-PREP-' . $seed,
        'preparationToken' => hash('sha256', 'prepare:' . $seed),
        'mode' => CashierV3HangOrderPlanV1::MODE_START_SERVICE,
        'organizationPathSnapshot' => '/1/3/',
        'organizationNameSnapshot' => '当前组织',
        'storeNameSnapshot' => '本店',
        'memberNameSnapshot' => '',
        'operatorNameSnapshot' => '操作员一',
        'businessDate' => '2026-07-29',
        'businessTimezone' => 'Asia/Shanghai',
        'occurredAt' => 1785254400,
        'recordedAt' => 1785254401,
        'roomId' => (int)$locked['roomId'],
        'roomNameSnapshot' => (string)$locked['roomName'],
        'roomVersion' => (int)$locked['roomVersion'],
        'roomTimeSlotId' => (string)$locked['slotKey'],
        'roomTimeSlotVersion' => (int)$locked['slotVersion'],
        'roomGuardFingerprint' => hangRoomGuardFingerprint($locked),
    ];
}

Db::name('cashier_v3_hang_order_line')->delete(true);
Db::name('cashier_v3_hang_order')->delete(true);
Db::name('cashier_v3_room_open_service_guard')->delete(true);

list($operatorScope, $dataScope) = hangRoomScopes();
$guards = new RoomOpenServiceGuardAuthority();
$hangOrders = new ThinkPhpCashierV3HangOrderRepository();

$success = null;
Db::startTrans();
try {
    $locked = $guards->lockInTx(301, $operatorScope, $dataScope);
    $plan = CashierV3HangOrderPlanV1::fromLockedDraft(
        hangRoomCommand(str_repeat('S', 108), $locked),
        hangRoomDraft('CTX-HANG-SUCCESS-01', 'success-line'),
        $operatorScope,
        $dataScope
    );
    $header = $plan->header();
    $claim = $guards->claimInTx(
        301,
        (string)$locked['slotKey'],
        (int)$locked['slotVersion'],
        RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER,
        (string)$header['hang_order_id'],
        $operatorScope,
        $dataScope
    );
    $success = $hangOrders->persistInTx($plan, $operatorScope, $dataScope);
    Db::commit();
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}

$guard301 = Db::name('cashier_v3_room_open_service_guard')->where('room_id', 301)->find();
$header301 = Db::name('cashier_v3_hang_order')->where('room_id', 301)->find();
$line301 = $header301
    ? Db::name('cashier_v3_hang_order_line')->where('hang_order_id', $header301['hang_order_id'])->find()
    : null;
hangRoomOk(
    'successful start-service hang claims room and freezes header/line',
    is_array($success)
        && ($success['replayed'] ?? true) === false
        && (string)($success['hangStatus'] ?? '') === CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS
        && (string)($guard301['occupation_status'] ?? '') === RoomOpenServiceGuardAuthority::STATUS_OCCUPIED
        && (string)($guard301['owner_id'] ?? '') === (string)($header301['hang_order_id'] ?? '')
        && (string)($header301['room_guard_fingerprint'] ?? '') !== ''
        && (int)($header301['line_count'] ?? 0) === 1
        && is_array($line301)
        && (string)($line301['workspace_line_id'] ?? '') !== ''
);

$secondConflict = false;
Db::startTrans();
try {
    $locked = $guards->lockInTx(301, $operatorScope, $dataScope);
    $plan = CashierV3HangOrderPlanV1::fromLockedDraft(
        hangRoomCommand('CONFLICT-0000001', $locked),
        hangRoomDraft('CTX-HANG-CONFLICT-01', 'conflict-line'),
        $operatorScope,
        $dataScope
    );
    $guards->claimInTx(
        301,
        (string)$locked['slotKey'],
        (int)$locked['slotVersion'],
        RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER,
        (string)$plan->header()['hang_order_id'],
        $operatorScope,
        $dataScope
    );
    $hangOrders->persistInTx($plan, $operatorScope, $dataScope);
    Db::commit();
} catch (RoomOpenServiceGuardException $exception) {
    Db::rollback();
    $secondConflict = $exception->reason() === 'room_open_service_conflict';
} catch (Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
hangRoomOk(
    'second hang for occupied room conflicts without another order',
    $secondConflict
        && (int)Db::name('cashier_v3_hang_order')->where('room_id', 301)->count() === 1
        && (int)Db::name('cashier_v3_hang_order_line')->count() === 1
);

$rollbackOrderId = '';
try {
    Db::startTrans();
    $locked = $guards->lockInTx(302, $operatorScope, $dataScope);
    $plan = CashierV3HangOrderPlanV1::fromLockedDraft(
        hangRoomCommand('ROLLBACK-0000001', $locked),
        hangRoomDraft('CTX-HANG-ROLLBACK-01', 'rollback-line'),
        $operatorScope,
        $dataScope
    );
    $rollbackOrderId = (string)$plan->header()['hang_order_id'];
    $guards->claimInTx(
        302,
        (string)$locked['slotKey'],
        (int)$locked['slotVersion'],
        RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER,
        $rollbackOrderId,
        $operatorScope,
        $dataScope
    );
    $hangOrders->persistInTx($plan, $operatorScope, $dataScope);
    throw new RuntimeException('SIMULATED_LATE_PERSISTENCE_FAILURE');
} catch (RuntimeException $exception) {
    Db::rollback();
    if ($exception->getMessage() !== 'SIMULATED_LATE_PERSISTENCE_FAILURE') {
        throw $exception;
    }
}
hangRoomOk(
    'late persistence failure rolls guard, header and lines back together',
    $rollbackOrderId !== ''
        && (int)Db::name('cashier_v3_room_open_service_guard')->where('room_id', 302)->count() === 0
        && (int)Db::name('cashier_v3_hang_order')->where('hang_order_id', $rollbackOrderId)->count() === 0
        && (int)Db::name('cashier_v3_hang_order_line')->where('hang_order_id', $rollbackOrderId)->count() === 0
);

echo "HANG_ROOM_ATOMICITY_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
