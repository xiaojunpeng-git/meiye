<?php
declare(strict_types=1);

/** Instance-bound full member projection, under a server READ ONLY transaction.
 * Returns counts and checksums only; never publishes member/staff personal data.
 * No command gateway, session, permission mutation or financial write is used.
 */
$sites = ['rh' => ['rh.cc3798.com', 'ruihao', '3306'], '008' => ['008.cc3798.com', 'mohe_008', '3307'], '010' => ['010.cc3798.com', 'mohe_010_app', '3307']];
$site = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !isset($sites[$site])) throw new RuntimeException('Explicit instance required');
[$domain, $database, $port] = $sites[$site]; $root = '/www/wwwroot/' . $domain;
require $root . '/vendor/autoload.php';
(new \think\App($root . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', get_class($error) . ': ' . $error->getMessage() . PHP_EOL); exit(1);
});
$config = config('database.connections.mysql');
if ($config['database'] !== $database || (string)$config['hostport'] !== $port || $config['prefix'] !== 'eb_'
    || !in_array($config['hostname'], ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Wrong instance');
\think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');
$pdo = \think\facade\Db::connect()->getPdo(); $pdo->beginTransaction();
try {
    // Prefer the reported member on RH. Other instances use their own bounded
    // service fixture, never an identity or data copied from another customer.
    $memberId = $site === 'rh' ? 913206 : (int)$pdo->query("SELECT u.uid FROM eb_user u JOIN eb_cashier_v3_entitlement_service_fact f ON f.member_id=u.uid AND f.tenant_id='0' AND f.service_status='completed' WHERE f.business_date BETWEEN '2026-10-01' AND '2026-10-24' AND EXISTS(SELECT 1 FROM eb_system_store_staff s WHERE s.store_id=u.belong_store_id AND s.status=1 AND s.is_del=0 AND s.employee_id>0) GROUP BY u.uid HAVING COUNT(*) BETWEEN 1 AND 100 ORDER BY u.uid LIMIT 1")->fetchColumn();
    if ($memberId <= 0) $memberId = (int)$pdo->query('SELECT u.uid FROM eb_user u WHERE EXISTS(SELECT 1 FROM eb_system_store_staff s WHERE s.store_id=u.belong_store_id AND s.status=1 AND s.is_del=0 AND s.employee_id>0) ORDER BY u.uid LIMIT 1')->fetchColumn();
    if ($memberId <= 0) throw new RuntimeException('No authorized member fixture available');
    $member = \think\facade\Db::name('user')->where('uid', $memberId)->field('uid,belong_store_id')->find();
    $staffId = (int)\think\facade\Db::name('system_store_staff')->where('store_id', (int)$member['belong_store_id'])->where('status', 1)->where('is_del', 0)->where('employee_id', '>', 0)->order('id')->value('id');
    $staff = \app\model\store\SystemStoreStaff::where('id', $staffId)->find()->toArray();
    $staff['roles'] = \app\services\cashier\v3\CashierV3PermissionSnapshotServices::normalizeRoleIds($staff['roles'] ?? []);
    $operator = (new \app\services\cashier\v3\CashierV3ScopeResolver())->operatorScope((int)$staff['store_id'], $staffId);
    $scope = app()->make(\app\services\cashier\v3\CashierV3DataScopeFactory::class)->build($operator->storeId(), $operator->operatorId(), $staff, '0', $operator->organizationId());
    $reader = new \app\services\cashier\v3\member\CashierV3MemberDetailQueryServices();
    $query = ['tab' => 'writeoff', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-24'];
    $detail = $reader->read($memberId, $operator, $scope, $query);
    $expected = (int)\think\facade\Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')->where('member_id', $memberId)->where('service_status', 'completed')->where('business_date', '>=', $query['dateFrom'])->where('business_date', '<=', $query['dateTo'])->count();
    if ((int)$detail['member']['memberId'] !== $memberId || count($detail['serviceRecords']) !== min(100, $expected)
        || (int)$detail['tabStates']['writeoff']['total'] !== $expected) throw new RuntimeException('Member service projection mismatch');
    $repeat = $reader->read($memberId, $operator, $scope, $query);
    if ($repeat['serviceRecords'] !== $detail['serviceRecords']) throw new RuntimeException('Unstable service history');
    $empty = $reader->read($memberId, $operator, $scope, array_replace($query, ['keyword' => 'R54-NO-SUCH-SERVICE-UNIQUE']));
    if ($empty['serviceRecords'] !== []) throw new RuntimeException('Empty search mismatch');
    $dependencies = ['app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php', 'app/services/cashier/v3/card/CashierV3CardReadAmountServices.php', 'app/services/cashier/v3/card/CashierV3CardComponentPurchaseAmountAuthority.php', 'app/services/cashier/v3/cashier/CashierV3EntitlementActualAmountAllocator.php', 'app/services/cashier/v3/cashier/CashierV3CardOriginDebtResolver.php'];
    $hashes = []; foreach ($dependencies as $file) $hashes[$file] = hash_file('sha256', $root . '/' . $file);
    if (!class_exists(\app\services\cashier\v3\card\CashierV3CardReadAmountServices::class)) throw new RuntimeException('Missing card read autoload');
    echo json_encode(['result' => 'PASS', 'site' => $site, 'readOnly' => true, 'fullMemberProjection' => true,
        'serviceRecords' => $expected, 'cards' => count($detail['cards']), 'reportedMemberCovered' => $site === 'rh',
        'repeatAndEmptySearch' => 'PASS', 'dependencies' => $hashes, 'businessWrites' => 0,
        'limit' => $expected === 0 ? 'No nonempty service fixture in this instance; successful empty full projection verified.' : null], JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
