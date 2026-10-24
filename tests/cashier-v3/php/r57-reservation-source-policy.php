<?php
/** Isolated adapter checks: no DB, config writes or business facts are created. */
namespace app\services\cashier\v3\config {
    final class CashierV3BusinessSourcePolicyServices {
        public static $enabled = false;
        public static $calls = [];
        public function read(): array { return ['enabled' => self::$enabled]; }
        public function resolveChoice($kind, $tenant, $store, $member, array $candidate): array {
            self::$calls[] = [$kind, $tenant, $store, $member, $candidate];
            return ['primarySourceId' => self::$enabled ? $candidate['primarySourceId'] : 9,
                'secondarySourceId' => 0, 'primarySourceNameSnapshot' => 'fixed',
                'secondarySourceNameSnapshot' => '', 'displayNameSnapshot' => 'fixed', 'attributionTypeSnapshot' => 'other'];
        }
    }
}
namespace {
    use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices as Policy;
    require dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php';
    $class = new ReflectionClass(\app\services\cashier\v3\reservation\CashierV3ReservationModule::class);
    $edit = $class->getMethod('sourceForEdit');
    $create = $class->getMethod('reservationBusinessSource');
    if (PHP_VERSION_ID < 80100) { $edit->setAccessible(true); $create->setAccessible(true); }
    $header = ['tenant_id' => 'tenant-one', 'store_id' => 5, 'member_id' => 11,
        'business_source_primary_id' => 7, 'business_source_label_snapshot' => 'historical'];
    $check = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
    $result = $edit->invoke(null, ['businessSourcePrimaryId' => 99], $header);
    $check($result['business_source_primary_id'] === 7, 'fixed edit must retain saved source');
    $check($result['business_source_label_snapshot'] === 'historical', 'must retain historical label');
    $check(count(Policy::$calls) === 0, 'saved fixed source must not be resolved again');
    $empty = array_merge($header, ['business_source_primary_id' => 0]);
    $result = $edit->invoke(null, [], $empty);
    $check($result['business_source_primary_id'] === 9, 'source-less pending confirmation gets fixed source');
    $result = $create->invoke(null, ['businessSourcePrimaryId' => 99], 'tenant-one', 5, 11);
    $check($result['business_source_primary_id'] === 9, 'new fixed source must ignore client ID');
    $check(array_slice(Policy::$calls[1], 0, 4) === ['purchase', 'tenant-one', 5, 11], 'scope must come from server');
    Policy::$enabled = true;
    $before = count(Policy::$calls);
    $result = $edit->invoke(null, ['businessSourcePrimaryId' => 7], $header);
    $check($result['business_source_label_snapshot'] === 'historical', 'same IDs preserve historical snapshot');
    $check(count(Policy::$calls) === $before, 'same source must not consult changed catalog');
    $result = $edit->invoke(null, ['businessSourcePrimaryId' => 8], $header);
    $check($result['business_source_primary_id'] === 8, 'explicit edit must go through policy');
    $result = $edit->invoke(null, [], $header);
    $check($result['business_source_primary_id'] === 7, 'retry without source retains saved choice');
    echo "R57_RESERVATION_SOURCE_ADAPTER=PASS (10 checks; policy dependency stubbed; no DB)\n";
}
