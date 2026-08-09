<?php
declare(strict_types=1);

namespace app\services\cashier\v3 {
    final class CashierV3TransactionGuard
    {
        public static function assertInTransaction(string $operation): void {}
    }
}

namespace {
    $root = getenv('C3_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
    require_once $root . '/app/services/cashier/v3/CashierV3DataScopeContext.php';
    require_once $root . '/app/services/cashier/v3/checkout/provider/CashierV3EntitlementProviderContracts.php';
    require_once $root . '/app/services/cashier/v3/checkout/provider/CashierV3ServiceOrderOccupationAuthority.php';
    require_once $root . '/app/services/cashier/v3/service/CashierV3ServiceOrderAuthorityException.php';
    require_once $root . '/app/services/cashier/v3/service/CashierV3ServiceOrderState.php';
    require_once $root . '/app/services/cashier/v3/service/CashierV3ServiceOrderRepository.php';
    require_once $root . '/app/services/cashier/v3/service/ThinkPhpCashierV3ServiceOrderRepository.php';
    require_once $root . '/app/services/cashier/v3/service/CashierV3ServiceOrderOccupationAuthorityProvider.php';

    use app\services\cashier\v3\CashierV3DataScopeContext;
    use app\services\cashier\v3\service\CashierV3ServiceOrderAuthorityException;
    use app\services\cashier\v3\service\CashierV3ServiceOrderOccupationAuthorityProvider;
    use app\services\cashier\v3\service\CashierV3ServiceOrderRepository;
    use app\services\cashier\v3\service\CashierV3ServiceOrderState;

    final class C3FakeRepository implements CashierV3ServiceOrderRepository
    {
        public $set = ['orders' => [], 'lines' => [], 'targetLines' => []];
        public $calls = [];

        public function transaction(callable $callback) { return $callback(); }
        public function lockOrCreateEntitlementGuard(string $tenantId, int $detailId): array
        {
            $this->calls[] = 'guard:' . $detailId;
            return ['current_version' => 1];
        }
        public function lockOccupationSet(string $tenantId, int $detailId, int $includeId = 0): array
        {
            $this->calls[] = 'set:' . $detailId . ':' . $includeId;
            return $this->set;
        }
        public function findOperation(string $tenantId, string $idempotencyKey): ?array { return null; }
        public function insertServiceOrder(array $row): array { throw new \LogicException('unused'); }
        public function insertLine(array $row): array { throw new \LogicException('unused'); }
        public function updateServiceOrderCas(string $tenantId, int $id, int $version, array $fields): bool { return false; }
        public function updateLineCas(string $tenantId, int $id, int $version, array $fields): bool { return false; }
        public function bumpEntitlementGuardCas(string $tenantId, int $detailId, int $version, string $action, int $now): int { return 0; }
        public function insertOperation(array $row): array { throw new \LogicException('unused'); }
    }

    $passed = 0;
    $failed = 0;
    function c3ok(string $name, bool $condition, string $detail = ''): void
    {
        global $passed, $failed;
        if ($condition) { $passed++; echo "PASS {$name}\n"; return; }
        $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
    }
    function c3reject(string $name, string $reason, callable $callback): void
    {
        try { $callback(); c3ok($name, false, 'accepted'); }
        catch (CashierV3ServiceOrderAuthorityException $e) { c3ok($name, $e->reason() === $reason, $e->reason()); }
    }
    function c3scope(string $mode, $visible = null, int $employeeId = 101): CashierV3DataScopeContext
    {
        return new CashierV3DataScopeContext(
            9, $employeeId, 7, 'tenant-1', 'org-1', $visible, $mode, [],
            $mode === CashierV3DataScopeContext::MODE_ALL, '', 'scope-v1', [], []
        );
    }
    function c3request(string $type, int $serviceOrderId = 0, int $reservationId = 0): array
    {
        return [
            'tenantId' => 'tenant-1', 'storeId' => 7, 'entitlementSourceDetailId' => 501,
            'source' => compact('type', 'serviceOrderId', 'reservationId'),
        ];
    }
    function c3order(int $id, string $status, int $version, array $participants = [101], int $storeId = 7): array
    {
        return [
            'id' => $id, 'tenant_id' => 'tenant-1', 'business_store_id' => $storeId,
            'participant_employee_ids_json' => json_encode($participants),
            'status' => $status, 'version' => $version,
        ];
    }
    function c3line(int $id, int $orderId, int $times, string $status = 'ACTIVE'): array
    {
        return [
            'id' => $id, 'tenant_id' => 'tenant-1', 'service_order_id' => $orderId,
            'entitlement_source_detail_id' => 501, 'occupied_times' => $times, 'status' => $status,
        ];
    }

    $repo = new C3FakeRepository();
    $repo->set = [
        'orders' => [
            c3order(11, 'IN_SERVICE', 3), c3order(12, 'OPEN', 4, [202], 8),
            c3order(13, 'COMPLETED', 5),
        ],
        'lines' => [],
        'targetLines' => [
            c3line(101, 11, 1), c3line(102, 11, 2), c3line(103, 12, 1),
            c3line(104, 13, 4), c3line(105, 12, 0, 'RELEASED'),
        ],
    ];
    $authority = new CashierV3ServiceOrderOccupationAuthorityProvider($repo);

    $direct = $authority->lockContributorsInTx(c3request('direct'), c3scope(CashierV3DataScopeContext::MODE_ALL));
    c3ok('direct returns every active service contributor', count($direct) === 2 && array_column($direct, 'id') === [11, 12]);
    c3ok('direct converts no service occupation', array_sum(array_column($direct, 'convertibleTimes')) === 0);
    c3ok('same order lines aggregate into one contributor', $direct[0]['occupiedTimes'] === 3 && $direct[0]['version'] === 3);
    c3ok('inactive order and released line are excluded', array_sum(array_column($direct, 'occupiedTimes')) === 4);
    c3ok('guard precedes occupation set', $repo->calls[0] === 'guard:501' && $repo->calls[1] === 'set:501:0');

    $repo->calls = [];
    $reservation = $authority->lockContributorsInTx(c3request('reservation', 0, 71), c3scope(CashierV3DataScopeContext::MODE_STORES, [7]));
    c3ok('reservation also returns service competitors', count($reservation) === 2);
    c3ok('reservation converts no service occupation', array_sum(array_column($reservation, 'convertibleTimes')) === 0);

    $repo->calls = [];
    $service = $authority->lockContributorsInTx(c3request('service_order', 11), c3scope(CashierV3DataScopeContext::MODE_SELF_PARTICIPANT));
    c3ok('current service order converts its full aggregation', $service[0]['id'] === 11 && $service[0]['convertibleTimes'] === 3);
    c3ok('other service order remains occupied but not convertible', $service[1]['id'] === 12 && $service[1]['occupiedTimes'] === 1 && $service[1]['convertibleTimes'] === 0);
    c3ok('current source is included in deterministic lock set', $repo->calls === ['guard:501', 'set:501:11']);

    c3reject('none scope fails closed', 'service_order_authority_scope_denied', function () use ($authority) {
        $authority->lockContributorsInTx(c3request('direct'), c3scope(CashierV3DataScopeContext::MODE_NONE, []));
    });
    c3reject('self participant denies non participant', 'service_order_authority_self_participant_denied', function () use ($authority) {
        $authority->lockContributorsInTx(c3request('service_order', 11), c3scope(CashierV3DataScopeContext::MODE_SELF_PARTICIPANT, [], 999));
    });
    c3reject('self participant cannot authorize direct source', 'service_order_authority_self_participant_source_required', function () use ($authority) {
        $authority->lockContributorsInTx(c3request('direct'), c3scope(CashierV3DataScopeContext::MODE_SELF_PARTICIPANT));
    });

    $originalSet = $repo->set;
    c3ok('cross store competitor remains counted', $direct[1]['id'] === 12 && $direct[1]['occupiedTimes'] === 1);
    $repo->set['orders'][0]['business_store_id'] = 8;
    c3reject('current service order must match operation store', 'current_service_order_store_mismatch', function () use ($authority) {
        $authority->lockContributorsInTx(c3request('service_order', 11), c3scope(CashierV3DataScopeContext::MODE_ALL));
    });
    $repo->set = $originalSet;
    $repo->set['orders'][0]['status'] = 'CANCELLED';
    c3reject('current service order must remain active', 'current_service_order_not_active_contributor', function () use ($authority) {
        $authority->lockContributorsInTx(c3request('service_order', 11), c3scope(CashierV3DataScopeContext::MODE_ALL));
    });
    $repo->set = $originalSet;

    $invalid = c3request('direct');
    $invalid['source']['serviceOrderId'] = 1;
    c3reject('direct source shape rejects hidden service id', 'service_order_authority_source_invalid', function () use ($authority, $invalid) {
        $authority->lockContributorsInTx($invalid, c3scope(CashierV3DataScopeContext::MODE_ALL));
    });
    c3ok('active state catalog is explicit', CashierV3ServiceOrderState::activeOrderStatuses() === ['OPEN','IN_SERVICE','PENDING_CHECKOUT']);
    CashierV3ServiceOrderState::assertOrdinaryTransition('OPEN', 'IN_SERVICE');
    c3ok('ordinary active transition is allowed', true);
    c3reject('completed is not an ordinary transition', 'service_order_transition_invalid', function () {
        CashierV3ServiceOrderState::assertOrdinaryTransition('PENDING_CHECKOUT', 'COMPLETED');
    });

    echo "C3_PURE_CONTRACT passed={$passed} failed={$failed}\n";
    exit($failed === 0 ? 0 : 1);
}
