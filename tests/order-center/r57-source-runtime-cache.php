<?php
/** Exercise the real runtime graph in one worker. Only metric/config/storage
 * ports and domain row readers are isolated; no application or DB is opened.
 */
namespace app\services\cashier\v3\config {
    class CashierV3BusinessSourcePolicyServices {
        public static $enabled = true;
        public function read(): array { return ['enabled' => self::$enabled]; }
    }
}
namespace app\services\metric {
    class MetricDictionaryServices {
        public function getDefinitions(): array { return [['code' => 'r57_metric', 'name' => 'Fixture metric']]; }
    }
}
namespace {
    // The independent R60 debt switch stays enabled in these source-only fixtures.
    function sys_config(string $name, $default = null) { return $name === 'cashier_debt_pay_switch' ? 1 : $default; }
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    $backend = dirname(__DIR__, 2) . '/后端代码';
    spl_autoload_register(static function ($class) use ($backend): void {
        if (strpos($class, 'app\\') !== 0) return;
        $file = $backend . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) require_once $file;
    });
    use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices as Policy;
    use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract as Contract;
    use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryRegistrar as Registrar;
    use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryWorkerContextResolver as Worker;
    use app\services\query\UnifiedQueryRuntime as Runtime;
    class R57FixtureProvider implements \app\services\query\UnifiedQueryProvider {
        private $page;
        public function __construct(string $page) { $this->page = $page; }
        public function pageCode(): string { return $this->page; }
        public function query(array $context, array $payload): array { throw new \LogicException('Reader not used'); }
        public function executeFrozenPlan(array $context, array $plan, string $exportScope, array $fieldKeys): array { throw new \LogicException('Reader not used'); }
    }
    // Providers are distinct configured classes; the container substitutes only
    // their read ports so the real runtime/registry/validator graph is executed.
    $providerMap = [];
    foreach (Contract::PAGE_BY_TYPE as $type => $page) $providerMap['R57Provider_' . $type] = $page;
    foreach ($providerMap as $class => $page) class_alias(R57FixtureProvider::class, $class);
    $configuration = ['registrars' => [Registrar::class], 'providers' => array_keys($providerMap),
        'permission_resolvers' => [], 'worker_context_resolvers' => [Worker::class]];
    function config(string $key) { global $configuration; return $configuration[substr($key, strlen('unified_query.'))] ?? null; }
    class R57Container {
        public $bound = [];
        public function make(string $class) {
            global $providerMap;
            return isset($providerMap[$class]) ? new R57FixtureProvider($providerMap[$class]) : new $class();
        }
        public function instance(string $class, $value): void { $this->bound[$class] = $value; }
    }
    function app(): R57Container { static $app; return $app ?: ($app = new R57Container()); }
    $checks = 0;
    $previous = null;
    foreach ([true, false, true] as $enabled) {
        Policy::$enabled = $enabled;
        $graph = Runtime::runtime();
        if ($previous !== null && $previous === $graph['registry']) throw new \RuntimeException('Changed capability retained stale registry');
        $checks++;
        if (Runtime::runtime()['registry'] !== $graph['registry']) throw new \RuntimeException('Unchanged capability rebuilt graph');
        $checks++;
        foreach (Contract::PAGE_BY_TYPE as $type => $page) {
            $keys = array_column($graph['registry']->fieldsForCapability($page, []), 'key');
            $original = array_column(Contract::definition($page)['fields'], 'key');
            if (in_array('source', $keys, true) !== ($enabled && in_array('source', $original, true))) throw new \RuntimeException('Stale source field: ' . $page);
            $checks++;
            foreach (['source_order_no', 'source_card', 'gift_source'] as $technical) {
                if (in_array($technical, $keys, true) !== in_array($technical, $original, true)) throw new \RuntimeException('Technical source removed');
                $checks++;
            }
        }
        $previous = $graph['registry'];
    }
    echo json_encode(['result' => 'PASS', 'checks' => $checks, 'businessWrites' => 0]), PHP_EOL;
}
