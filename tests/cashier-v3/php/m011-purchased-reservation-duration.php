<?php
declare(strict_types=1);

namespace think\facade {
    /** Read-only fake records query boundaries; any unexpected table/write fails the test. */
    class Db {
        public static $rows = [];
        public static $reads = [];
        public static $fail = false;
        public static function name($table) {
            if ($table !== 'store_product') throw new \RuntimeException('Unexpected table access');
            if (self::$fail) throw new \RuntimeException('Database unavailable');
            return new M011ProductQuery();
        }
    }
    class M011ProductQuery {
        private $id;
        public function where($key, $id) {
            if ($key !== 'id') throw new \RuntimeException('Purchased duration must not require store sale filters');
            $this->id = (int)$id;
            return $this;
        }
        public function field($fields) { return $this; }
        public function find() { Db::$reads[] = $this->id; return Db::$rows[$this->id] ?? null; }
        public function value($field) { $row = $this->find(); return $row[$field] ?? null; }
    }
}
namespace app\services\product\product {
    class StoreProductReservationServices {
        public const DEFAULT_PROJECT_SERVICE_DURATION = 60;
        public static $calls = [];
        public function getProductInfo($id, $store) {
            self::$calls[] = [$id, $store];
            if ($id !== 2170 || $store !== 113) throw new \RuntimeException('Missing store product');
            return [$id, ['project_service_duration' => 45]];
        }
    }
}
namespace app\services\cashier\v3 {
    class CashierV3ResultCode {
        public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
        public const INVALID_COMMAND_CONTEXT = 'INVALID_COMMAND_CONTEXT';
        public const STATUS_FAILED = 'failed';
    }
    class CashierV3CommandException extends \RuntimeException {
        public function __construct($code, $message, $status = '', $detail = []) { parent::__construct($message); }
    }
}
namespace {
    function app() { return new class {
        public function make($type) {
            if ($type !== \app\services\product\product\StoreProductReservationServices::class) throw new \RuntimeException('Unexpected service');
            return new $type();
        }
    }; }
    $module = getenv('M011_MODULE_PATH') ?: dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php';
    require $module;
    $class = \app\services\cashier\v3\reservation\CashierV3ReservationModule::class;
    $duration = new \ReflectionMethod($class, 'purchasedProjectDuration');
    $duration->setAccessible(true);
    $plans = new \ReflectionMethod($class, 'projectPlansForStore');
    $plans->setAccessible(true);
    $checks = 0;
    $ok = function ($label, $condition) use (&$checks) {
        if (!$condition) throw new \RuntimeException($label);
        ++$checks; echo 'PASS ' . $label . PHP_EOL;
    };
    \think\facade\Db::$rows = [
        8382 => ['id' => 8382, 'pid' => 0, 'project_service_duration' => 60, 'is_show' => 0],
        8404 => ['id' => 8404, 'pid' => 0, 'project_service_duration' => 90],
        10 => ['id' => 10, 'pid' => 8404, 'project_service_duration' => 0],
        11 => ['id' => 11, 'pid' => 10, 'project_service_duration' => 35],
        12 => ['id' => 12, 'pid' => 0, 'project_service_duration' => -10],
        13 => ['id' => 13, 'pid' => 999, 'project_service_duration' => null],
        14 => ['id' => 14, 'pid' => 0],
    ];
    $before = serialize(\think\facade\Db::$rows);
    foreach ([8382 => 60, 8404 => 90, 10 => 90, 11 => 35, 12 => 60, 13 => 60, 14 => 60, 999 => 60] as $id => $expected) {
        $ok('duration ' . $id, $duration->invoke(null, $id) === $expected);
    }
    [$rows, $total] = $plans->invoke(null, [
        ['projectId' => 8382, 'source' => 'card', 'entitlementSourceDetailId' => 123, 'duration' => 999, 'quantity' => 2],
        ['projectId' => 2170, 'source' => 'unpaid', 'quantity' => 1],
    ], 113);
    $ok('paid plus unpaid duration total', $total === 165);
    $ok('ignore client duration', $rows[0]['duration'] === 60);
    $ok('retain entitlement identity', $rows[0]['entitlementSourceDetailId'] === 123);
    $ok('unpaid route remains store-scoped', \app\services\product\product\StoreProductReservationServices::$calls === [[2170, 113]]);
    try {
        $plans->invoke(null, [['projectId' => 8382, 'source' => 'unpaid']], 113);
        throw new \RuntimeException('Unpaid unavailable product accepted');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        $ok('unpaid unavailable product rejected', true);
    }
    try {
        $plans->invoke(null, [['projectId' => 8382, 'source' => 'card']], 113);
        throw new \RuntimeException('Missing entitlement accepted');
    } catch (\app\services\cashier\v3\CashierV3CommandException $error) {
        $ok('purchased detail still required', true);
    }
    \think\facade\Db::$fail = true;
    try { $duration->invoke(null, 8382); throw new \LogicException('Database failure hidden'); }
    catch (\RuntimeException $error) { $ok('database failure not converted to default', $error->getMessage() === 'Database unavailable'); }
    $ok('original records not modified', $before === serialize(\think\facade\Db::$rows));
    echo 'M011_PURCHASED_DURATION=PASS checks=' . $checks . PHP_EOL;
}
