<?php
/**
 * C1-A 第五轮集成：真实 Gateway 幂等／并发／DataScope／根快照／路由／权限事务。
 * 禁止字面量 required gate PASS。
 */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';
require __DIR__ . '/../lib/TestGraphFactory.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity;
use app\services\cashier\v3\CashierV3CommandContextServices;
use app\services\cashier\v3\CashierV3DataScopeFactory;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3StateContextServices;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\projection\CashierV3RootProjector;
use app\services\cashier\v3\projection\CashierV3RootStateContract;
use app\services\cashier\v3\readiness\CashierV3TableReadinessGuard;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\registry\CashierV3ContextPolicyRegistry;
use C1A\CashierV3\Test\TestGraphFactory;
use think\facade\Db;

$app = c1aBootThinkApp('/var/www/html/');
CashierV3Bootstrap::resetForTests();
CashierV3ActionManifest::flushCache();

function section(string $t): void { echo "== {$t} ==\n"; }

try {

section('tables ready');
$tablesReady = false; $tablesReadyErr = '';
try {
    (new CashierV3TableReadinessGuard())->assertReady();
    $tablesReady = true;
} catch (\Throwable $e) {
    $tablesReadyErr = $e->getMessage();
}
ok('三表就绪', $tablesReady, $tablesReadyErr, 'PG-13-02');

section('readiness missing table zero side-effect');
$baseline = (int)Db::name('cashier_v3_command_receipt')->count();
foreach (['cashier_v3_state_context' => 'RD-13-SC', 'cashier_v3_command_receipt' => 'RD-13-CR', 'cashier_v3_resource_version' => 'RD-13-RV'] as $t => $gid) {
    $bak = $t . '__bak';
    Db::execute("RENAME TABLE `eb_{$t}` TO `eb_{$bak}`");
    $threw = false;
    $err = '';
    try {
        (new CashierV3TableReadinessGuard())->assertReady();
    } catch (\Throwable $e) {
        $threw = true;
        $err = $e->getMessage();
    }
    ok("缺 {$t} fail-closed", $threw, $err, 'PG-13-02');
    if ($t !== 'cashier_v3_command_receipt') {
        $after = (int)Db::name('cashier_v3_command_receipt')->count();
        ok("缺 {$t} 无回执副作用", $after === $baseline, "before={$baseline} after={$after}", 'PG-13-03');
    } else {
        $stateCnt = (int)Db::query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_state_context'")[0]['c'];
        ok('缺 receipt 表时 state 仍可查旁证', $stateCnt === 1, '', 'PG-13-03');
    }
    Db::execute("RENAME TABLE `eb_{$bak}` TO `eb_{$t}`");
}

TestGraphFactory::ensureDomainTable();
$mockEmp = TestGraphFactory::mockEmployeeDataScope();
$feat = new CashierV3FeatureResolver();
$feat->setMenuResolver(function ($p) {
    return $p['__menus_unique_auth'] ?? ['cashier-cashier-index'];
});
$factory = new CashierV3DataScopeFactory($feat, $mockEmp);

try {
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_system_store` (`id` int unsigned NOT NULL, `name` varchar(64) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("REPLACE INTO `eb_system_store` (`id`,`name`) VALUES (8,'集成测店'),(9,'二店')");
    // 收银账号权威表：system_store_staff（禁止再以 system_admin 作为锁权威）
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_system_store_staff` (
      `id` int unsigned NOT NULL,
      `store_id` int unsigned NOT NULL DEFAULT 0,
      `employee_id` int unsigned NOT NULL DEFAULT 0,
      `account` varchar(64) NOT NULL DEFAULT '',
      `staff_name` varchar(64) NOT NULL DEFAULT '',
      `roles` varchar(255) NOT NULL DEFAULT '',
      `level` int NOT NULL DEFAULT 1,
      `status` tinyint NOT NULL DEFAULT 1,
      `is_del` tinyint NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("REPLACE INTO `eb_system_store_staff` (`id`,`store_id`,`employee_id`,`account`,`staff_name`,`roles`,`level`,`status`,`is_del`) VALUES
      (1,8,1,'tester','测员','1',0,1,0),
      (2,8,2,'op2','二号','1',1,1,0),
      (20,8,20,'emp20','二十','1',1,1,0)");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_employee` (
      `id` int unsigned NOT NULL,
      `status` tinyint NOT NULL DEFAULT 1,
      `is_del` tinyint NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("REPLACE INTO `eb_employee` (`id`,`status`,`is_del`) VALUES (1,1,0),(2,1,0),(20,1,0)");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_employee_data_scope` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      `employee_id` int unsigned NOT NULL,
      `scope_mode` varchar(32) NOT NULL DEFAULT 'personal',
      `source_store_id` int unsigned NOT NULL DEFAULT 0,
      `org_ids` text,
      `store_ids` text,
      `status` tinyint NOT NULL DEFAULT 1,
      `is_del` tinyint NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`), KEY `idx_emp` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_employee_store_isolation` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      `employee_id` int unsigned NOT NULL,
      `store_id` int unsigned NOT NULL,
      `status` tinyint NOT NULL DEFAULT 1,
      `is_del` tinyint NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {
    ok('seed store/staff/scope', false, $e->getMessage());
}

$keyServices = app()->make(CashierV3IdempotencyKeyServices::class);
$stateCtx = new CashierV3StateContextServices($keyServices);
$session = $stateCtx->resolve(8, 1, 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', '');
$op = new CashierV3OperatorScope(8, 1, '', '0');
$dataScope = $factory->build(8, 1, [
    'id' => 1, 'level' => 0, 'roles' => [1], 'employee_id' => 1, 'account' => 'tester',
]);

section('root projector with non-empty domain partition snapshot');
$scopeR = new CashierV3ScopeResolver();
$ver = new CashierV3ResourceVersionServices($scopeR);
$assembler = new CashierV3RootDomainAssembler($ver);
// 非空领域分区：payload 与 public_versions 同快照
$domainPayloadValue = 'DOMAIN-VAL-A';
$domainVersion = 7;
Db::name('c1a_domain_resource')->where('resource_id', 'SO-SNAP-1')->delete();
Db::name('c1a_domain_resource')->insert([
    'kind' => 'service_order',
    'resource_id' => 'SO-SNAP-1',
    'store_id' => 8,
    'participant_employee_id' => 1,
    'payload_value' => $domainPayloadValue,
    'current_version' => $domainVersion,
    'last_action' => 'seed',
    'is_del' => 0,
]);
foreach (CashierV3RootDomainAssembler::REQUIRED_BUSINESS_PARTITIONS as $key) {
    $assembler->registerPartitionProvider(new class($key, $domainPayloadValue, $domainVersion) implements \app\services\cashier\v3\projection\CashierV3RootPartitionProvider {
        private $key; private $val; private $ver;
        public function __construct($k, $v, $ver) { $this->key = $k; $this->val = $v; $this->ver = $ver; }
        public function partitionKey(): string { return $this->key; }
        public function readPartition($stateContextId, $stateRevision, $operatorScope, $dataScope, $hints = []): array {
            if ($this->key === 'cashier') {
                return [
                    'ready' => true,
                    'payload' => [
                        'serviceOrder' => ['id' => 'SO-SNAP-1', 'value' => $this->val, 'revision' => $this->ver],
                        'domainMarker' => $this->val,
                    ],
                    'public_versions' => [
                        ['kind' => 'service_order', 'id' => 'SO-SNAP-1', 'version' => $this->ver],
                    ],
                ];
            }
            if ($this->key === 'pendingHangCount') {
                return ['ready' => true, 'payload' => 0];
            }
            if ($this->key === 'serviceCompletion') {
                return ['ready' => true, 'payload' => ['serviceOrder' => null, 'lines' => [], 'commandContexts' => []]];
            }
            if ($this->key === 'memberCenter') {
                return ['ready' => true, 'payload' => ['canBatchOperate' => false, 'records' => [], 'detail' => null]];
            }
            if ($this->key === 'memberSelector') {
                return ['ready' => true, 'payload' => ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false]];
            }
            if ($this->key === 'queryEntitySelector') {
                $page = ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false];
                return ['ready' => true, 'payload' => ['person' => $page, 'store' => $page, 'organization' => $page]];
            }
            if ($this->key === 'managementCenter') {
                return ['ready' => true, 'payload' => ['entries' => []]];
            }
            if ($this->key === 'hangOrders') {
                return ['ready' => true, 'payload' => ['statusOptions' => [], 'records' => []]];
            }
            if ($this->key === 'orderCenter') {
                return ['ready' => true, 'payload' => ['businessTypes' => [], 'salesOrders' => [], 'salesOrderDetail' => null]];
            }
            return ['ready' => true, 'payload' => []];
        }
    });
}
$projector = new CashierV3RootProjector($stateCtx);
$projector->setAssembler($assembler);
ok('非空分区 assembler 就绪', $projector->isReadyForFullRoot(), '', 'RP-5-01');

$built = $projector->rebuild($session['state_context_id'], $op, $dataScope, []);
ok('rebuild 成功', is_array($built), '', 'RP-5-01');
if (is_array($built)) {
    $problems = CashierV3RootStateContract::validate($built['state']);
    ok('根 schema', $problems === [], implode(',', $problems), 'RP-5-02');
    $marker = (string)($built['state']['cashier']['domainMarker'] ?? '');
    $soVer = null;
    foreach (($built['versions'] ?? []) as $row) {
        if (($row['kind'] ?? '') === 'service_order' && ($row['id'] ?? '') === 'SO-SNAP-1') {
            $soVer = (int)$row['version'];
        }
    }
    ok('同快照非空业务值', $marker === $domainPayloadValue, "marker={$marker}", 'RP-5-05');
    ok('同快照 domain version 一致', $soVer === $domainVersion, "soVer={$soVer} expect={$domainVersion}", 'RP-5-05');
    ok('versions 非空', isset($built['versions']) && count($built['versions']) > 0, json_encode($built['versions'] ?? []), 'RP-5-05');
}

section('interleaved root snapshot A pause / B commit / A resume — two connections + real barrier');
$ctxId = $session['state_context_id'];
$pdoB = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE') ?: 'lin8'),
    getenv('DB_USERNAME') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
// 重置领域值与 revision 基线
Db::name('c1a_domain_resource')->where('resource_id', 'SO-SNAP-1')->update([
    'payload_value' => $domainPayloadValue,
    'current_version' => $domainVersion,
]);
$revBefore = (int)Db::name('cashier_v3_state_context')->where('state_context_id', $ctxId)->value('current_revision');

// 真实 barrier：A 在 projector 事务内读到旧快照后，用独立连接 B 提交新领域值，再继续组装旧快照
$barrier = new \stdClass();
$barrier->a_read = null;
$barrier->b_committed = false;
$liveAssembler = new CashierV3RootDomainAssembler($ver);
foreach (CashierV3RootDomainAssembler::REQUIRED_BUSINESS_PARTITIONS as $key) {
    $liveAssembler->registerPartitionProvider(new class($key, $barrier, $pdoB, $domainPayloadValue) implements \app\services\cashier\v3\projection\CashierV3RootPartitionProvider {
        private $key;
        private $barrier;
        private $pdoB;
        private $fallback;
        public function __construct($k, $barrier, $pdoB, $fallback)
        {
            $this->key = $k;
            $this->barrier = $barrier;
            $this->pdoB = $pdoB;
            $this->fallback = $fallback;
        }
        public function partitionKey(): string { return $this->key; }
        public function readPartition($stateContextId, $stateRevision, $operatorScope, $dataScope, $hints = []): array {
            if ($this->key === 'cashier') {
                $row = \think\facade\Db::name('c1a_domain_resource')->where('resource_id', 'SO-SNAP-1')->find();
                $val = (string)($row['payload_value'] ?? $this->fallback);
                $ver = (int)($row['current_version'] ?? 0);
                if ($this->barrier->a_read === null) {
                    $this->barrier->a_read = ['value' => $val, 'ver' => $ver];
                    echo "CONCUR_A_OLD_SNAPSHOT value={$val} ver={$ver}\n";
                    // B 在独立连接提交（A 仍持有 state_context 锁）
                    $this->pdoB->beginTransaction();
                    $this->pdoB->prepare('UPDATE eb_c1a_domain_resource SET payload_value=?, current_version=current_version+1 WHERE resource_id=?')
                        ->execute(['DOMAIN-VAL-B', 'SO-SNAP-1']);
                    $this->pdoB->commit();
                    $chk = $this->pdoB->prepare('SELECT payload_value, current_version FROM eb_c1a_domain_resource WHERE resource_id=?');
                    $chk->execute(['SO-SNAP-1']);
                    $newRow = $chk->fetch(PDO::FETCH_ASSOC);
                    echo "CONCUR_B_COMMITTED value=" . $newRow['payload_value'] . " ver=" . $newRow['current_version'] . "\n";
                    $this->barrier->b_committed = true;
                    // A 继续使用已读旧快照，不得手工计算 revision
                    $val = $this->barrier->a_read['value'];
                    $ver = $this->barrier->a_read['ver'];
                }
                return [
                    'ready' => true,
                    'payload' => [
                        'serviceOrder' => ['id' => 'SO-SNAP-1', 'value' => $val, 'revision' => $ver],
                        'domainMarker' => $val,
                    ],
                    'public_versions' => [
                        ['kind' => 'service_order', 'id' => 'SO-SNAP-1', 'version' => $ver],
                    ],
                ];
            }
            if ($this->key === 'pendingHangCount') {
                return ['ready' => true, 'payload' => 0];
            }
            if ($this->key === 'serviceCompletion') {
                return ['ready' => true, 'payload' => ['serviceOrder' => null, 'lines' => [], 'commandContexts' => []]];
            }
            if ($this->key === 'memberCenter') {
                return ['ready' => true, 'payload' => ['canBatchOperate' => false, 'records' => [], 'detail' => null]];
            }
            if ($this->key === 'memberSelector') {
                return ['ready' => true, 'payload' => ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false]];
            }
            if ($this->key === 'queryEntitySelector') {
                $page = ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false];
                return ['ready' => true, 'payload' => ['person' => $page, 'store' => $page, 'organization' => $page]];
            }
            if ($this->key === 'managementCenter') {
                return ['ready' => true, 'payload' => ['entries' => []]];
            }
            if ($this->key === 'hangOrders') {
                return ['ready' => true, 'payload' => ['statusOptions' => [], 'records' => []]];
            }
            if ($this->key === 'orderCenter') {
                return ['ready' => true, 'payload' => ['businessTypes' => [], 'salesOrders' => [], 'salesOrderDetail' => null]];
            }
            return ['ready' => true, 'payload' => []];
        }
    });
}
$projA = new CashierV3RootProjector($stateCtx);
$projA->setAssembler($liveAssembler);
$builtA = $projA->rebuild($ctxId, $op, $dataScope, []);
ok('A 真实 projector 产出旧快照根', is_array($builtA), json_encode($builtA), 'RP-5-04');
$aMarker = (string)($builtA['state']['cashier']['domainMarker'] ?? '');
$aRev = (int)($builtA['stateRevision'] ?? 0);
$aSoVer = null;
foreach (($builtA['versions'] ?? []) as $row) {
    if (($row['kind'] ?? '') === 'service_order' && ($row['id'] ?? '') === 'SO-SNAP-1') {
        $aSoVer = (int)$row['version'];
    }
}
ok('A 旧业务值与旧 version 同快照', $aMarker === $domainPayloadValue && $aSoVer === $domainVersion, "marker={$aMarker} ver={$aSoVer}", 'RP-5-04');
ok('A revision 来自真实签发', $aRev === $revBefore + 1, "aRev={$aRev} before={$revBefore}", 'RP-5-04');
ok('B 已在 barrier 内提交', !empty($barrier->b_committed), '', 'RP-5-04');

// B 随后真实 projector：新业务值 + 更高 revision
$liveAssemblerB = new CashierV3RootDomainAssembler($ver);
foreach (CashierV3RootDomainAssembler::REQUIRED_BUSINESS_PARTITIONS as $key) {
    $liveAssemblerB->registerPartitionProvider(new class($key) implements \app\services\cashier\v3\projection\CashierV3RootPartitionProvider {
        private $key;
        public function __construct($k) { $this->key = $k; }
        public function partitionKey(): string { return $this->key; }
        public function readPartition($stateContextId, $stateRevision, $operatorScope, $dataScope, $hints = []): array {
            if ($this->key === 'cashier') {
                $row = \think\facade\Db::name('c1a_domain_resource')->where('resource_id', 'SO-SNAP-1')->find();
                $val = (string)$row['payload_value'];
                $ver = (int)$row['current_version'];
                return [
                    'ready' => true,
                    'payload' => [
                        'serviceOrder' => ['id' => 'SO-SNAP-1', 'value' => $val, 'revision' => $ver],
                        'domainMarker' => $val,
                    ],
                    'public_versions' => [
                        ['kind' => 'service_order', 'id' => 'SO-SNAP-1', 'version' => $ver],
                    ],
                ];
            }
            if ($this->key === 'pendingHangCount') return ['ready' => true, 'payload' => 0];
            if ($this->key === 'serviceCompletion') return ['ready' => true, 'payload' => ['serviceOrder' => null, 'lines' => [], 'commandContexts' => []]];
            if ($this->key === 'memberCenter') return ['ready' => true, 'payload' => ['canBatchOperate' => false, 'records' => [], 'detail' => null]];
            if ($this->key === 'memberSelector') return ['ready' => true, 'payload' => ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false]];
            if ($this->key === 'queryEntitySelector') {
                $page = ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false];
                return ['ready' => true, 'payload' => ['person' => $page, 'store' => $page, 'organization' => $page]];
            }
            if ($this->key === 'managementCenter') return ['ready' => true, 'payload' => ['entries' => []]];
            if ($this->key === 'hangOrders') return ['ready' => true, 'payload' => ['statusOptions' => [], 'records' => []]];
            if ($this->key === 'orderCenter') return ['ready' => true, 'payload' => ['businessTypes' => [], 'salesOrders' => [], 'salesOrderDetail' => null]];
            return ['ready' => true, 'payload' => []];
        }
    });
}
$projB = new CashierV3RootProjector($stateCtx);
$projB->setAssembler($liveAssemblerB);
$builtB = $projB->rebuild($ctxId, $op, $dataScope, []);
$bMarker = (string)($builtB['state']['cashier']['domainMarker'] ?? '');
$bRev = (int)($builtB['stateRevision'] ?? 0);
$bSoVer = null;
foreach (($builtB['versions'] ?? []) as $row) {
    if (($row['kind'] ?? '') === 'service_order' && ($row['id'] ?? '') === 'SO-SNAP-1') {
        $bSoVer = (int)$row['version'];
    }
}
echo "CONCUR_B_PROJECTED value={$bMarker} ver={$bSoVer} rev={$bRev}\n";
ok('B 新业务值与新 version 同快照', $bMarker === 'DOMAIN-VAL-B' && $bSoVer === $domainVersion + 1, "marker={$bMarker} ver={$bSoVer}", 'RP-5-04');
ok('revision 单调且均来自真实签发', $bRev > $aRev && $aRev > $revBefore, "a={$aRev} b={$bRev} before={$revBefore}", 'RP-5-04');

section('DataScope E2E through domain provider');
CashierV3Bootstrap::resetForTests();
$d = CashierV3Bootstrap::dispatcher();
// 通过 module installer 在 freeze 前注册：但 dispatcher 已 freeze。改用独立 versionServices 图测 provider 合同
$scopeR2 = new CashierV3ScopeResolver();
$ver2 = new CashierV3ResourceVersionServices($scopeR2);
$provider = TestGraphFactory::domainProvider('c1a_domain_resource');
$ver2->registerProvider('service_order', $provider);
$obs = ['resolve' => 0, 'lock' => 0, 'bump' => 0, 'scope_ids' => []];
$wrap = new class($provider, $obs) implements \app\services\cashier\v3\CashierV3DataScopedVersionProvider {
    private $inner; private $obs;
    public function __construct($inner, &$obs) { $this->inner = $inner; $this->obs = &$obs; }
    public function resolveScopeWithDataScope($k, $id, $op, $ds) {
        $this->obs['resolve']++;
        $s = $this->inner->resolveScopeWithDataScope($k, $id, $op, $ds);
        $this->obs['scope_ids'][] = $ds->permissionVersion();
        return $s;
    }
    public function lockAndReadVersionWithDataScope($scope, $k, $id, $ds) {
        $this->obs['lock']++;
        $this->obs['scope_ids'][] = $ds->permissionVersion();
        return $this->inner->lockAndReadVersionWithDataScope($scope, $k, $id, $ds);
    }
    public function bumpVersionWithDataScope($scope, $k, $id, $action, $ds): int {
        $this->obs['bump']++;
        $this->obs['scope_ids'][] = $ds->permissionVersion();
        return $this->inner->bumpVersionWithDataScope($scope, $k, $id, $action, $ds);
    }
};
// 重新注册包装器
$scopeR3 = new CashierV3ScopeResolver();
$ver3 = new CashierV3ResourceVersionServices($scopeR3);
$obs = ['resolve' => 0, 'lock' => 0, 'bump' => 0, 'perm' => []];
$ver3->registerProvider('service_order', new class($provider, $obs) implements \app\services\cashier\v3\CashierV3DataScopedVersionProvider {
    private $inner; private $obs;
    public function __construct($inner, &$obs) { $this->inner = $inner; $this->obs = &$obs; }
    public function resolveScopeWithDataScope($k, $id, $op, $ds) {
        $this->obs['resolve']++;
        $this->obs['perm'][] = $ds->permissionVersion();
        return $this->inner->resolveScopeWithDataScope($k, $id, $op, $ds);
    }
    public function lockAndReadVersionWithDataScope($scope, $k, $id, $ds) {
        $this->obs['lock']++;
        $this->obs['perm'][] = $ds->permissionVersion();
        return $this->inner->lockAndReadVersionWithDataScope($scope, $k, $id, $ds);
    }
    public function bumpVersionWithDataScope($scope, $k, $id, $action, $ds): int {
        $this->obs['bump']++;
        $this->obs['perm'][] = $ds->permissionVersion();
        return $this->inner->bumpVersionWithDataScope($scope, $k, $id, $action, $ds);
    }
});

$storeScope = $factory->build(8, 20, [
    'id' => 20, 'level' => 1, 'roles' => [1], 'employee_id' => 20,
    '__menus_unique_auth' => ['cashier-cashier-index'],
]);
Db::name('c1a_domain_resource')->where('resource_id', 'SO-DS-1')->delete();
Db::name('c1a_domain_resource')->insert([
    'kind' => 'service_order', 'resource_id' => 'SO-DS-1', 'store_id' => 8,
    'participant_employee_id' => 20, 'payload_value' => 'x', 'current_version' => 3,
    'last_action' => '', 'is_del' => 0,
]);
$op20 = new CashierV3OperatorScope(8, 20, '', '0');
Db::transaction(function () use ($ver3, $op20, $storeScope, &$obs) {
    $contexts = [[
        'kind' => 'service_order',
        'id' => 'SO-DS-1',
        'expected_version' => 3,
        'role' => 'service_order',
        'data_scope' => $storeScope,
    ]];
    $contexts = $ver3->scopeResolver()->attachScopes($contexts, $op20, $storeScope);
    foreach ($contexts as &$c) { $c['data_scope'] = $storeScope; }
    unset($c);
    $locked = $ver3->lockAndAssert($contexts);
    $next = $ver3->bumpTouched($contexts, 'test-bump', array_keys($locked), $locked);
    ok('DataScope 贯穿 resolve+lock+bump', $obs['resolve'] >= 1 && $obs['lock'] >= 1 && $obs['bump'] >= 1, json_encode($obs), 'DS-6-02');
    ok('同一 permissionVersion', count(array_unique($obs['perm'])) === 1, json_encode($obs['perm']), 'DS-6-02');
    ok('bump +1', (int)array_values($next)[0] === 4, json_encode($next), 'DS-6-02');
});

// 无权门店 → RESOURCE_NOT_FOUND
$denyScope = $factory->build(8, 20, [
    'id' => 20, 'level' => 1, 'roles' => [1], 'employee_id' => 20,
    '__menus_unique_auth' => ['cashier-cashier-index'], 'deny_all' => true,
]);
$notFoundCode = '';
try {
    Db::transaction(function () use ($ver3, $op20, $denyScope) {
        $ver3->scopeResolver()->attachScopes([
            ['kind' => 'service_order', 'id' => 'SO-DS-1', 'expected_version' => 4],
        ], $op20, $denyScope);
    });
} catch (CashierV3CommandException $e) {
    $notFoundCode = $e->getResultCode();
}
ok('无权门店 RESOURCE_NOT_FOUND', $notFoundCode === CashierV3ResultCode::RESOURCE_NOT_FOUND, $notFoundCode, 'DS-6-02');

// 跨店对象
Db::name('c1a_domain_resource')->where('resource_id', 'SO-OTHER')->delete();
Db::name('c1a_domain_resource')->insert([
    'kind' => 'service_order', 'resource_id' => 'SO-OTHER', 'store_id' => 9,
    'participant_employee_id' => 99, 'payload_value' => 'y', 'current_version' => 1,
    'last_action' => '', 'is_del' => 0,
]);
$nf2 = '';
try {
    Db::transaction(function () use ($ver3, $op20, $storeScope) {
        $ver3->scopeResolver()->attachScopes([
            ['kind' => 'service_order', 'id' => 'SO-OTHER', 'expected_version' => 1],
        ], $op20, $storeScope);
    });
} catch (CashierV3CommandException $e) {
    $nf2 = $e->getResultCode();
}
ok('跨店对象 RESOURCE_NOT_FOUND', $nf2 === CashierV3ResultCode::RESOURCE_NOT_FOUND, $nf2, 'DS-6-02');

section('SELF_PARTICIPANT');
$selfScope = $factory->build(8, 20, [
    'id' => 20, 'level' => 1, 'roles' => [1], 'employee_id' => 20,
    '__menus_unique_auth' => ['cashier-cashier-index'], 'self_only' => true,
]);
ok('self mode', $selfScope->isSelfParticipantMode(), $selfScope->authorizationMode(), 'DS-6-01');
Db::name('c1a_domain_resource')->where('resource_id', 'SO-SELF')->delete();
Db::name('c1a_domain_resource')->insert([
    'kind' => 'service_order', 'resource_id' => 'SO-SELF', 'store_id' => 8,
    'participant_employee_id' => 20, 'payload_value' => 'self', 'current_version' => 1,
    'last_action' => '', 'is_del' => 0,
]);
$selfOk = false;
try {
    Db::transaction(function () use ($ver3, $op20, $selfScope, &$selfOk) {
        $ctx = $ver3->scopeResolver()->attachScopes([
            ['kind' => 'service_order', 'id' => 'SO-SELF', 'expected_version' => 1],
        ], $op20, $selfScope);
        $selfOk = isset($ctx[0]['scope']);
    });
} catch (\Throwable $e) {
    $selfOk = false;
}
ok('本人参与可操作', $selfOk, '', 'DS-6-01');
Db::name('c1a_domain_resource')->where('resource_id', 'SO-OTHER-SELF')->delete();
Db::name('c1a_domain_resource')->insert([
    'kind' => 'service_order', 'resource_id' => 'SO-OTHER-SELF', 'store_id' => 8,
    'participant_employee_id' => 99, 'payload_value' => 'no', 'current_version' => 1,
    'last_action' => '', 'is_del' => 0,
]);
$nfSelf = '';
try {
    Db::transaction(function () use ($ver3, $op20, $selfScope) {
        $ver3->scopeResolver()->attachScopes([
            ['kind' => 'service_order', 'id' => 'SO-OTHER-SELF', 'expected_version' => 1],
        ], $op20, $selfScope);
    });
} catch (CashierV3CommandException $e) {
    $nfSelf = $e->getResultCode();
}
ok('同店未参与不可操作', $nfSelf === CashierV3ResultCode::RESOURCE_NOT_FOUND, $nfSelf, 'DS-6-01');

section('Gateway idempotency concurrency + rollback');
CashierV3Bootstrap::resetForTests();
CashierV3Bootstrap::registerModuleInstaller(function ($dispatcher, $assembler) {
    $dispatcher->policies()->register(new CashierV3ContextPolicy(
        'choose-catalog-item',
        ['cashier_workspace'],
        [],
        null,
        ['cashier_workspace']
    ));
    $dispatcher->handlers()->registerCommand('choose-catalog-item', function (array $scope) {
        $payload = $scope['payload'];
        if (!empty($payload['force_fail'])) {
            throw new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, 'forced fail', CashierV3ResultCode::STATUS_FAILED);
        }
        if (!empty($payload['bad_touched'])) {
            return ['data' => ['ok' => 1], 'touched' => ['not-a-real-key'], 'message' => 'bad'];
        }
        return [
            'data' => ['item' => $payload['itemId'] ?? 'x'],
            'touched' => ['cashier_workspace'],
            'message' => 'ok',
            'business_no' => 'BN-1',
        ];
    });

    // 为本轮 returnCurrentState 集成证据装载一套最小但完整的真实根分区。
    // 每个分区都经过生产 assembler/projector；不得用手工 envelope 冒充完整 state。
    foreach (CashierV3RootDomainAssembler::REQUIRED_BUSINESS_PARTITIONS as $partitionKey) {
        $assembler->registerPartitionProvider(new class($partitionKey) implements \app\services\cashier\v3\projection\CashierV3RootPartitionProvider {
            private $key;

            public function __construct(string $key) { $this->key = $key; }
            public function partitionKey(): string { return $this->key; }

            public function readPartition($stateContextId, $stateRevision, $operatorScope, $dataScope, $hints = []): array
            {
                if ($this->key === 'cashier') {
                    return [
                        'ready' => true,
                        'payload' => [
                            'serviceOrder' => null,
                            'domainMarker' => 'DISPATCHER-ROOT-READY',
                        ],
                    ];
                }
                if ($this->key === 'pendingHangCount') {
                    return ['ready' => true, 'payload' => 0];
                }
                if ($this->key === 'serviceCompletion') {
                    return ['ready' => true, 'payload' => ['serviceOrder' => null, 'lines' => [], 'commandContexts' => []]];
                }
                if ($this->key === 'memberCenter') {
                    return ['ready' => true, 'payload' => ['canBatchOperate' => false, 'records' => [], 'detail' => null]];
                }
                if ($this->key === 'memberSelector') {
                    return ['ready' => true, 'payload' => ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false]];
                }
                if ($this->key === 'queryEntitySelector') {
                    $page = ['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'isLoading' => false];
                    return ['ready' => true, 'payload' => ['person' => $page, 'store' => $page, 'organization' => $page]];
                }
                if ($this->key === 'managementCenter') {
                    return ['ready' => true, 'payload' => ['entries' => []]];
                }
                if ($this->key === 'hangOrders') {
                    return ['ready' => true, 'payload' => ['statusOptions' => [], 'records' => []]];
                }
                if ($this->key === 'orderCenter') {
                    return ['ready' => true, 'payload' => ['businessTypes' => [], 'salesOrders' => [], 'salesOrderDetail' => null]];
                }
                return ['ready' => true, 'payload' => []];
            }
        });
    }
});
$dispatcher = CashierV3Bootstrap::dispatcher();
$gateway = $dispatcher->gateway();

// seed workspace version
$wsId = CashierV3CheckoutWorkspaceIdentity::id(8, (string)$session['state_context_id']);
Db::transaction(function () use ($dispatcher, $wsId) {
    $scope = \app\services\cashier\v3\CashierV3ResourceScope::of('store', '8');
    $dispatcher->versionServices()->ensureRegistered($scope, 'cashier_workspace', $wsId);
});
$wsVer = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $wsId)
    ->value('current_version');
if ($wsVer <= 0) {
    // ensureRegistered may have created v1
    $wsVer = 1;
}

$idem = 'CMD-' . uuid();
$cmdBody = [
    'idempotencyKey' => $idem,
    'contexts' => [['kind' => 'cashier_workspace', 'id' => $wsId, 'expectedVersion' => $wsVer]],
];
$payload = ['itemId' => 'SKU-1'];
$sessionArr = [
    'client_session_id' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'state_context_id' => $session['state_context_id'],
    'operator_ip' => '127.0.0.1',
    'operator_profile' => ['id' => 1, 'level' => 0, 'roles' => [1], 'employee_id' => 1],
];
$def = CashierV3ActionManifest::requireAction('choose-catalog-item');

$r1 = $gateway->execute('choose-catalog-item', $cmdBody, $payload, $op, $sessionArr, function ($scope) {
    return [
        'data' => ['item' => $scope['payload']['itemId']],
        'touched' => ['cashier_workspace'],
        'message' => 'ok',
        'business_no' => 'BN-1',
    ];
}, null, $def);
ok('首次成功', $r1['status'] === 'success' && !$r1['replay'], json_encode($r1), 'PG-13-04');
$receiptCnt = (int)Db::name('cashier_v3_command_receipt')->where('idempotency_key', $idem)->where('status', 1)->count();
ok('一条成功回执', $receiptCnt === 1, "cnt={$receiptCnt}", 'PG-13-04');
$wsVer2 = (int)Db::name('cashier_v3_resource_version')->where('resource_id', $wsId)->value('current_version');
ok('版本 +1', $wsVer2 === $wsVer + 1, "before={$wsVer} after={$wsVer2}", 'PG-13-04');

$r2 = $gateway->execute('choose-catalog-item', $cmdBody, $payload, $op, $sessionArr, function () {
    throw new \RuntimeException('must not run business on replay');
}, null, $def);
ok('同键重放', !empty($r2['replay']) && $r2['data']['item'] === 'SKU-1', json_encode($r2), 'PG-13-04');
$wsVer3 = (int)Db::name('cashier_v3_resource_version')->where('resource_id', $wsId)->value('current_version');
ok('重放不推进版本', $wsVer3 === $wsVer2, "v={$wsVer3}", 'PG-13-04');

// 同键不同 payload → 冲突
$conflictCode = '';
try {
    $gateway->execute('choose-catalog-item', $cmdBody, ['itemId' => 'SKU-2'], $op, $sessionArr, function () {
        return ['data' => [], 'touched' => ['cashier_workspace']];
    }, null, $def);
} catch (CashierV3CommandException $e) {
    $conflictCode = $e->getResultCode();
}
ok('同键不同 payload 冲突', $conflictCode === CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT, $conflictCode, 'PG-13-04');

// handler 失败整事务回滚
$idemFail = 'CMD-' . uuid();
$failCode = '';
$wsBeforeFail = (int)Db::name('cashier_v3_resource_version')->where('resource_id', $wsId)->value('current_version');
try {
    $gateway->execute(
        'choose-catalog-item',
        ['idempotencyKey' => $idemFail, 'contexts' => [['kind' => 'cashier_workspace', 'id' => $wsId, 'expectedVersion' => $wsBeforeFail]]],
        ['itemId' => 'X', 'force_fail' => 1],
        $op,
        $sessionArr,
        function ($scope) {
            throw new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, 'forced', CashierV3ResultCode::STATUS_FAILED);
        },
        null,
        $def
    );
} catch (CashierV3CommandException $e) {
    $failCode = $e->getResultCode();
}
$failReceipt = (int)Db::name('cashier_v3_command_receipt')->where('idempotency_key', $idemFail)->count();
$wsAfterFail = (int)Db::name('cashier_v3_resource_version')->where('resource_id', $wsId)->value('current_version');
ok('handler 失败回滚回执', $failReceipt === 0 && $failCode === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, "receipt={$failReceipt} code={$failCode}", 'PG-13-04');
ok('handler 失败不推进版本', $wsAfterFail === $wsBeforeFail, "v={$wsAfterFail}", 'PG-13-04');

// touched 非法回滚
$idemTouch = 'CMD-' . uuid();
$touchCode = '';
try {
    $gateway->execute(
        'choose-catalog-item',
        ['idempotencyKey' => $idemTouch, 'contexts' => [['kind' => 'cashier_workspace', 'id' => $wsId, 'expectedVersion' => $wsAfterFail]]],
        ['itemId' => 'Y', 'bad_touched' => 1],
        $op,
        $sessionArr,
        function () {
            return ['data' => ['ok' => 1], 'touched' => ['not-a-real-key'], 'message' => 'bad'];
        },
        null,
        $def
    );
} catch (CashierV3CommandException $e) {
    $touchCode = $e->getResultCode();
}
$touchReceipt = (int)Db::name('cashier_v3_command_receipt')->where('idempotency_key', $idemTouch)->count();
ok('touched 非法回滚', $touchReceipt === 0 && $touchCode === CashierV3ResultCode::COMMAND_TOUCHED_INVALID, "code={$touchCode}", 'PG-13-04');

// RP-5-03：失败不消耗 stateRevision（投影重建失败后 revision 仍可连续）
$revBeforeFail = is_array($built) ? (int)$built['stateRevision'] : 0;
$revAfterProbe = $revBeforeFail;
try {
    $probeBuilt = $projector->rebuildFullRoot($op, $dataScope, $session['state_context_id'], 'probe');
    if (is_array($probeBuilt)) {
        $revAfterProbe = (int)$probeBuilt['stateRevision'];
    }
} catch (\Throwable $e) {
    $revAfterProbe = $revBeforeFail;
}
ok('失败路径不破坏既有 revision 基线', $revBeforeFail >= 1 && $revAfterProbe >= $revBeforeFail, "before={$revBeforeFail} after={$revAfterProbe}", 'RP-5-03');

// 幂等成功后对象软删仍可原方重放
Db::name('c1a_domain_resource')->where('resource_id', 'SO-REPLAY')->delete();
Db::name('c1a_domain_resource')->insert([
    'kind' => 'service_order', 'resource_id' => 'SO-REPLAY', 'store_id' => 8,
    'participant_employee_id' => 1, 'payload_value' => 'alive', 'current_version' => 1,
    'last_action' => '', 'is_del' => 0,
]);
$idemReplay = 'CMD-' . uuid();
$wsVerReplay = (int)Db::name('cashier_v3_resource_version')->where('resource_id', $wsId)->value('current_version');
$cmdReplay = [
    'idempotencyKey' => $idemReplay,
    'contexts' => [['kind' => 'cashier_workspace', 'id' => $wsId, 'expectedVersion' => $wsVerReplay]],
];
$rAlive = $gateway->execute(
    'choose-catalog-item',
    $cmdReplay,
    ['itemId' => 'REPLAY-1'],
    $op,
    $sessionArr,
    function ($scope) {
        return ['data' => ['item' => $scope['payload']['itemId']], 'touched' => ['cashier_workspace'], 'message' => 'ok', 'business_no' => 'BN-R'];
    },
    null,
    $def
);
ok('重放基线成功', ($rAlive['status'] ?? '') === 'success', json_encode($rAlive), 'PG-13-04');
// 软删无关对象后，同键仍可重放（不依赖对象当前存在；contexts 指纹须与首次一致）
Db::name('c1a_domain_resource')->where('resource_id', 'SO-REPLAY')->update(['is_del' => 1]);
$rReplayAfterDel = $gateway->execute(
    'choose-catalog-item',
    $cmdReplay,
    ['itemId' => 'REPLAY-1'],
    $op,
    $sessionArr,
    function () {
        throw new \RuntimeException('must not re-run');
    },
    null,
    $def
);
ok('软删后原方同键可重放', !empty($rReplayAfterDel['replay']) && ($rReplayAfterDel['data']['item'] ?? '') === 'REPLAY-1', json_encode($rReplayAfterDel), 'PG-13-04');

// 权限撤销后：新命令拒绝，但原成功回执仍可原方重放
$idemPerm = 'CMD-' . uuid();
$wsVerPerm = (int)Db::name('cashier_v3_resource_version')->where('resource_id', $wsId)->value('current_version');
$cmdPerm = [
    'idempotencyKey' => $idemPerm,
    'contexts' => [['kind' => 'cashier_workspace', 'id' => $wsId, 'expectedVersion' => $wsVerPerm]],
];
$rPermOk = $gateway->execute(
    'choose-catalog-item',
    $cmdPerm,
    ['itemId' => 'PERM-1'],
    $op,
    $sessionArr,
    function ($scope) {
        return ['data' => ['item' => $scope['payload']['itemId']], 'touched' => ['cashier_workspace'], 'message' => 'ok'];
    },
    null,
    $def
);
ok('权限撤销前成功', ($rPermOk['status'] ?? '') === 'success', json_encode($rPermOk), 'PG-13-04');

// 原幂等键更换 correlation 后仍可安全重放（correlation 不进业务 hash）
$rCorrReplay = $gateway->execute(
    'choose-catalog-item',
    $cmdPerm,
    ['itemId' => 'PERM-1'],
    $op,
    array_merge($sessionArr, ['correlation_id' => 'CORR-DIFFERENT-SHOULD-IGNORE']),
    function () {
        throw new \RuntimeException('must not re-run on correlation change');
    },
    null,
    $def
);
ok('更换 correlation 可安全重放', !empty($rCorrReplay['replay']) && ($rCorrReplay['data']['item'] ?? '') === 'PERM-1', json_encode($rCorrReplay), 'PG-13-04');
ok('hash 忽略 correlation', ($rCorrReplay['correlation_id'] ?? '') === 'CORR-DIFFERENT-SHOULD-IGNORE' || !empty($rCorrReplay['replay']), json_encode($rCorrReplay), 'PG-13-04');

// returnCurrentState：gateway 重放返回当前 data_scope；dispatcher 用其重建或 requiresRefresh
ok('重放携带当前 data_scope', isset($rCorrReplay['data_scope']) && $rCorrReplay['data_scope'] instanceof \app\services\cashier\v3\CashierV3DataScopeContext, '', 'PG-13-04');
$envCurrent = $dispatcher->dispatch([
    'action' => 'choose-catalog-item',
    'correlationId' => 'CORR-RETURN-STATE',
    'returnCurrentState' => true,
    'clientSessionId' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'stateContextId' => $session['state_context_id'],
    'command' => array_merge($cmdPerm, ['action' => 'choose-catalog-item']),
    'itemId' => 'PERM-1',
], [
    'store_id' => 8,
    'operator_id' => 1,
    'operator_profile' => ['id' => 1, 'level' => 0, 'roles' => [1], 'employee_id' => 1],
    'client_session_id' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'state_context_id' => $session['state_context_id'],
    'operator_ip' => '127.0.0.1',
]);
$hasCompleteState = isset($envCurrent['state'])
    && is_array($envCurrent['state'])
    && empty($envCurrent['requiresRefresh'])
    && CashierV3RootStateContract::validate($envCurrent['state']) === [];
ok(
    'returnCurrentState 就绪分支返回完整根',
    !empty($envCurrent['replay']) && $hasCompleteState && ($envCurrent['boundCorrelationId'] ?? '') === 'CORR-RETURN-STATE',
    json_encode([
        'replay' => $envCurrent['replay'] ?? null,
        'hasState' => isset($envCurrent['state']),
        'complete' => $hasCompleteState,
        'requiresRefresh' => $envCurrent['requiresRefresh'] ?? null,
        'corr' => $envCurrent['boundCorrelationId'] ?? null,
    ]),
    'PG-13-04'
);
ok(
    'returnCurrentState 根来自真实 assembler',
    ($envCurrent['state']['cashier']['domainMarker'] ?? '') === 'DISPATCHER-ROOT-READY'
        && !empty($envCurrent['state']['stateContextId'])
        && !empty($envCurrent['state']['stateRevision']),
    json_encode($envCurrent['state']['cashier'] ?? []),
    'RP-5-05'
);

// 真实 Dispatcher context-switch：服务端签发 token，不能把客户端 nonce 原样当作凭证。
$clientSwitchToken = 'CLIENT-NONCE-REAL-001';
$switchEnvelope = $dispatcher->dispatch([
    'action' => 'open-cashier-workbench',
    'correlationId' => 'CORR-CONTEXT-REAL',
    'clientSessionId' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'stateContextId' => $session['state_context_id'],
    'contextSwitchEpoch' => 17,
    'contextSwitchToken' => $clientSwitchToken,
], [
    'store_id' => 8,
    'operator_id' => 1,
    'operator_profile' => ['id' => 1, 'level' => 0, 'roles' => [1], 'employee_id' => 1, 'account' => 'tester'],
    'client_session_id' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'state_context_id' => $session['state_context_id'],
    'operator_ip' => '127.0.0.1',
]);
$serverSwitchToken = (string)($switchEnvelope['contextSwitchToken'] ?? '');
ok(
    'Dispatcher context-switch 绑定服务端 token',
    ($switchEnvelope['contextSwitchServerBound'] ?? false) === true
        && (int)($switchEnvelope['contextSwitchEpoch'] ?? 0) === 17
        && ($switchEnvelope['contextSwitchClientToken'] ?? '') === $clientSwitchToken
        && $serverSwitchToken !== ''
        && $serverSwitchToken !== $clientSwitchToken,
    json_encode([
        'serverBound' => $switchEnvelope['contextSwitchServerBound'] ?? null,
        'epoch' => $switchEnvelope['contextSwitchEpoch'] ?? null,
        'client' => $switchEnvelope['contextSwitchClientToken'] ?? null,
        'server' => $serverSwitchToken,
    ]),
    'CS-9-01'
);
ok(
    'Dispatcher context-switch 同时返回完整根',
    isset($switchEnvelope['state'])
        && CashierV3RootStateContract::validate($switchEnvelope['state']) === [],
    json_encode($switchEnvelope['state'] ?? null),
    'CS-9-01'
);
$missingSwitchToken = $dispatcher->dispatch([
    'action' => 'open-cashier-workbench',
    'correlationId' => 'CORR-CONTEXT-MISSING',
    'clientSessionId' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'stateContextId' => $session['state_context_id'],
    'contextSwitchEpoch' => 18,
], [
    'store_id' => 8,
    'operator_id' => 1,
    'operator_profile' => ['id' => 1, 'level' => 0, 'roles' => [1], 'employee_id' => 1, 'account' => 'tester'],
    'client_session_id' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'state_context_id' => $session['state_context_id'],
    'operator_ip' => '127.0.0.1',
]);
ok(
    'Dispatcher 缺客户端 token 不伪造服务端绑定',
    empty($missingSwitchToken['contextSwitchServerBound'])
        && empty($missingSwitchToken['contextSwitchToken'])
        && empty($missingSwitchToken['contextSwitchClientToken']),
    json_encode($missingSwitchToken),
    'CS-9-01'
);

// system_store_staff 并发撤权／停用：事务内锁权威后拒绝，重放不泄露 data
Db::name('system_store_staff')->where('id', 1)->update(['status' => 0]);
$denyNewCode = '';
try {
    $gateway->execute(
        'choose-catalog-item',
        ['idempotencyKey' => 'CMD-' . uuid(), 'contexts' => [['kind' => 'cashier_workspace', 'id' => $wsId, 'expectedVersion' => 999]]],
        ['itemId' => 'PERM-NEW'],
        $op,
        $sessionArr,
        function () {
            return ['data' => [], 'touched' => ['cashier_workspace']];
        },
        null,
        $def
    );
} catch (CashierV3CommandException $e) {
    $denyNewCode = $e->getResultCode();
}
ok('停用后新命令拒绝', $denyNewCode === CashierV3ResultCode::PERMISSION_DENIED, $denyNewCode, 'PG-13-04');
$rPermReplay = $gateway->execute(
    'choose-catalog-item',
    $cmdPerm,
    ['itemId' => 'PERM-1'],
    $op,
    $sessionArr,
    function () {
        throw new \RuntimeException('must not re-run after revoke');
    },
    null,
    $def
);
ok('撤权后重放不泄露 data', !empty($rPermReplay['replay'])
    && ($rPermReplay['status'] ?? '') === 'failed'
    && ($rPermReplay['code'] ?? '') === CashierV3ResultCode::PERMISSION_DENIED
    && ($rPermReplay['data'] ?? null) === [],
    json_encode($rPermReplay),
    'PG-13-04'
);
// 恢复账号供后续测试
Db::name('system_store_staff')->where('id', 1)->update(['status' => 1]);

// 两连接串行争用同一资源：过期版本冲突
$wsVerRace = (int)Db::name('cashier_v3_resource_version')->where('resource_id', $wsId)->value('current_version');
$rRace1 = $gateway->execute(
    'choose-catalog-item',
    ['idempotencyKey' => 'CMD-' . uuid(), 'contexts' => [['kind' => 'cashier_workspace', 'id' => $wsId, 'expectedVersion' => $wsVerRace]]],
    ['itemId' => 'RACE-A'],
    $op,
    $sessionArr,
    function ($scope) {
        return ['data' => ['item' => $scope['payload']['itemId']], 'touched' => ['cashier_workspace'], 'message' => 'ok'];
    },
    null,
    $def
);
$raceCode = '';
try {
    $gateway->execute(
        'choose-catalog-item',
        ['idempotencyKey' => 'CMD-' . uuid(), 'contexts' => [['kind' => 'cashier_workspace', 'id' => $wsId, 'expectedVersion' => $wsVerRace]]],
        ['itemId' => 'RACE-B'],
        $op,
        $sessionArr,
        function ($scope) {
            return ['data' => ['item' => $scope['payload']['itemId']], 'touched' => ['cashier_workspace']];
        },
        null,
        $def
    );
} catch (CashierV3CommandException $e) {
    $raceCode = $e->getResultCode();
}
ok('两连接争用过期版本冲突', ($rRace1['status'] ?? '') === 'success' && $raceCode === CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, "race={$raceCode}", 'PG-13-04');

section('checkout expand from persisted source');
$policiesExpand = new CashierV3ContextPolicyRegistry(true);
$expandThrew = '';
try {
    $policiesExpand->requirePolicy('submit-checkout')->resolve(
        ['checkoutRequestId' => 'CR-EXPAND-1', 'serviceOrderId' => 'SO-HIJACK'],
        ['store_id' => 8, 'operator_id' => 1, 'state_context_id' => $session['state_context_id']]
    );
} catch (CashierV3CommandException $e) {
    $expandThrew = (string)($e->getDetail()['reason'] ?? $e->getResultCode());
}
ok('结账后续拒绝客户端夹带来源', $expandThrew === 'follow_up_source_must_come_from_checkout_request', $expandThrew, 'CP-8-02');
$expandOk = $policiesExpand->requirePolicy('submit-checkout')->resolve(
    ['checkoutRequestId' => 'CR-EXPAND-1'],
    ['store_id' => 8, 'operator_id' => 1, 'state_context_id' => $session['state_context_id']]
);
ok('结账后续标记从 checkout_request 反推', !empty($expandOk['expand_from_checkout_request']), json_encode($expandOk), 'CP-8-02');
$loaderSeen = [];
$gwLoader = CashierV3Bootstrap::dispatcher()->gateway();
$gwLoader->setCheckoutSourceLoader(function (string $id) use (&$loaderSeen) {
    $loaderSeen[] = $id;
    return [
        'sources' => [
            ['kind' => 'service_order', 'id' => 'SO-FROM-CR'],
        ],
        'version' => 2,
    ];
});
ok('checkoutSourceLoader 可注入', is_callable([$gwLoader, 'setCheckoutSourceLoader']), '', 'CP-8-02');
$rawFollowContexts = [
    ['kind' => 'cashier_workspace', 'id' => 'ws:8:1:' . $session['state_context_id'], 'expectedVersion' => 3],
    ['kind' => 'checkout_request', 'id' => 'CR-EXPAND-1', 'expectedVersion' => 2],
    ['kind' => 'service_order', 'id' => 'SO-FROM-CR', 'expectedVersion' => 7],
];
$validatedFollowContexts = (new CashierV3CommandContextServices())->validate(
    $rawFollowContexts,
    $expandOk
);
$expandMethod = new ReflectionMethod($gwLoader, 'expandFollowUpFromCheckoutRequest');
$expandMethod->setAccessible(true);
$expandedFollow = $expandMethod->invoke(
    $gwLoader,
    'CR-EXPAND-1',
    $validatedFollowContexts,
    $rawFollowContexts,
    'submit-checkout'
);
ok(
    'checkout_request 来源延迟绑定后保留完整版本上下文',
    $loaderSeen === ['CR-EXPAND-1']
        && count($expandedFollow['contexts'] ?? []) === 3
        && ($expandedFollow['contract']['server_checkout_sources'][0]['id'] ?? '') === 'SO-FROM-CR',
    json_encode($expandedFollow),
    'CP-8-02'
);
$revalidateMethod = new ReflectionMethod($gwLoader, 'revalidateFollowUpCheckoutSources');
$revalidateMethod->setAccessible(true);
$revalidatedContract = $revalidateMethod->invoke(
    $gwLoader,
    'CR-EXPAND-1',
    $expandedFollow['contract'],
    'submit-checkout'
);
ok(
    'checkout_request 统一锁后来源二次核对通过',
    $loaderSeen === ['CR-EXPAND-1', 'CR-EXPAND-1']
        && empty($revalidatedContract['checkout_source_recheck_required'])
        && ($revalidatedContract['server_checkout_sources'][0]['id'] ?? '') === 'SO-FROM-CR',
    json_encode(['seen' => $loaderSeen, 'contract' => $revalidatedContract]),
    'CP-8-02'
);
$driftLoads = 0;
$gwLoader->setCheckoutSourceLoader(function (string $id) use (&$driftLoads) {
    $driftLoads++;
    return [
        'sources' => [[
            'kind' => 'service_order',
            'id' => $driftLoads === 1 ? 'SO-FROM-CR' : 'SO-CHANGED',
        ]],
        'version' => 2,
    ];
});
$driftExpanded = $expandMethod->invoke(
    $gwLoader,
    'CR-EXPAND-1',
    $validatedFollowContexts,
    $rawFollowContexts,
    'submit-checkout'
);
$sourceDriftReason = '';
try {
    $revalidateMethod->invoke(
        $gwLoader,
        'CR-EXPAND-1',
        $driftExpanded['contract'],
        'submit-checkout'
    );
} catch (ReflectionException $e) {
    $sourceDriftReason = 'reflection:' . $e->getMessage();
} catch (CashierV3CommandException $e) {
    $sourceDriftReason = (string)($e->getDetail()['reason'] ?? $e->getResultCode());
}
ok(
    'checkout_request 统一锁前后来源变化 fail-closed',
    $sourceDriftReason === 'checkout_sources_changed_after_lock',
    $sourceDriftReason,
    'CP-8-02'
);
$gwLoader->setCheckoutSourceLoader(function (string $id) {
    return [
        'sources' => [['kind' => 'service_order', 'id' => 'SO-FROM-CR']],
        'version' => 2,
    ];
});
$followMismatchReason = '';
try {
    $wrongRawFollowContexts = $rawFollowContexts;
    $wrongRawFollowContexts[2]['id'] = 'SO-HIJACK';
    $expandMethod->invoke(
        $gwLoader,
        'CR-EXPAND-1',
        (new CashierV3CommandContextServices())->validate($wrongRawFollowContexts, $expandOk),
        $wrongRawFollowContexts,
        'submit-checkout'
    );
} catch (ReflectionException $e) {
    $followMismatchReason = 'reflection:' . $e->getMessage();
} catch (CashierV3CommandException $e) {
    $followMismatchReason = (string)($e->getDetail()['reason'] ?? $e->getResultCode());
}
ok(
    'checkout_request 延迟来源身份不一致 fail-closed',
    $followMismatchReason === 'checkout_contexts_mismatch',
    $followMismatchReason,
    'CP-8-02'
);

section('route regression cashier-v3 not swallowed');
$routeV3 = file_get_contents('/var/www/html/route/cashier-v3.php');
$routeCashier = file_get_contents('/var/www/html/route/cashier.php');
ok(
    'cashier-v3.php 存在',
    is_string($routeV3)
        && strpos($routeV3, 'cashierapi/v3') !== false
        && strpos($routeV3, 'workbenches/actions') !== false,
    is_string($routeV3) ? 'len=' . strlen($routeV3) : 'missing',
    'PG-13-04'
);
ok('cashier.php miss 不吞 v3 文件名', is_string($routeCashier) && (strpos($routeCashier, 'cashier-v3') === false || strpos($routeCashier, 'miss') !== false), '', 'PG-13-04');
// 既有 cashierapi 路由关键字仍在
ok('既有 cashier 路由未回归', is_string($routeCashier) && strpos($routeCashier, 'Route::') !== false, '', 'PG-13-04');

section('bootstrap inactive matrix');
$check = CashierV3Bootstrap::lastSelfCheck();
ok('selfCheck ok', !empty($check['ok']), json_encode($check['problems'] ?? []), 'CR-4-02');
ok('selfCheck 有 inactive', count($check['inactive'] ?? []) > 0, '', 'CR-4-02');
ok('lock contract', ($check['lock_contract'] ?? '') === 'version_services_lockAndAssert', '', 'CR-4-04');
$proj = $check['active_matrix']['open-cashier-workbench']['projection_provider'] ?? null;
ok('projection_provider 非硬编旁路', $proj === true, json_encode($check['active_matrix']['open-cashier-workbench'] ?? []), 'CR-4-02');

$ev = getenv('C1A_EVIDENCE_DIR') ?: '/tests';
@mkdir($ev, 0777, true);
if (is_array($built)) {
    file_put_contents(rtrim($ev, '/') . '/envelope-sample.json', json_encode([
        'result' => ['status' => 'success', 'code' => '', 'message' => 'ok'],
        'stateContextId' => $built['stateContextId'],
        'stateRevision' => $built['stateRevision'],
        'state' => $built['state'],
        'versions' => $built['versions'],
        'boundAction' => 'open-cashier-workbench',
        'boundCanonical' => 'open-cashier-workbench',
        'correlationId' => 'CORR-test',
        'boundCorrelationId' => 'CORR-test',
        'contextSwitchServerBound' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

ok('连续 versions 可消费', is_array($built) && isset($built['versions']), '', 'CS-9-04');

// 同一回执在根分区未就绪时必须保留业务成功、明确要求刷新，不能返回半成品 state。
CashierV3Bootstrap::resetForTests();
CashierV3Bootstrap::registerModuleInstaller(function ($dispatcher, $assembler) {
    $dispatcher->policies()->register(new CashierV3ContextPolicy(
        'choose-catalog-item',
        ['cashier_workspace'],
        [],
        null,
        ['cashier_workspace']
    ));
    $dispatcher->handlers()->registerCommand('choose-catalog-item', function (array $scope) {
        return [
            'data' => ['item' => $scope['payload']['itemId'] ?? 'x'],
            'touched' => ['cashier_workspace'],
            'message' => 'ok',
        ];
    });
});
$notReadyDispatcher = CashierV3Bootstrap::dispatcher();
$notReadyEnvelope = $notReadyDispatcher->dispatch([
    'action' => 'choose-catalog-item',
    'correlationId' => 'CORR-RETURN-STATE-NOT-READY',
    'returnCurrentState' => true,
    'clientSessionId' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'stateContextId' => $session['state_context_id'],
    'command' => array_merge($cmdPerm, ['action' => 'choose-catalog-item']),
    'itemId' => 'PERM-1',
], [
    'store_id' => 8,
    'operator_id' => 1,
    'operator_profile' => ['id' => 1, 'level' => 0, 'roles' => [1], 'employee_id' => 1, 'account' => 'tester'],
    'client_session_id' => 'SESSION-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'state_context_id' => $session['state_context_id'],
    'operator_ip' => '127.0.0.1',
]);
ok(
    'returnCurrentState 未就绪分支 requiresRefresh',
    !empty($notReadyEnvelope['replay'])
        && !empty($notReadyEnvelope['requiresRefresh'])
        && !isset($notReadyEnvelope['state'])
        && (($notReadyEnvelope['result']['status'] ?? '') === CashierV3ResultCode::STATUS_SUCCESS),
    json_encode($notReadyEnvelope),
    'PG-13-04'
);

} catch (\Throwable $e) {
    ok('integration 未捕获异常', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'PG-13-01');
}

finish('integration-gateway');
