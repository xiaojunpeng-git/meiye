<?php
declare(strict_types=1);

namespace app\services\query {
    class UnifiedQueryException extends \RuntimeException {
        public function __construct(string $code, string $message, array $details) { parent::__construct($message); }
    }
    class UnifiedQueryRuntime { public static function runtime(): array { return ['contextFactory' => new \stdClass()]; } }
}
namespace app\services\cashier\v3 { class CashierV3ScopeResolver { public const TENANT_SCOPE_ID = 'test-tenant'; } }
namespace app\services\product\inventory\query {
    class InventoryPlatformUnifiedQueryContextFactory {
        public static array $calls = [];
        public static bool $denied = false;
        public static bool $cost = false;
        public function __construct($core) {}
        public function make($id, $info, $date, $store, $subject, $page): array {
            self::$calls[] = [$id, $store, $subject, $page];
            if (self::$denied) throw new \RuntimeException('scope-denied');
            return ['tenant_id' => 'test-tenant', 'scope_dimensions' => ['location_id' => [91]],
                'permissions' => self::$cost ? ['inventory.cost.view', 'inventory.location.manage'] : []];
        }
    }
}
namespace think\facade {
    class Db {
        public static array $reads = [];
        public static array $fact = ['order_sn' => 'RK1', 'source_type' => 'manual_outbound', 'business_date' => '2026-10-11',
            'recorded_at' => 1, 'quantity_units' => 20, 'quantity_scale' => 1, 'unit_cost_cents' => 155, 'cost_amount_cents' => 310];
        public static function name($name) { return new Query($name); }
    }
    class Query {
        private string $table;
        private array $where = [];
        public function __construct($table) { $this->table = $table; }
        public function where($key, $value) { $this->where[$key] = $value; return $this; }
        public function __call($name, $args) { return $this; }
        public function find() {
            Db::$reads[] = [$this->table, $this->where];
            return ['id' => 91, 'store_id' => 80, 'tenant_id' => 'test-tenant', 'location_name' => 'store warehouse'];
        }
        public function toArray() { Db::$reads[] = [$this->table, $this->where]; return [Db::$fact]; }
    }
}
namespace app\services\product\inventory {
    class InventoryPlatformAccessPolicy {
        public function canVoidHqInbound(array $access): bool {
            return in_array('inventory.location.manage', (array)($access['features'] ?? []), true)
                || in_array('admin-inbound-manage', (array)($access['unique_auth'] ?? []), true);
        }
    }
    class InventoryHqLocationServices {
        public function readableLocation($info, $id) { return ['location' => ['id' => $id, 'store_id' => 0, 'tenant_id' => 'test-tenant'],
            'access' => ['features' => ['inventory.cost.view', 'inventory.location.manage']]]; }
    }
    class InventoryManualDocumentReversalServices { public const INBOUND = 'manual_inbound'; public const OUTBOUND = 'manual_outbound'; }
    class InventoryManualDocumentReversalProjectionServices { public function settledIndex(...$args) { return []; } }
    class InventoryOutboundHeaderServices { public function apply($rows, ...$args) { return $rows; } }
    class InventoryInboundOutboundTraceReadServices {
        public function detail($location, $storeId, $sourceId, $cost) { return compact('location', 'storeId', 'sourceId', 'cost'); }
    }
}
namespace {
    use app\services\product\inventory\InventoryPlatformDocumentReadScope;
    use app\services\product\inventory\InventoryPlatformHqInboundServices;
    use app\services\product\inventory\InventoryPlatformHqOutboundServices;
    use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory as Context;
    use think\facade\Db;

    $root = dirname(__DIR__, 3) . '/后端代码/app/services/product/inventory/';
    foreach (['InventoryPlatformDocumentReadScope', 'InventoryPlatformHqInboundServices', 'InventoryPlatformHqOutboundServices', 'InventoryMovementQueryServices'] as $class) require $root . $class . '.php';
    function check(bool $ok, string $name): void { if (!$ok) throw new \RuntimeException($name); }
    function rejected(callable $call): void { try { $call(); } catch (\RuntimeException | \InvalidArgumentException $e) { return; } throw new \RuntimeException('expected rejection'); }
    $admin = ['id' => 4];
    $selection = ['subject' => 'STORE', 'storeId' => '80', 'locationId' => '91'];
    $scope = new InventoryPlatformDocumentReadScope();
    rejected(fn() => $scope->resolve($admin, 7, 'inventory_inbound', ['subject' => 'invalid']));
    foreach ([0, -1, '80abc', [], '080'] as $id) rejected(fn() => $scope->resolve($admin, 7, 'inventory_inbound', array_replace($selection, ['storeId' => $id])));
    $before = count(Db::$reads);
    rejected(fn() => $scope->resolve($admin, 7, 'inventory_inbound', array_replace($selection, ['locationId' => 92])));
    check(count(Db::$reads) === $before, 'foreign location rejected before detail query');
    Context::$denied = true;
    rejected(fn() => (new InventoryPlatformHqInboundServices())->detail($admin, 7, 'document', $selection));
    check(count(Db::$reads) === $before, 'page/store denial never falls back to HQ');
    Context::$denied = false;
    foreach ([false, true] as $cost) {
        Context::$cost = $cost;
        foreach ([new InventoryPlatformHqInboundServices(), new InventoryPlatformHqOutboundServices()] as $index => $service) {
            $detail = $service->detail($admin, 7, 'document', $selection);
            check($detail['lines'][0]['quantity'] === '2', 'quantity projection unchanged');
            check($detail['lines'][0]['unit_cost_cents'] === ($cost ? 155 : null), 'server cost redaction');
            check($detail['document']['can_void'] === false, 'store read cannot grant HQ writes');
            check(Context::$calls[count(Context::$calls) - 1] === [4, 80, 'STORE', $index ? 'inventory_outbound' : 'inventory_inbound'], 'exact page and authenticated admin scope');
            $factRead = Db::$reads[count(Db::$reads) - 1][1];
            check($factRead['f.store_id'] === 80 && $factRead['f.location_id'] === 91 && $factRead['f.tenant_id'] === 'test-tenant', 'fact is scoped by tenant, store and location');
        }
    }
    $hq = (new InventoryPlatformHqInboundServices())->detail($admin, 7, 'document');
    check($hq['document']['can_void'] === true, 'existing HQ capability retained');
    $trace = (new InventoryPlatformHqInboundServices())->outboundDetails($admin, 7, 'document', $selection);
    check($trace['storeId'] === 80 && $trace['location']['id'] === 91, 'trace follows selected scope');
    Db::$fact += ['presale_claim_status' => 'VOIDED', 'presale_void_reason' => 'test', 'presale_voided_at' => 2];
    Db::$fact['source_type'] = 'presale_claim_outbound';
    $presale = (new InventoryPlatformHqOutboundServices())->detail($admin, 7, 'document', $selection);
    check($presale['document']['status_name'] === '已作废' && !$presale['document']['can_void'], 'presale status retains original projector');
    echo "PLATFORM_DOCUMENT_DETAIL_SCOPE_OK: selection, denial, scoped facts, cost, HQ capability, trace, presale\n";
}
