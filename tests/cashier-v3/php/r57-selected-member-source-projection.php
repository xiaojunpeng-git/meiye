<?php
/** Execute the actual summary projection closure. Only its two read ports are
 * isolated; no dispatcher command, database, workspace or financial write runs.
 */
namespace app\services\cashier\v3\config {
    class CashierV3BusinessSourcePolicyServices {
        public static $calls = [];
        public function initialChoices($tenant, $store, $member): array {
            self::$calls[] = [$tenant, $store, $member];
            return ['purchase' => ['primarySourceId' => 41], 'recharge' => ['primarySourceId' => 42], 'repayment' => ['primarySourceId' => 43]];
        }
    }
}
namespace {
    $file = dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php';
    $source = file_get_contents($file);
    $start = strpos($source, 'function (array $scope) use ($cashierMemberSummaries): array {');
    $end = strpos($source, "\n            });", $start);
    if ($start === false || $end === false) throw new \RuntimeException('Summary closure boundary changed');
    $cashierMemberSummaries = new class {
        public $calls = [];
        public function read($member, $store): array {
            $this->calls[] = [$member, $store];
            return ['id' => (string)$member, 'memberId' => $member];
        }
    };
    $projection = eval('return ' . substr($source, $start, $end - $start) . "\n};");
    $operator = new class { public function storeId(): int { return 23; } };
    $data = new class { public function tenantId(): string { return 'authorized-tenant'; } };
    $result = $projection(['payload' => ['memberId' => 101, 'storeId' => 999, 'tenantId' => 'forged'],
        'operator_scope' => $operator, 'data_scope' => $data]);
    if ($cashierMemberSummaries->calls !== [[101, 23]]) throw new \RuntimeException('Member summary scope changed');
    if (\app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices::$calls !== [['authorized-tenant', 23, 101]]) throw new \RuntimeException('History must use authorized identity');
    $member = $result['data']['memberSummary'];
    if ($member['memberId'] !== 101 || $member['sourceDefaults']['purchase']['primarySourceId'] !== 41
        || $member['sourceDefaults']['recharge']['primarySourceId'] !== 42
        || $member['sourceDefaults']['repayment']['primarySourceId'] !== 43) throw new \RuntimeException('Summary lost business-kind defaults');
    echo "R57_SELECTED_MEMBER_SOURCE_PROJECTION=PASS (scope and all three kinds; read ports isolated)\n";
}
