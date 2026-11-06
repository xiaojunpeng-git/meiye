<?php
declare(strict_types=1);

// Isolated behavior test: no 007 connection, no WeChat request, no business write.
namespace think\facade {
    final class Db {
        public static $rows = [];
        public static function name(string $table): Query { return new Query($table); }
    }
    final class Query {
        private $table;
        private $filters = [];
        public function __construct(string $table) { $this->table = $table; }
        public function where(string $key, $value): self { $this->filters[$key] = $value; return $this; }
        public function field(string $fields): self { return $this; }
        private function rows(): array {
            return array_values(array_filter(Db::$rows[$this->table] ?? [], function (array $row): bool {
                foreach ($this->filters as $key => $value) if (($row[$key] ?? null) != $value) return false;
                return true;
            }));
        }
        public function select(): Collection { return new Collection($this->rows()); }
        public function find(): ?array { return $this->rows()[0] ?? null; }
        public function value(string $key) { return $this->rows()[0][$key] ?? null; }
    }
    final class Collection {
        private $rows;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function toArray(): array { return $this->rows; }
    }
    final class Log {
        public static function warning(string $text): void {}
        public static function error(string $text): void {}
    }
}
namespace app\services\message\notice {
    final class RoutineTemplateListService {
        public static $sent = [];
        public $mark = '';
        public function setEvent(string $mark): self { $this->mark = $mark; return $this; }
        public function sendTemplate(string $code, int $member, array $data, ?string $link = null): void {
            self::$sent[] = compact('code', 'member', 'data', 'link') + ['mark' => $this->mark];
        }
    }
}
namespace {
    function app() { return new class {
        public function make(string $class) { return new $class(); }
    }; }
    require __DIR__ . '/../../后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutMemberNotificationServices.php';
    use app\services\cashier\v3\settlement\CashierV3CheckoutMemberNotificationServices;
    use app\services\message\notice\RoutineTemplateListService;
    use think\facade\Db;

    $check = static function (bool $condition, string $case): void {
        if (!$condition) throw new \RuntimeException($case);
        echo "PASS {$case}\n";
    };
    Db::$rows = [
        'system_notification' => [
            ['mark' => 'order_writeoff', 'is_routine' => 1],
            ['mark' => 'yue_change', 'is_routine' => 1],
        ],
        'cashier_v3_business_event' => [[
            'command_idempotency_key' => 'C-1',
            'event_type' => 'entitlement.writeoff.completed',
            'event_no' => 'EV-1', 'member_id' => 84, 'store_id' => 10,
            'occurred_at' => 1793505600,
            'payload' => json_encode(['sourceDetailId' => 55, 'projectId' => 8996, 'quantity' => 1]),
        ]],
        'store_order_cart_info' => [['id' => 55, 'oid' => 990, 'write_surplus_times' => 3]],
        'store_product' => [['id' => 8996, 'store_name' => '护理项目']],
        'system_store' => [['id' => 10, 'name' => '湾悦城店']],
        'cashier_v3_balance_fact' => [[
            'fact_id' => 'CFB-1', 'command_idempotency_key' => 'C-1',
            'balance_change_type' => 'order_payment', 'fact_direction' => 'forward',
            'status' => 'effective', 'order_id' => 'CSO-1',
            'member_id' => 84, 'store_id' => 10,
            'principal_delta_cents' => -17000, 'bonus_delta_cents' => 0,
            'principal_after_cents' => 83000, 'bonus_after_cents' => 0,
            'settled_at' => 1793505600,
        ]],
        'cashier_v3_sales_order' => [[
            'order_id' => 'CSO-1', 'command_idempotency_key' => 'C-1',
            'member_id' => 84, 'store_id' => 10,
            'order_status' => 'settled', 'order_direction' => 'forward',
            'order_no' => 'XS26110100001', 'settled_at' => 1793505600,
        ]],
    ];
    // The committed wallet fact remains sufficient when receipt normalization
    // omits balancePayment; no browser-supplied amount may drive a notice.
    $submission = ['salesOrder' => ['orderId' => 'CSO-1']];
    $sender = new CashierV3CheckoutMemberNotificationServices();
    $sender->sendAfterCommit('C-1', $submission);
    $sent = RoutineTemplateListService::$sent;
    $check(count($sent) === 2, 'committed writeoff and balance debit each request one notification');
    $check($sent[0]['mark'] === 'order_writeoff' && $sent[0]['code'] === 'CIKA_CHUANGE'
        && $sent[0]['data']['number3'] === '3', 'writeoff reads committed remaining rights');
    $check($sent[1]['mark'] === 'yue_change' && $sent[1]['code'] === 'YUE_CHANGE'
        && $sent[1]['data']['amount2'] === '170.00'
        && $sent[1]['data']['amount3'] === '830.00', 'balance uses actual debit and post-balance');
    RoutineTemplateListService::$sent = [];
    Db::$rows['system_notification'][0]['is_routine'] = 0;
    Db::$rows['system_notification'][1]['is_routine'] = 0;
    $sender->sendAfterCommit('C-1', $submission);
    $check(RoutineTemplateListService::$sent === [], 'disabled switches send nothing');
    Db::$rows['system_notification'][0]['is_routine'] = 1;
    Db::$rows['system_notification'][1]['is_routine'] = 1;
    Db::$rows['cashier_v3_balance_fact'] = [];
    $sender->sendAfterCommit('C-1', $submission);
    $check(count(RoutineTemplateListService::$sent) === 1, 'no committed wallet debit sends no balance message');
    Db::$rows['cashier_v3_balance_fact'][] = [
        'fact_id' => 'CFB-2', 'command_idempotency_key' => 'C-1',
        'balance_change_type' => 'order_payment', 'fact_direction' => 'forward',
        'status' => 'effective', 'order_id' => 'CSO-1',
        'member_id' => 84, 'store_id' => 10,
        'principal_delta_cents' => -17000, 'bonus_delta_cents' => 0,
        'principal_after_cents' => 83000, 'bonus_after_cents' => 0,
        'settled_at' => 1793505600,
    ];
    Db::$rows['cashier_v3_sales_order'][0]['order_status'] = 'pending';
    RoutineTemplateListService::$sent = [];
    $sender->sendAfterCommit('C-1', $submission);
    $check(count(RoutineTemplateListService::$sent) === 1, 'unsettled sale cannot request balance notice');

    $gateway = (string)file_get_contents(__DIR__ . '/../../后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php');
    $transactionEnd = strpos($gateway, "                });\n                // Notifications are external effects");
    $sendCall = strpos($gateway, '->sendAfterCommit($idempotencyKey');
    $check($transactionEnd !== false && $sendCall !== false && $transactionEnd < $sendCall,
        'notification dispatch occurs only after the checkout transaction closes');
    $check(strpos($gateway, "empty(\$commandResult['replay'])") !== false,
        'command receipt replay never requests a second delivery');
    $runtime = (string)file_get_contents(__DIR__ . '/../../后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutMemberNotificationServices.php');
    $check(strpos($runtime, '007.cc3798.com') === false && strpos($runtime, "Db::name('system_notification')") !== false,
        'runtime delivery reads only local notification switches');
}
