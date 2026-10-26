<?php
/**
 * 测试专用对象图工厂（禁止使用生产 Bootstrap::buildDispatcherForTests）。
 * 生产路径只能走 CashierV3Bootstrap::dispatcher()。
 */
namespace C1A\CashierV3\Test;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandGatewayServices;
use app\services\cashier\v3\CashierV3DataScopeFactory;
use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3PermissionSnapshotServices;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3StateContextServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\permission\CashierV3PermissionPolicyRegistry;
use app\services\cashier\v3\permission\CashierV3SelectorGrantServices;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\projection\CashierV3RootProjector;
use app\services\cashier\v3\readiness\CashierV3TableReadinessGuard;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\registry\CashierV3ContextPolicyRegistry;
use app\services\cashier\v3\registry\CashierV3HandlerRegistry;
use app\services\cashier\v3\registry\CashierV3PermissionGuard;
use app\services\organization\EmployeeDataScopeServices;
use think\facade\Db;

final class TestGraphFactory
{
    public static function mockEmployeeDataScope(): EmployeeDataScopeServices
    {
        return new class extends EmployeeDataScopeServices {
            public function __construct() {}
            public function isSuperAdmin(array $adminInfo): bool
            {
                return !empty($adminInfo['force_data_super']);
            }
            public function resolveEffectiveStoreIds(int $employeeId, int $contextStoreId = 0, array $adminInfo = [])
            {
                if (!empty($adminInfo['deny_all'])) {
                    return [];
                }
                if (!empty($adminInfo['multi_stores'])) {
                    return array_map('intval', $adminInfo['multi_stores']);
                }
                if (!empty($adminInfo['self_only']) || $employeeId > 0 && empty($adminInfo['multi_stores']) && empty($adminInfo['force_stores'])) {
                    if (!empty($adminInfo['force_stores'])) {
                        return array_map('intval', $adminInfo['force_stores']);
                    }
                    // 空数组 = 无门店扩展 → SELF_PARTICIPANT
                    if (!empty($adminInfo['self_only'])) {
                        return [];
                    }
                }
                if ($employeeId <= 0) {
                    return [];
                }
                if (!empty($adminInfo['force_stores'])) {
                    return array_map('intval', $adminInfo['force_stores']);
                }
                return [$contextStoreId > 0 ? $contextStoreId : 8];
            }
        };
    }

    /**
     * 领域测试表 provider（仅 DataScoped 接口，无旁路）。
     */
    public static function domainProvider(string $table = 'c1a_domain_resource'): CashierV3DataScopedVersionProvider
    {
        return new class($table) implements CashierV3DataScopedVersionProvider {
            private $table;
            public function __construct(string $table) { $this->table = $table; }
            public function resolveScopeWithDataScope(
                string $kind,
                string $resourceId,
                CashierV3OperatorScope $operatorScope,
                CashierV3DataScopeContext $dataScope
            ) {
                $row = Db::name($this->table)->where('resource_id', $resourceId)->where('kind', $kind)->find();
                if (!$row || !empty($row['is_del'])) {
                    return null;
                }
                $storeId = (int)$row['store_id'];
                $participantId = (int)($row['participant_employee_id'] ?? 0);
                if ($dataScope->isSelfParticipantMode()) {
                    if ($participantId <= 0 || $participantId !== $dataScope->employeeId()) {
                        return null;
                    }
                    return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$storeId);
                }
                if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_ALL) {
                    return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$storeId);
                }
                if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_STORES) {
                    if (!$dataScope->allowsStore($storeId)) {
                        return null;
                    }
                    return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$storeId);
                }
                return null;
            }
            public function lockAndReadVersionWithDataScope(
                CashierV3ResourceScope $scope,
                string $kind,
                string $resourceId,
                CashierV3DataScopeContext $dataScope
            ) {
                $row = Db::name($this->table)
                    ->where('resource_id', $resourceId)
                    ->where('kind', $kind)
                    ->where('store_id', (int)$scope->id())
                    ->lock(true)
                    ->find();
                if (!$row || !empty($row['is_del'])) {
                    return null;
                }
                // 锁等待期归属变更：再校验 DataScope
                $re = $this->resolveScopeWithDataScope($kind, $resourceId, new CashierV3OperatorScope((int)$scope->id(), $dataScope->operatorId(), '', '0'), $dataScope);
                if ($re === null) {
                    return null;
                }
                return (int)$row['current_version'];
            }
            public function bumpVersionWithDataScope(
                CashierV3ResourceScope $scope,
                string $kind,
                string $resourceId,
                string $action,
                CashierV3DataScopeContext $dataScope
            ): int {
                $affected = Db::name($this->table)
                    ->where('resource_id', $resourceId)
                    ->where('kind', $kind)
                    ->where('store_id', (int)$scope->id())
                    ->update([
                        'current_version' => Db::raw('current_version + 1'),
                        'last_action' => mb_substr($action, 0, 64),
                    ]);
                if ((int)$affected !== 1) {
                    return 0;
                }
                $row = Db::name($this->table)
                    ->where('resource_id', $resourceId)
                    ->where('kind', $kind)
                    ->find();
                return $row ? (int)$row['current_version'] : 0;
            }
        };
    }

    public static function ensureDomainTable(): void
    {
        Db::execute("CREATE TABLE IF NOT EXISTS `eb_c1a_domain_resource` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `kind` varchar(32) NOT NULL,
          `resource_id` varchar(64) NOT NULL,
          `store_id` int unsigned NOT NULL,
          `participant_employee_id` int unsigned NOT NULL DEFAULT 0,
          `payload_value` varchar(128) NOT NULL DEFAULT '',
          `current_version` bigint unsigned NOT NULL DEFAULT 1,
          `last_action` varchar(64) NOT NULL DEFAULT '',
          `is_del` tinyint NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_kind_id` (`kind`,`resource_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        Db::execute("CREATE TABLE IF NOT EXISTS `eb_c1a_checkout_request` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `checkout_request_id` varchar(64) NOT NULL,
          `store_id` int unsigned NOT NULL,
          `sources_json` text NOT NULL,
          `current_version` bigint unsigned NOT NULL DEFAULT 1,
          `is_del` tinyint NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_cr` (`checkout_request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}
