<?php
declare(strict_types=1);

/** 本地真实网关/数据库回归；所有预约、回执和审计写入最终回滚。 */
$backend = getenv('BACKEND_ROOT') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
(new think\App($backend . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
});

use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use app\services\cashier\v3\reservation\CashierV3ReservationModule;
use app\services\cashier\v3\reservation\CashierV3ReservationPartitionProvider;
use think\facade\Db;

$config = config('database.connections.mysql');
if ($config['hostname'] !== 'mysql' || $config['database'] !== 'ruihao') throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
};
$dispatcher = CashierV3Bootstrap::dispatcher();
$action = 'mark-reservation-color';
$definition = CashierV3ActionManifest::requireAction($action);
$check(in_array($action, CashierV3Bootstrap::lastSelfCheck()['activated'], true), 'production graph activates color command');
$header = Db::name('cashier_v3_reservation')->alias('r')
    ->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
    ->whereIn('r.status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE', 'COMPLETED'])
    ->whereExists(function ($q) { $q->table('eb_system_store_staff')->whereRaw('store_id = r.store_id')->where('status', 1)->where('is_del', 0); })
    ->order('r.id desc')->find();
if (!$header) throw new RuntimeException('LOCAL_RESERVATION_FIXTURE_REQUIRED');
$store = (int)$header['store_id'];
$profile = Db::name('system_store_staff')->where('store_id', $store)->where('status', 1)->where('is_del', 0)->order('id asc')->find();
$operatorId = (int)$profile['id'];
$org = (string)Db::name('organization_store')->where('store_id', $store)->value('org_id');
$operator = new CashierV3OperatorScope($store, $operatorId, $org, (string)$header['tenant_id']);
$scope = new CashierV3DataScopeContext($operatorId, (int)$profile['employee_id'], $store, (string)$header['tenant_id'], $org, [$store], 'stores', [], false, '', 'r27-local', ['cashier.v3.reservation'], $profile);
$session = ['client_session_id' => 'SESSION-11111111-1111-4111-8111-111111111127', 'state_context_id' => '', 'operator_profile' => $profile];
$handler = $dispatcher->handlers()->requireCommand($action);
$key = static function (): string { return 'RESERVATION_ACTION-' . vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split('12345678123441238123' . bin2hex(random_bytes(6)), 4)); };
$run = static function ($color, string $idempotencyKey) use ($dispatcher, $action, $header, $operator, $session, $handler, $scope, $definition): array {
    return $dispatcher->gateway()->execute($action, ['idempotencyKey' => $idempotencyKey, 'contexts' => []],
        ['reservationId' => (int)$header['id'], 'color' => $color], $operator, $session, $handler, $scope, $definition);
};
$isolated = new ReflectionMethod(CashierV3ReservationModule::class, 'markColor');
$isolated->setAccessible(true);

Db::startTrans();
try {
    $sibling = $header;
    unset($sibling['id']);
    $sibling['reservation_id'] = 'R27T' . bin2hex(random_bytes(8));
    $sibling['reservation_no'] = 'R27T' . bin2hex(random_bytes(8));
    $siblingId = Db::name('cashier_v3_reservation')->insertGetId($sibling);
    $auditBefore = (int)Db::name('cashier_v3_reservation_operation')->where('reservation_id', $header['id'])->count();
    $facts = [];
    foreach (['cashier_v3_business_event', 'cashier_v3_entitlement_service_fact', 'cashier_v3_entitlement_writeoff_fact', 'cashier_v3_performance_fact', 'cashier_v3_balance_fact'] as $table) $facts[$table] = Db::name($table)->count();
    foreach (['red', 'yellow', 'blue', 'green', 'purple', ''] as $color) {
        $commandId = $key();
        $result = $run($color, $commandId);
        $check($result['status'] === 'success', 'gateway saves color ' . ($color ?: 'clear'));
        $after = Db::name('cashier_v3_reservation')->where('id', $header['id'])->find();
        $expected = $header;
        $expected['calendar_color'] = $color;
        $check($after === $expected, 'only color changes; status/time/member/business version unchanged');
        $replay = $run($color, $commandId);
        $check(!empty($replay['replay']), 'same command replays immutable receipt');
        try { $run($color === 'red' ? 'blue' : 'red', $commandId); throw new RuntimeException('changed payload reused command key'); }
        catch (CashierV3CommandException $error) { $check(true, 'same key cannot change a previously saved color'); }
        $projection = (new CashierV3ReservationPartitionProvider())->readPartition('', '', $operator, $scope, ['calendarDate' => date('Y-m-d', (int)$header['appointment_start_at']), 'pageSize' => 100]);
        $blocks = array_values(array_filter($projection['payload']['calendar']['blocks'], static function ($b) use ($header) { return $b['reservationId'] === (int)$header['id']; }));
        $check(count($blocks) >= 2 && array_unique(array_column($blocks, 'colorMark')) === [$color], 'staff and room calendars read saved color');
    }
    $check((int)Db::name('cashier_v3_reservation_operation')->where('reservation_id', $header['id'])->count() === $auditBefore + 6, 'six changes create six audits despite retries');
    $check(Db::name('cashier_v3_reservation')->where('id', $siblingId)->value('calendar_color') === $sibling['calendar_color'], 'another appointment for same member is unchanged');
    foreach (['orange', '<script>', null, [], 1] as $invalid) {
        try { $run($invalid, $key()); throw new RuntimeException('invalid color accepted'); }
        catch (CashierV3CommandException $error) { $check(true, 'invalid color rejected without a write'); }
    }
    foreach (['other-store', 'other-tenant'] as $case) {
        $otherStore = $case === 'other-store' ? $store + 100000 : $store;
        $otherTenant = $case === 'other-tenant' ? 'r27-other-tenant' : (string)$header['tenant_id'];
        $otherScope = new CashierV3DataScopeContext($operatorId, 1, $otherStore, $otherTenant, $org, [$otherStore], 'stores', [], false, '', 'r27-local', ['cashier.v3.reservation'], $profile);
        try {
            $isolated->invoke(null, ['payload' => ['reservationId' => $header['id'], 'color' => 'red'], 'operator_scope' => new CashierV3OperatorScope($otherStore, $operatorId, $org, $otherTenant), 'data_scope' => $otherScope, 'idempotency_key' => $key()]);
            throw new RuntimeException('cross scope write accepted');
        } catch (CashierV3CommandException $error) { $check(true, $case . ' cannot change reservation'); }
    }
    $readOnly = new CashierV3DataScopeContext($operatorId, 1, $store, (string)$header['tenant_id'], $org, [$store], 'stores', [], false, '', 'r27-readonly', ['cashier.v3.reservation'], $profile, true);
    try { $dispatcher->permissionGuard()->assertAllowed($definition, $readOnly); throw new RuntimeException('read-only write accepted'); }
    catch (CashierV3CommandException $error) { $check(true, 'read-only session cannot mark color'); }
    foreach ($facts as $table => $count) $check(Db::name($table)->count() === $count, $table . ' unchanged');
    echo "R27 RESERVATION COLOR MYSQL PASS (rollback only)\n";
} finally { Db::rollback(); }
