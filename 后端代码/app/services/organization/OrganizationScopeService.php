<?php
namespace app\services\organization;

use app\dao\organization\OrganizationAdminDao;
use app\dao\organization\OrganizationAdminStoreExcludeDao;
use app\dao\organization\OrganizationDao;
use app\dao\organization\OrganizationStoreDao;
use app\services\agent\SystemRegionAgentServices;
use app\services\BaseServices;
use app\services\store\SystemRegionManageServices;
use mohe\services\CacheService;
use mohe\services\SystemConfigService;
use think\facade\Db;
use think\facade\Log;

/**
 * 组织—门店—管理员 统一范围解析（后台与手机端共用）
 */
class OrganizationScopeService extends BaseServices
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_DUAL_READ = 'dual_read';
    public const MODE_ORGANIZATION = 'organization';

    public const SOURCE_MODE_CONFIG_KEY = 'organization_source_mode';

    /** Redis：跨 Worker 共享的 mode 版本号（切换/回滚必须 bump） */
    public const SOURCE_MODE_VERSION_KEY = 'organization:source_mode_ver';

    /** Redis：运行时 mode 快照（与 DB 配置同步发布，供多 Worker 快速一致读取） */
    public const SOURCE_MODE_RUNTIME_KEY = 'organization:source_mode_runtime';

    /** 请求内 mode 缓存最大存活（秒）；即使漏 bump 也最多短暂陈旧 */
    public const REQUEST_MODE_MAX_AGE = 5;

    /** 迁移批次审计 action */
    public const MIGRATE_BATCH_ACTION = 'migrate_batch';

    /** 正式切源成功凭证 */
    public const SOURCE_CUTOVER_ACTION = 'source_cutover';

    /** 切源回滚凭证（fail-closed 优先） */
    public const SOURCE_ROLLBACK_ACTION = 'source_rollback';

    /**
     * 正式切源后：是否显式允许「无 organization_admin、仅旧区域代理」过渡授权。
     * 默认关闭；仅产品点名打开时生效。未切源时不走此开关（仍用旧代理逻辑）。
     */
    public const LEGACY_REGION_AGENT_COMPAT_KEY = 'organization_legacy_region_agent_compat';

    /** @var array<int, array<int>> */
    protected static $orgStoreCache = [];

    /** @var array<int, array<int>> */
    protected static $adminStoreCache = [];

    /**
     * 请求内 mode 缓存（必须绑定 Redis 版本；禁止跨 Worker 永久静态）
     * @var string|null
     */
    protected static $requestMode = null;

    /** @var string|null */
    protected static $requestModeVersion = null;

    /** @var int */
    protected static $requestModeCachedAt = 0;

    /** @var string|null 仅测试注入；生产必须保持 null */
    protected static $testSourceMode = null;

    /** @var bool|null 仅测试注入完整性；生产必须保持 null */
    protected static $testIntegrityPassed = null;

    /** @var bool 仅测试：强制完整性查询失败关闭 */
    protected static $testIntegrityQueryBoom = false;

    /** @var bool 仅测试：发布前直接失败（不碰 Redis mode） */
    protected static $testRedisPublishBoom = false;

    /** @var bool 仅测试：runtime SET 成功后、INCR 前失败（验证补偿） */
    protected static $testFailAfterRuntimeSet = false;

    /** @var bool 仅测试：Redis 完全成功后、写 cutover 审计前失败 */
    protected static $testFailAfterRedisBeforeAudit = false;

    /**
     * 仅测试：在指定阶段暂停（持有结构锁），供双进程并发断言。
     * 阶段：after_cutover_gate | after_runtime_set_before_fail | after_incr_before_rollback_audit
     * @var string
     */
    protected static $testHoldPhase = '';

    /** @var string */
    protected static $testHoldReadyFile = '';

    /** @var string */
    protected static $testHoldGoFile = '';

    /**
     * 切源凭证请求内缓存（绑定 source_mode_version + 最长存活，禁止跨 Worker 永久静态）
     * @var array{version:string,value:bool,cached_at:int}|null
     */
    protected static $cutoverCredCache = null;

    /** 切源凭证缓存最长存活（秒） */
    public const CUTOVER_CRED_MAX_AGE = 5;

    /** @var string 最近一次非法 mode 告警文案（便于冒烟断言） */
    protected static $lastSourceModeWarning = '';

    public function __construct(
        OrganizationDao $orgDao,
        OrganizationStoreDao $orgStoreDao,
        OrganizationAdminDao $adminDao,
        OrganizationAdminStoreExcludeDao $excludeDao
    ) {
        $this->dao = $orgDao;
        $this->orgDao = $orgDao;
        $this->orgStoreDao = $orgStoreDao;
        $this->adminDao = $adminDao;
        $this->excludeDao = $excludeDao;
    }

    /** @var OrganizationDao */
    protected $orgDao;

    /** @var OrganizationStoreDao */
    protected $orgStoreDao;

    /** @var OrganizationAdminDao */
    protected $adminDao;

    /** @var OrganizationAdminStoreExcludeDao */
    protected $excludeDao;

    /**
     * 显式读取组织数据源模式。
     * 缺失或非法值一律按 legacy；非法值写告警日志。
     *
     * 缓存策略：仅允许「请求内 + Redis 版本」缓存；禁止跨 Worker 永久静态。
     * 切换/回滚须 publishSourceMode / clearSourceModeCache（bump 共享版本）。
     */
    public function getSourceMode(): string
    {
        if (self::$testSourceMode !== null) {
            return $this->normalizeSourceMode(self::$testSourceMode, true);
        }

        $version = $this->getSourceModeVersion();
        $now = time();
        if (self::$requestMode !== null
            && self::$requestModeVersion === $version
            && ($now - self::$requestModeCachedAt) < self::REQUEST_MODE_MAX_AGE
        ) {
            return self::$requestMode;
        }

        $raw = $this->readSourceModeRaw();
        $mode = $this->normalizeSourceMode($raw, true);
        self::$requestMode = $mode;
        self::$requestModeVersion = $version;
        self::$requestModeCachedAt = $now;
        return $mode;
    }

    /**
     * 是否正式使用新组织范围。
     * 必须同时满足：mode=organization + 有效正式切源凭证(committed) + 原生完整性。
     * 直接改 DB / 只写 runtime / 已被 source_rollback 均不能绕过首次切源门禁。
     */
    public function isMigrated(): bool
    {
        try {
            if ($this->getSourceMode() !== self::MODE_ORGANIZATION) {
                return false;
            }
            if (self::$testIntegrityPassed !== null) {
                return (bool)self::$testIntegrityPassed;
            }
            if (!$this->hasValidCutoverCredential()) {
                Log::warning('organization_source_mode=organization 但缺少有效正式切源凭证，业务仍回退旧组织范围');
                return false;
            }
            /** @var OrganizationReconcileServices $reconcile */
            $reconcile = app()->make(OrganizationReconcileServices::class);
            $native = $reconcile->validateOrganizationNativeIntegrity(false);
            if (empty($native['passed'])) {
                Log::warning('organization_source_mode=organization 但原生完整性未通过，业务仍回退旧组织范围', [
                    'blockers' => $native['blockers'] ?? [],
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('isMigrated 判定异常，回退旧组织范围：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 与 isMigrated() 同义，供 source_status 接口语义更清晰。
     */
    public function isUsingOrganizationSource(): bool
    {
        return $this->isMigrated();
    }

    /**
     * 迁移对账（公开）
     */
    public function reconcileLegacyMigration(bool $forceRefresh = false, bool $requireSuccessfulBatch = false): array
    {
        if (self::$testIntegrityPassed !== null) {
            return [
                'kind' => 'reconcile',
                'passed' => (bool)self::$testIntegrityPassed,
                'blockers' => self::$testIntegrityPassed ? [] : ['测试注入：完整性未通过'],
                'warnings' => [],
                'stats' => $this->emptyCoverageStats(),
                'anomaly' => [],
            ];
        }
        if (self::$testIntegrityQueryBoom) {
            OrganizationReconcileServices::setTestQueryBoom(true);
        }
        try {
            /** @var OrganizationReconcileServices $svc */
            $svc = app()->make(OrganizationReconcileServices::class);
            return $svc->reconcileLegacyMigration($forceRefresh, $requireSuccessfulBatch);
        } finally {
            OrganizationReconcileServices::setTestQueryBoom(false);
        }
    }

    /**
     * 正式运行期原生完整性（公开）
     */
    public function validateOrganizationNativeIntegrity(bool $forceRefresh = false): array
    {
        if (self::$testIntegrityPassed !== null) {
            return [
                'kind' => 'native',
                'passed' => (bool)self::$testIntegrityPassed,
                'blockers' => self::$testIntegrityPassed ? [] : ['测试注入：完整性未通过'],
                'warnings' => [],
                'stats' => $this->emptyCoverageStats(),
                'anomaly' => [],
            ];
        }
        if (self::$testIntegrityQueryBoom) {
            OrganizationReconcileServices::setTestQueryBoom(true);
        }
        try {
            /** @var OrganizationReconcileServices $svc */
            $svc = app()->make(OrganizationReconcileServices::class);
            return $svc->validateOrganizationNativeIntegrity($forceRefresh);
        } finally {
            OrganizationReconcileServices::setTestQueryBoom(false);
        }
    }

    /**
     * 覆盖率与切源就绪状态（只读）
     */
    public function getSourceStatus(): array
    {
        $mode = $this->getSourceMode();
        if ($mode === self::MODE_ORGANIZATION) {
            $report = $this->validateOrganizationNativeIntegrity(true);
            $canCutover = false;
            $isOrgSource = $this->isUsingOrganizationSource();
            // mode=organization 但缺少正式切源凭证：必须显式 blocker/anomaly，不能只靠 is_organization_source=false
            if (!$this->hasValidCutoverCredential()) {
                $report['blockers'] = array_values(array_unique(array_merge(
                    $report['blockers'] ?? [],
                    ['缺少有效正式切源凭证，不能按新组织数据源对外生效']
                )));
                $anomalyMissing = $report['anomaly'] ?? [];
                $anomalyMissing['missing_cutover_credential'] = 1;
                $report['anomaly'] = $anomalyMissing;
            }
        } else {
            // 切源前：迁移对账 + 成功批次 hash
            $report = $this->reconcileLegacyMigration(true, true);
            $canCutover = !empty($report['passed']);
            $isOrgSource = false;
        }
        $stats = $report['stats'] ?? $this->emptyCoverageStats();
        $anomaly = $report['anomaly'] ?? [];
        $warnings = array_values(array_unique(array_merge(
            $report['warnings'] ?? [],
            self::$lastSourceModeWarning !== '' ? [self::$lastSourceModeWarning] : []
        )));
        $blockers = $report['blockers'] ?? [];

        return [
            'source_mode' => $mode,
            'is_organization_source' => $isOrgSource,
            'integrity_kind' => $report['kind'] ?? '',
            'legacy_org_count' => (int)($stats['legacy_org_count'] ?? 0),
            'mapped_org_count' => (int)($stats['mapped_org_count'] ?? 0),
            'active_store_count' => (int)($stats['active_store_count'] ?? 0),
            'mapped_store_count' => (int)($stats['mapped_store_count'] ?? 0),
            'legacy_agent_count' => (int)($stats['legacy_agent_count'] ?? 0),
            'mapped_admin_count' => (int)($stats['mapped_admin_count'] ?? 0),
            'orphan_store_count' => (int)($anomaly['orphan_store_count'] ?? $stats['orphan_store_count'] ?? 0),
            'anomaly' => $anomaly,
            'warnings' => $warnings,
            'blockers' => $blockers,
            'can_cutover' => $canCutover,
            'legacy_hash' => (string)($report['legacy_hash'] ?? $anomaly['legacy_hash'] ?? ''),
            'new_projection_hash' => (string)($report['new_projection_hash'] ?? $anomaly['new_projection_hash'] ?? ''),
            'cache_refresh_hint' => '切换/回滚须 OrganizationScopeService::publishSourceMode($mode) 或 clearSourceModeCache()：先成功清理共享配置缓存，再 Redis INCR 版本；任一步 Redis/缓存失败必须抛错，不得静默成功。其它 Worker 见新版本即失效（请求内最长 '
                . self::REQUEST_MODE_MAX_AGE . ' 秒）。DB 为持久真值；运行时快照仅在 publish 成功后与发布 mode 一致。publish/clear 与组织结构变更共用 withOrganizationStructureLock。',
            'source_mode_version' => $this->getSourceModeVersion(),
        ];
    }

    /**
     * 正式迁移前置门禁（dry_run 仅报告，不阻断预演）
     */
    public function getMigrateReadiness(bool $dryRun = false): array
    {
        $mode = $this->getSourceMode();
        $stats = $this->getSourceCoverageStats();
        $blockers = [];
        $warnings = [];

        if ($mode === self::MODE_ORGANIZATION) {
            $blockers[] = '当前已正式使用新组织数据源，禁止再次正式迁移';
        }
        if ((int)$stats['mapped_org_count'] > 0) {
            $warnings[] = '新组织表已有数据，当前处于半迁移状态';
            if (!$dryRun) {
                $blockers[] = '新组织表已有数据，当前处于半迁移状态，禁止静默覆盖；请使用 dry_run 预演对账，或完成对账后再由产品确认切源';
            }
        }
        if ((int)$stats['legacy_org_count'] <= 0) {
            $blockers[] = '旧区域架构无数据，无法迁移';
        }

        $preflight = $this->collectMigratePreflightIssues();
        foreach ($preflight['blockers'] as $msg) {
            if (!$dryRun) {
                $blockers[] = $msg;
            } else {
                $warnings[] = $msg;
            }
        }
        foreach ($preflight['warnings'] as $msg) {
            $warnings[] = $msg;
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));

        return [
            'source_mode' => $mode,
            'dry_run' => $dryRun,
            'half_migrated' => (int)$stats['mapped_org_count'] > 0 && $mode !== self::MODE_ORGANIZATION,
            'can_migrate' => empty($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'stats' => $stats,
            'source_mode_will_change' => false,
        ];
    }

    /**
     * 兼容旧调用：按当前 mode 路由到对账或原生完整性。
     * @deprecated 请显式调用 reconcileLegacyMigration / validateOrganizationNativeIntegrity
     */
    public function checkOrganizationIntegrity(bool $forceRefresh = false, bool $requireMigrateBatch = true): array
    {
        if ($this->getSourceMode() === self::MODE_ORGANIZATION) {
            return $this->validateOrganizationNativeIntegrity($forceRefresh);
        }
        return $this->reconcileLegacyMigration($forceRefresh, $requireMigrateBatch);
    }

    /**
     * 覆盖率计数
     */
    public function getSourceCoverageStats(): array
    {
        if ($this->getSourceMode() === self::MODE_ORGANIZATION) {
            $report = $this->validateOrganizationNativeIntegrity(false);
        } else {
            $report = $this->reconcileLegacyMigration(false, false);
        }
        return $report['stats'] ?? $this->emptyCoverageStats();
    }

    /**
     * 检测组织 pid 是否形成环（有限步 + visited）
     */
    public function hasOrganizationCycle(): bool
    {
        $list = $this->orgDao->getList(['is_del' => 0], 'id,pid') ?: [];
        return $this->detectCycleInOrgRows($list);
    }

    /**
     * @param array<int, array{id?:int|string,pid?:int|string}> $list
     */
    public function detectCycleInOrgRows(array $list): bool
    {
        $pidMap = [];
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $pidMap[$id] = (int)($row['pid'] ?? 0);
        }
        if (!$pidMap) {
            return false;
        }
        foreach ($pidMap as $startId => $_) {
            $seen = [];
            $cur = $startId;
            $guard = 0;
            $max = count($pidMap) + 2;
            while ($cur > 0) {
                if (isset($seen[$cur])) {
                    return true;
                }
                $seen[$cur] = true;
                $cur = (int)($pidMap[$cur] ?? 0);
                if (++$guard > $max) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 仅供测试注入 source mode；生产代码禁止调用。
     */
    public static function setTestSourceMode(?string $mode): void
    {
        self::$testSourceMode = $mode;
        self::$requestMode = null;
        self::$requestModeVersion = null;
        self::$requestModeCachedAt = 0;
        self::$lastSourceModeWarning = '';
    }

    /**
     * 仅供测试注入完整性结果；生产代码禁止调用。
     */
    public static function setTestIntegrityPassed(?bool $passed): void
    {
        self::$testIntegrityPassed = $passed;
    }

    /**
     * 仅供测试：强制完整性查询失败关闭。
     */
    public static function setTestIntegrityQueryBoom(bool $boom): void
    {
        self::$testIntegrityQueryBoom = $boom;
    }

    public static function getLastSourceModeWarning(): string
    {
        return self::$lastSourceModeWarning;
    }

    /**
     * 严格成功协议发布 source mode（整段处于组织结构锁内）。
     * - organization：改 Redis 前必须 DB=organization + reconcile(true,true) + 成功批次 hash；成功后写 source_cutover=committed。
     * - Redis：先校验版本键 → 保留旧 runtime → SET → INCR；失败必须恢复旧 runtime 并失效 Worker 缓存。
     * - 审计写入失败：恢复旧 runtime，不得留下 committed 假象。
     * - 与组织新增/移动/删除、clearSourceModeCache 共用 withOrganizationStructureLock，禁止第二套锁。
     */
    public static function publishSourceMode(string $mode): void
    {
        /** @var OrganizationManageServices $manage */
        $manage = app()->make(OrganizationManageServices::class);
        $manage->withOrganizationStructureLock(function () use ($mode) {
            self::publishSourceModeLocked($mode);
        });
    }

    /**
     * 结构锁内的发布实现（含 organization 门禁全流程与失败补偿）。
     */
    protected static function publishSourceModeLocked(string $mode): void
    {
        $normalized = self::normalizeSourceModeStatic($mode, true);
        if (self::$testRedisPublishBoom) {
            throw new \RuntimeException('organization source mode 发布失败：测试注入 Redis 失败');
        }

        $cutoverMeta = null;
        if ($normalized === self::MODE_ORGANIZATION) {
            // 锁内重新读 DB + 强制对账 + 批次/hash（关闭对账后被并发改写的窗口）
            $cutoverMeta = self::assertOrganizationCutoverAllowed();
            self::maybeTestHold('after_cutover_gate');
        }

        // 发布前读取旧状态；版本键非数字时在写 runtime 前失败
        $oldRuntime = self::readRuntimeModeRaw();
        self::assertVersionKeyNumericOrEmpty();

        try {
            $cleared = SystemConfigService::clear();
            if ($cleared === false) {
                throw new \RuntimeException('清理 SystemConfig 共享缓存失败');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('清理 SystemConfig 共享缓存失败：' . $e->getMessage(), 0, $e);
        }

        $newVersion = null;
        try {
            self::redisSetRuntimeStrict($normalized);
            if (self::$testFailAfterRuntimeSet) {
                self::maybeTestHold('after_runtime_set_before_fail');
                throw new \RuntimeException('organization source mode 发布失败：runtime SET 后模拟后续失败');
            }
            $newVersion = self::incrSourceModeVersionStrict();
            if (self::$testFailAfterRedisBeforeAudit) {
                throw new \RuntimeException('organization source mode 发布失败：Redis 成功后模拟审计前失败');
            }
        } catch (\Throwable $e) {
            self::compensateRestoreRuntime($oldRuntime);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }

        if ($normalized === self::MODE_ORGANIZATION) {
            try {
                self::writeSourceCutoverCommittedAudit($cutoverMeta ?? [], (string)$newVersion);
            } catch (\Throwable $e) {
                self::compensateRestoreRuntime($oldRuntime);
                throw new \RuntimeException('写入正式切源凭证失败，已恢复旧 runtime：' . $e->getMessage(), 0, $e);
            }
        }

        self::resetRequestModeCache();
        self::clearCache();
        self::clearIntegrityCache();
    }

    /**
     * 回滚运行时快照到 DB 真值，并写 source_rollback（fail-closed）。
     * 整段处于组织结构锁内，防止与 publish 交错导致补偿覆盖成功发布。
     */
    public static function clearSourceModeCache(): void
    {
        /** @var OrganizationManageServices $manage */
        $manage = app()->make(OrganizationManageServices::class);
        $manage->withOrganizationStructureLock(function () {
            self::clearSourceModeCacheLocked();
        });
    }

    /**
     * 回滚协议（fail-closed，避免 INCR 与 rollback 审计之间的凭证缓存竞态）：
     * 1) SET runtime=legacy（过渡安全态，禁止立刻 DELETE）
     * 2) INCR version
     * 3) 写 source_rollback
     * 4) 审计成功后再 DELETE runtime，回落 DB 真值
     * 审计前失败：恢复旧 runtime 并 bump；审计成功但最终 DELETE 失败：保留 legacy，禁止补偿回 organization。
     */
    protected static function clearSourceModeCacheLocked(): void
    {
        if (self::$testRedisPublishBoom) {
            throw new \RuntimeException('organization source mode 回滚发布失败：测试注入 Redis 失败');
        }

        $oldRuntime = self::readRuntimeModeRaw();
        self::assertVersionKeyNumericOrEmpty();

        try {
            $cleared = SystemConfigService::clear();
            if ($cleared === false) {
                throw new \RuntimeException('清理 SystemConfig 共享缓存失败');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('清理 SystemConfig 共享缓存失败：' . $e->getMessage(), 0, $e);
        }

        $newVersion = null;
        try {
            // 先严格 SET legacy，作为 fail-closed 过渡态（勿先 DELETE）
            self::redisSetRuntimeStrict(self::MODE_LEGACY);
            if (self::$testFailAfterRuntimeSet) {
                self::maybeTestHold('after_runtime_set_before_fail');
                throw new \RuntimeException('organization source mode 回滚失败：SET legacy 后模拟后续失败');
            }
            $newVersion = self::incrSourceModeVersionStrict();
            self::maybeTestHold('after_incr_before_rollback_audit');
            if (self::$testFailAfterRedisBeforeAudit) {
                throw new \RuntimeException('organization source mode 回滚失败：INCR 后模拟审计前失败');
            }
        } catch (\Throwable $e) {
            self::compensateRestoreRuntime($oldRuntime);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }

        try {
            self::writeSourceRollbackAudit((string)$newVersion, $oldRuntime);
        } catch (\Throwable $e) {
            self::compensateRestoreRuntime($oldRuntime);
            throw new \RuntimeException('写入 source_rollback 凭证失败，已恢复旧 runtime：' . $e->getMessage(), 0, $e);
        }

        // 审计已提交：最终删除 runtime；失败则保留 legacy 安全态，禁止补偿回 organization
        try {
            self::redisDeleteRuntimeStrict();
        } catch (\Throwable $e) {
            Log::error('正式回滚已写 source_rollback，但删除 runtime 失败，保留 legacy 安全状态，禁止回退 organization：' . $e->getMessage());
            self::$testSourceMode = null;
            self::$testIntegrityPassed = null;
            self::$testIntegrityQueryBoom = false;
            self::$lastSourceModeWarning = '';
            self::resetRequestModeCache();
            self::clearCache();
            self::clearIntegrityCache();
            throw new \RuntimeException(
                '组织数据源回滚审计已提交，但清理 runtime 失败（已保留 legacy 安全状态，禁止回退 organization）：' . $e->getMessage(),
                0,
                $e
            );
        }

        self::$testSourceMode = null;
        self::$testIntegrityPassed = null;
        self::$testIntegrityQueryBoom = false;
        self::$lastSourceModeWarning = '';
        self::resetRequestModeCache();
        self::clearCache();
        self::clearIntegrityCache();
    }

    /**
     * @param mixed $value
     */
    protected static function redisSetRuntimeStrict($value): void
    {
        try {
            $ok = CacheService::redisHandler()->set(self::SOURCE_MODE_RUNTIME_KEY, $value, 30 * 24 * 3600);
        } catch (\Throwable $e) {
            throw new \RuntimeException('写入 organization source mode runtime 失败：' . $e->getMessage(), 0, $e);
        }
        if ($ok === false) {
            throw new \RuntimeException('写入 organization source mode runtime 返回失败');
        }
    }

    protected static function redisDeleteRuntimeStrict(): void
    {
        try {
            $ok = CacheService::redisHandler()->delete(self::SOURCE_MODE_RUNTIME_KEY);
        } catch (\Throwable $e) {
            throw new \RuntimeException('删除 organization source mode runtime 失败：' . $e->getMessage(), 0, $e);
        }
        if ($ok === false) {
            throw new \RuntimeException('删除 organization source mode runtime 返回失败');
        }
    }

    public static function clearIntegrityCache(): void
    {
        self::$cutoverCredCache = null;
        OrganizationReconcileServices::clearCaches();
    }

    public static function setTestRedisPublishBoom(bool $boom): void
    {
        self::$testRedisPublishBoom = $boom;
    }

    public static function setTestFailAfterRuntimeSet(bool $flag): void
    {
        self::$testFailAfterRuntimeSet = $flag;
    }

    public static function setTestFailAfterRedisBeforeAudit(bool $flag): void
    {
        self::$testFailAfterRedisBeforeAudit = $flag;
    }

    /**
     * 仅供测试：在结构锁内指定阶段暂停，直到 go 文件出现。
     */
    public static function setTestHold(string $phase, string $readyFile, string $goFile): void
    {
        self::$testHoldPhase = $phase;
        self::$testHoldReadyFile = $readyFile;
        self::$testHoldGoFile = $goFile;
    }

    public static function clearTestHold(): void
    {
        self::$testHoldPhase = '';
        self::$testHoldReadyFile = '';
        self::$testHoldGoFile = '';
    }

    protected static function maybeTestHold(string $phase): void
    {
        if (self::$testHoldPhase === '' || self::$testHoldPhase !== $phase) {
            return;
        }
        if (self::$testHoldReadyFile === '' || self::$testHoldGoFile === '') {
            return;
        }
        @file_put_contents(self::$testHoldReadyFile, $phase . ' ' . (string)time());
        $deadline = time() + 25;
        while (time() < $deadline && !is_file(self::$testHoldGoFile)) {
            usleep(30000);
        }
    }

    /**
     * 绕过缓存直接读 DB organization_source_mode；缺行返回空串。
     */
    public static function readDbSourceModeBypassCache(): string
    {
        try {
            $raw = Db::name('system_config')->where('menu_name', self::SOURCE_MODE_CONFIG_KEY)->value('value');
        } catch (\Throwable $e) {
            throw new \RuntimeException('读取 DB organization_source_mode 失败：' . $e->getMessage(), 0, $e);
        }
        if ($raw === null || $raw === '') {
            return '';
        }
        $decoded = json_decode((string)$raw, true);
        if (is_string($decoded) || is_numeric($decoded)) {
            return self::normalizeSourceModeStatic($decoded, false);
        }
        return self::normalizeSourceModeStatic($raw, false);
    }

    /**
     * 是否存在有效正式切源凭证（source_rollback 优先 fail-closed）。
     * 结果按 source_mode_version 缓存，最长 CUTOVER_CRED_MAX_AGE 秒；版本变化立即失效。
     */
    public function hasValidCutoverCredential(): bool
    {
        $version = $this->getSourceModeVersion();
        $now = time();
        if (self::$cutoverCredCache !== null
            && (string)self::$cutoverCredCache['version'] === (string)$version
            && ($now - (int)self::$cutoverCredCache['cached_at']) < self::CUTOVER_CRED_MAX_AGE
        ) {
            return (bool)self::$cutoverCredCache['value'];
        }
        $value = $this->loadValidCutoverCredentialFromDb();
        self::$cutoverCredCache = [
            'version' => (string)$version,
            'value' => $value,
            'cached_at' => $now,
        ];
        return $value;
    }

    protected function loadValidCutoverCredentialFromDb(): bool
    {
        try {
            $latest = Db::name('organization_change_log')
                ->whereIn('action', [self::SOURCE_CUTOVER_ACTION, self::SOURCE_ROLLBACK_ACTION])
                ->order('id', 'desc')
                ->find();
        } catch (\Throwable $e) {
            return false;
        }
        if (empty($latest)) {
            return false;
        }
        if (($latest['action'] ?? '') === self::SOURCE_ROLLBACK_ACTION) {
            return false;
        }
        $after = [];
        $raw = $latest['after_data'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $after = $decoded;
            }
        }
        return ($after['status'] ?? '') === 'committed'
            && trim((string)($after['batch_id'] ?? '')) !== '';
    }

    /**
     * organization 切源门禁：DB + 对账 + 成功批次（不写 Redis）
     * @return array{batch_id:string,legacy_hash:string,new_projection_hash:string}
     */
    protected static function assertOrganizationCutoverAllowed(): array
    {
        $dbMode = self::readDbSourceModeBypassCache();
        if ($dbMode !== self::MODE_ORGANIZATION) {
            throw new \RuntimeException(
                '拒绝发布 organization：DB organization_source_mode 必须明确为 organization（当前='
                . ($dbMode === '' ? '缺失' : $dbMode) . '）'
            );
        }
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(self::class);
        $reconcile = $scope->reconcileLegacyMigration(true, true);
        if (empty($reconcile['passed'])) {
            throw new \RuntimeException('拒绝发布 organization：' . implode('；', $reconcile['blockers'] ?? ['对账未通过']));
        }
        $batchId = trim((string)($reconcile['anomaly']['batch_id'] ?? ''));
        $legacyHash = (string)($reconcile['legacy_hash'] ?? '');
        $newHash = (string)($reconcile['new_projection_hash'] ?? '');
        if ($batchId === '' || $legacyHash === '' || $newHash === '') {
            throw new \RuntimeException('拒绝发布 organization：成功批次 batch_id/hash 不完整');
        }
        return [
            'batch_id' => $batchId,
            'legacy_hash' => $legacyHash,
            'new_projection_hash' => $newHash,
        ];
    }

    /**
     * @return mixed|null
     */
    protected static function readRuntimeModeRaw()
    {
        try {
            $runtime = CacheService::redisHandler()->get(self::SOURCE_MODE_RUNTIME_KEY);
            if ($runtime === false || $runtime === '') {
                return null;
            }
            return $runtime;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function assertVersionKeyNumericOrEmpty(): void
    {
        // 与 INCR 一致走原生 Redis，避免 Cache 反序列化把「非数字脏键」变成无关异常
        try {
            $handler = CacheService::redisHandler()->handler();
            $prefix = (string)(\think\facade\Config::get('cache.stores.redis.prefix') ?? '');
            $ver = $handler->get($prefix . self::SOURCE_MODE_VERSION_KEY);
        } catch (\Throwable $e) {
            throw new \RuntimeException('读取 source mode 版本键失败：' . $e->getMessage(), 0, $e);
        }
        if ($ver === null || $ver === false || $ver === '') {
            return;
        }
        $raw = (string)$ver;
        // CacheService 写入的整数常见形态：纯数字 / i:123; / s:N:"123";
        if (preg_match('/^\d+$/', $raw)) {
            return;
        }
        if (preg_match('/^i:(\d+);$/', $raw)) {
            return;
        }
        if (preg_match('/^s:\d+:"(\d+)";$/', $raw)) {
            return;
        }
        throw new \RuntimeException('organization source mode 版本键非数字，拒绝发布：' . $raw);
    }

    /**
     * @param mixed $oldRuntime
     */
    protected static function compensateRestoreRuntime($oldRuntime): void
    {
        try {
            if ($oldRuntime === null || $oldRuntime === false || $oldRuntime === '') {
                self::redisDeleteRuntimeStrict();
            } else {
                self::redisSetRuntimeStrict($oldRuntime);
            }
        } catch (\Throwable $e) {
            Log::error('补偿恢复 organization source mode runtime 失败：' . $e->getMessage());
        }
        try {
            self::incrSourceModeVersionStrict();
        } catch (\Throwable $e) {
            Log::error('补偿后递增 source mode 版本失败：' . $e->getMessage());
        }
        self::resetRequestModeCache();
        try {
            SystemConfigService::clear();
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * @param array{batch_id:string,legacy_hash:string,new_projection_hash:string} $meta
     */
    protected static function writeSourceCutoverCommittedAudit(array $meta, string $version): void
    {
        Db::name('organization_change_log')->insert([
            'org_id' => 0,
            'action' => self::SOURCE_CUTOVER_ACTION,
            'target_type' => 'source_mode',
            'target_id' => 0,
            'before_data' => '',
            'after_data' => json_encode([
                'status' => 'committed',
                'source_mode' => self::MODE_ORGANIZATION,
                'batch_id' => (string)($meta['batch_id'] ?? ''),
                'legacy_hash' => (string)($meta['legacy_hash'] ?? ''),
                'new_projection_hash' => (string)($meta['new_projection_hash'] ?? ''),
                'source_mode_version' => $version,
                'finished_at' => time(),
            ], JSON_UNESCAPED_UNICODE),
            'remark' => '正式切源 committed',
            'operator_id' => 0,
            'operator_name' => 'system',
            'add_time' => time(),
        ]);
    }

    /**
     * @param mixed $oldRuntime
     */
    protected static function writeSourceRollbackAudit(string $version, $oldRuntime): void
    {
        Db::name('organization_change_log')->insert([
            'org_id' => 0,
            'action' => self::SOURCE_ROLLBACK_ACTION,
            'target_type' => 'source_mode',
            'target_id' => 0,
            'before_data' => json_encode(['runtime' => $oldRuntime], JSON_UNESCAPED_UNICODE),
            'after_data' => json_encode([
                'status' => 'rolled_back',
                'source_mode_version' => $version,
                'finished_at' => time(),
            ], JSON_UNESCAPED_UNICODE),
            'remark' => '组织数据源回滚',
            'operator_id' => 0,
            'operator_name' => 'system',
            'add_time' => time(),
        ]);
    }

    public static function getSourceModeVersionPublic(): string
    {
        try {
            $ver = CacheService::redisHandler()->get(self::SOURCE_MODE_VERSION_KEY);
            if ($ver !== null && $ver !== false && $ver !== '') {
                return (string)$ver;
            }
        } catch (\Throwable $e) {
            // fallthrough
        }
        return '0';
    }

    protected static function resetRequestModeCache(): void
    {
        self::$requestMode = null;
        self::$requestModeVersion = null;
        self::$requestModeCachedAt = 0;
        self::$cutoverCredCache = null;
    }

    /** 仅供测试：清空请求内 mode 缓存 */
    public static function resetRequestModeCacheForTest(): void
    {
        self::resetRequestModeCache();
    }

    /**
     * 版本递增必须成功（Redis INCR，键前缀与 CacheService 读写一致）
     * @return string 新版本号
     */
    protected static function incrSourceModeVersionStrict(): string
    {
        try {
            $handler = CacheService::redisHandler()->handler();
            $prefix = (string)(\think\facade\Config::get('cache.stores.redis.prefix') ?? '');
            $ver = $handler->incr($prefix . self::SOURCE_MODE_VERSION_KEY);
            if ($ver === false || $ver === null) {
                throw new \RuntimeException('Redis INCR organization source mode version 返回失败');
            }
            return (string)$ver;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Redis INCR organization source mode version 失败：' . $e->getMessage(), 0, $e);
        }
    }

    protected function getSourceModeVersion(): string
    {
        try {
            $ver = CacheService::redisHandler()->get(self::SOURCE_MODE_VERSION_KEY);
            if ($ver !== null && $ver !== false && $ver !== '') {
                return (string)$ver;
            }
        } catch (\Throwable $e) {
            // fallthrough
        }
        return '0';
    }

    /**
     * @return mixed
     */
    protected function readSourceModeRaw()
    {
        try {
            $runtime = CacheService::redisHandler()->get(self::SOURCE_MODE_RUNTIME_KEY);
            if ($runtime !== null && $runtime !== false && $runtime !== '') {
                return $runtime;
            }
        } catch (\Throwable $e) {
            // fallthrough to sys_config
        }
        try {
            return sys_config(self::SOURCE_MODE_CONFIG_KEY, self::MODE_LEGACY);
        } catch (\Throwable $e) {
            return self::MODE_LEGACY;
        }
    }

    protected function emptyCoverageStats(): array
    {
        return [
            'legacy_org_count' => 0,
            'mapped_org_count' => 0,
            'active_store_count' => 0,
            'mapped_store_count' => 0,
            'legacy_agent_count' => 0,
            'mapped_admin_count' => 0,
            'orphan_store_count' => 0,
        ];
    }

    /**
     * @param mixed $raw
     */
    protected function normalizeSourceMode($raw, bool $logInvalid = false): string
    {
        return self::normalizeSourceModeStatic($raw, $logInvalid);
    }

    /**
     * @param mixed $raw
     */
    protected static function normalizeSourceModeStatic($raw, bool $logInvalid = false): string
    {
        if (is_array($raw)) {
            $raw = (string)reset($raw);
        }
        $mode = strtolower(trim((string)$raw));
        if ($mode === '' || $mode === 'null' || $mode === '0') {
            return self::MODE_LEGACY;
        }
        $allowed = [self::MODE_LEGACY, self::MODE_DUAL_READ, self::MODE_ORGANIZATION];
        if (in_array($mode, $allowed, true)) {
            return $mode;
        }
        $msg = 'organization_source_mode 配置非法，已按 legacy 处理：' . $mode;
        self::$lastSourceModeWarning = $msg;
        if ($logInvalid) {
            Log::warning($msg);
        }
        return self::MODE_LEGACY;
    }

    /**
     * @return array{blockers:string[],warnings:string[]}
     */
    protected function collectMigratePreflightIssues(): array
    {
        $blockers = [];
        $warnings = [];
        try {
            $badStores = (int)Db::name('system_store')->alias('s')
                ->leftJoin('system_region_agent a', 'a.id = s.region_id AND a.is_del = 0')
                ->where('s.is_del', 0)
                ->where('s.region_id', '>', 0)
                ->whereNull('a.id')
                ->count();
            if ($badStores > 0) {
                $blockers[] = "有 {$badStores} 家门店的区域管理人员无效，无法可靠映射组织";
            }
            $agentsNoRegion = (int)Db::name('system_region_agent')
                ->where('is_del', 0)
                ->whereRaw('(manage_region_id IS NULL OR manage_region_id = 0)')
                ->count();
            if ($agentsNoRegion > 0) {
                $warnings[] = "有 {$agentsNoRegion} 名区域管理人员未绑定区域，迁移后将跳过";
            }
        } catch (\Throwable $e) {
            $warnings[] = '迁移预检查询异常：' . $e->getMessage();
        }
        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    /**
     * 组织下门店（可选含下级组织）
     */
    public function getOrgStoreIds(int $orgId, bool $includeDescendants = false): array
    {
        if ($orgId <= 0) {
            return [];
        }
        $cacheKey = $orgId . ':' . (int)$includeDescendants;
        if (isset(self::$orgStoreCache[$cacheKey])) {
            return self::$orgStoreCache[$cacheKey];
        }
        $orgIds = $includeDescendants
            ? $this->collectDescendantOrgIds($orgId)
            : [$orgId];
        $storeIds = $this->orgStoreDao->getColumn([['org_id', 'in', $orgIds]], 'store_id') ?: [];
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        self::$orgStoreCache[$cacheKey] = $storeIds;
        return $storeIds;
    }

    /**
     * 组织范围内的组织 ID（含下级），供集团人员等无门店任职资源查询使用。
     * 返回范围仍由当前组织权限决定，调用方不得用客户端组织参数替换。
     */
    public function getOrgIds(int $orgId, bool $includeDescendants = false): array
    {
        if ($orgId <= 0) return [];
        return $includeDescendants ? $this->collectDescendantOrgIds($orgId) : [$orgId];
    }

    /**
     * 管理员有效门店 = 组织全量 − 排除列表
     */
    public function getAdminResolvedStoreIds(int $orgAdminId): array
    {
        if ($orgAdminId <= 0) {
            return [];
        }
        if (isset(self::$adminStoreCache[$orgAdminId])) {
            return self::$adminStoreCache[$orgAdminId];
        }
        $admin = $this->adminDao->get($orgAdminId, ['id', 'org_id', 'is_del']);
        if (!$admin || (int)($admin['is_del'] ?? 0) === 1) {
            return [];
        }
        $admin = is_object($admin) ? $admin->toArray() : $admin;
        $orgStoreIds = $this->getOrgStoreIds((int)$admin['org_id'], true);
        if (!$orgStoreIds) {
            self::$adminStoreCache[$orgAdminId] = [];
            return [];
        }
        $excludeIds = $this->excludeDao->getColumn(['org_admin_id' => $orgAdminId], 'store_id') ?: [];
        $excludeIds = array_flip(array_map('intval', $excludeIds));
        $resolved = [];
        foreach ($orgStoreIds as $storeId) {
            if (!isset($excludeIds[$storeId])) {
                $resolved[] = $storeId;
            }
        }
        $resolved = array_values(array_unique($resolved));
        self::$adminStoreCache[$orgAdminId] = $resolved;
        return $resolved;
    }

    /**
     * 兼容旧区域代理：优先新表，否则回退旧逻辑
     */
    public function getResolvedStoreIdsByLegacyAgentId(int $agentId): array
    {
        if ($agentId <= 0) {
            return [];
        }
        /** @var SystemRegionAgentServices $legacy */
        $legacy = app()->make(SystemRegionAgentServices::class);
        if ($this->isMigrated()) {
            $orgAdmin = $this->adminDao->getOne([
                'legacy_agent_id' => $agentId,
                'is_del' => 0,
            ], 'id,org_id');
            if ($orgAdmin) {
                $row = is_object($orgAdmin) ? $orgAdmin->toArray() : $orgAdmin;
                return $this->getAdminResolvedStoreIds((int)$row['id']);
            }
            // 无 org_admin 映射：仅显式兼容开关才回落旧管辖树；禁止再走 bridge（否则与 getAgentStoreScopeIds 死递归）
            if (!$this->isLegacyRegionAgentCompatEnabled()) {
                return [];
            }
            return $legacy->getAgentStoreScopeIds($agentId, false);
        }
        return $legacy->getAgentStoreScopeIds($agentId, false);
    }

    /**
     * 正式切源后是否开启「仅旧区域代理」显式兼容（默认关）。
     */
    public function isLegacyRegionAgentCompatEnabled(): bool
    {
        if (!$this->isMigrated()) {
            return false;
        }
        try {
            $raw = SystemConfigService::get(self::LEGACY_REGION_AGENT_COMPAT_KEY, '0');
        } catch (\Throwable $e) {
            try {
                $raw = Db::name('system_config')->where('menu_name', self::LEGACY_REGION_AGENT_COMPAT_KEY)->value('value');
            } catch (\Throwable $e2) {
                return false;
            }
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_string($decoded) || is_numeric($decoded)) {
                $raw = $decoded;
            }
        }
        $v = strtolower(trim((string)$raw));
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * 仅有效 organization_admin 的门店并集（组织及下级 − 排除），不含旧代理回退。
     */
    public function getOrganizationAdminStoreIdsByUid(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        /** @var \app\services\user\UserServices $userServices */
        $userServices = app()->make(\app\services\user\UserServices::class);
        $phone = trim((string)$userServices->value(['uid' => $uid], 'phone'));
        $where = ['is_del' => 0];
        $admins = [];
        if ($phone !== '') {
            $admins = array_merge($admins, $this->adminDao->getList(array_merge($where, ['phone' => $phone])) ?: []);
        }
        $uidAdmins = $this->adminDao->getList(array_merge($where, ['uid' => $uid])) ?: [];
        $admins = array_merge($admins, $uidAdmins);
        $storeIds = [];
        $seenAdmin = [];
        foreach ($admins as $admin) {
            $adminId = (int)($admin['id'] ?? 0);
            if (!$adminId || isset($seenAdmin[$adminId])) {
                continue;
            }
            $seenAdmin[$adminId] = true;
            $storeIds = array_merge($storeIds, $this->getAdminResolvedStoreIds($adminId));
        }
        return array_values(array_unique(array_filter(array_map('intval', $storeIds))));
    }

    /**
     * 是否存在有效 organization_admin 记录（uid/手机号），不论门店是否被全部排除。
     */
    public function userHasOrganizationAdmin(int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }
        /** @var \app\services\user\UserServices $userServices */
        $userServices = app()->make(\app\services\user\UserServices::class);
        $phone = trim((string)$userServices->value(['uid' => $uid], 'phone'));
        if ($phone !== '') {
            $byPhone = $this->adminDao->getOne(['phone' => $phone, 'is_del' => 0], 'id');
            if ($byPhone) {
                return true;
            }
        }
        $byUid = $this->adminDao->getOne(['uid' => $uid, 'is_del' => 0], 'id');
        return !empty($byUid);
    }

    /**
     * 手机端区域身份与有效门店范围（商家端 / agent/home / 目标共用）。
     *
     * @return array{is_region:bool,store_ids:array,via:string,legacy_agent_id:int}
     */
    public function resolveMobileRegionAccess(int $uid): array
    {
        $empty = [
            'is_region' => false,
            'store_ids' => [],
            'via' => 'none',
            'legacy_agent_id' => 0,
        ];
        if ($uid <= 0) {
            return $empty;
        }

        if ($this->isMigrated()) {
            if ($this->userHasOrganizationAdmin($uid)) {
                return [
                    'is_region' => true,
                    'store_ids' => $this->getOrganizationAdminStoreIdsByUid($uid),
                    'via' => 'organization_admin',
                    'legacy_agent_id' => 0,
                ];
            }
            if (!$this->isLegacyRegionAgentCompatEnabled()) {
                return $empty;
            }
            /** @var SystemRegionAgentServices $legacy */
            $legacy = app()->make(SystemRegionAgentServices::class);
            $agent = $legacy->getRegionAgentByUid($uid);
            $agentId = (int)($agent['id'] ?? 0);
            if ($agentId <= 0) {
                return $empty;
            }
            $storeIds = $this->getResolvedStoreIdsByLegacyAgentId($agentId);
            return [
                'is_region' => true,
                'store_ids' => $storeIds,
                'via' => 'legacy_compat',
                'legacy_agent_id' => $agentId,
            ];
        }

        /** @var SystemRegionAgentServices $legacy */
        $legacy = app()->make(SystemRegionAgentServices::class);
        $agent = $legacy->getRegionAgentByUid($uid);
        $agentId = (int)($agent['id'] ?? 0);
        if ($agentId <= 0) {
            return $empty;
        }
        return [
            'is_region' => true,
            'store_ids' => $this->getResolvedStoreIdsByLegacyAgentId($agentId),
            'via' => 'legacy_agent',
            'legacy_agent_id' => $agentId,
        ];
    }

    /**
     * 解析集团根组织 ID（禁止硬编码 id=1）。
     * 迁移未完成返回 0；已切源则取 pid=0 且未删除的根，按 sort/id 取第一个。
     */
    public function resolveGroupRootOrgId(): int
    {
        if (!$this->isMigrated()) {
            return 0;
        }
        try {
            $row = Db::name('organization')
                ->where('pid', 0)
                ->where('is_del', 0)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->field('id')
                ->find();
            return (int)($row['id'] ?? 0);
        } catch (\Throwable $e) {
            Log::warning('resolveGroupRootOrgId 失败：' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 经营看板门店范围：org 展开 ∩ allowed；若传 store_id 须在交集内，否则空（越权不回退）。
     *
     * @param int $orgId 0=不按组织再收窄（仅用 allowed）
     * @param int $storeId 0=不单店收窄
     * @param int[] $allowedStoreIds
     * @return int[]
     */
    public function resolveDashboardStoreIds(int $orgId, int $storeId, array $allowedStoreIds): array
    {
        $allowed = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        if (!$allowed) {
            return [];
        }
        $resolved = $allowed;
        if ($orgId > 0) {
            $orgStores = $this->getOrgStoreIds($orgId, true);
            if (!$orgStores) {
                return [];
            }
            $allowedFlip = array_flip($allowed);
            $resolved = [];
            foreach ($orgStores as $sid) {
                $sid = (int)$sid;
                if ($sid > 0 && isset($allowedFlip[$sid])) {
                    $resolved[] = $sid;
                }
            }
            $resolved = array_values(array_unique($resolved));
        }
        if ($storeId > 0) {
            if (!in_array($storeId, $resolved, true)) {
                return [];
            }
            return [$storeId];
        }
        return $resolved;
    }

    /**
     * 在有效范围内解析前端筛选；有效范围空则空；交集空则空（绝不回退全量）。
     *
     * @param array $allowedStoreIds
     * @param array $params store_id/store_ids/org_ids/excluded_store_ids
     * @return array
     */
    public function resolveScopedStoreIdsFromRequest(array $allowedStoreIds, array $params = []): array
    {
        $allowed = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        if (!$allowed) {
            return [];
        }
        $storeIdsRaw = $params['store_ids'] ?? $params['store_id'] ?? '';
        $orgIdsRaw = $params['org_ids'] ?? '';
        $excludedRaw = $params['excluded_store_ids'] ?? '';
        $hasFilter = false;
        if (is_array($storeIdsRaw)) {
            $hasFilter = !empty(array_filter(array_map('intval', $storeIdsRaw)));
        } else {
            $hasFilter = trim((string)$storeIdsRaw) !== '' && (string)$storeIdsRaw !== '0';
        }
        if (!$hasFilter && (int)($params['store_id'] ?? 0) > 0) {
            $hasFilter = true;
            $storeIdsRaw = (int)$params['store_id'];
        }
        if (trim((string)$orgIdsRaw) !== '' || trim((string)$excludedRaw) !== '') {
            $hasFilter = true;
        }
        if (!$hasFilter) {
            return $allowed;
        }
        return $this->resolveStoreIdsFromFilter([
            'store_ids' => $storeIdsRaw,
            'org_ids' => $orgIdsRaw,
            'excluded_store_ids' => $excludedRaw,
        ], $allowed);
    }

    /**
     * 手机端 uid → 有效门店并集
     * 正式切源后：仅 organization_admin（默认）；无 org_admin 时不隐式回退旧代理，除非显式兼容开关。
     */
    public function getResolvedStoreIdsByUid(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        if ($this->isMigrated()) {
            $storeIds = $this->getOrganizationAdminStoreIdsByUid($uid);
            if ($storeIds) {
                return $storeIds;
            }
            if ($this->userHasOrganizationAdmin($uid)) {
                // 有管理员身份但门店全排除
                return [];
            }
            if (!$this->isLegacyRegionAgentCompatEnabled()) {
                return [];
            }
        }
        /** @var SystemRegionAgentServices $legacy */
        $legacy = app()->make(SystemRegionAgentServices::class);
        $agent = $legacy->getRegionAgentByUid($uid);
        if (!$agent) {
            return [];
        }
        return $this->getResolvedStoreIdsByLegacyAgentId((int)($agent['id'] ?? 0));
    }

    /**
     * 实时解析筛选参数（分析类不固化，单次请求完成）
     *
     * @param array $params store_ids, org_ids, excluded_store_ids, legacy_agent_id
     * @param array $allowedStoreIds 调用方权限上限
     */
    public function resolveStoreIdsFromFilter(array $params, array $allowedStoreIds = []): array
    {
        $allowedSet = $allowedStoreIds
            ? array_flip(array_map('intval', $allowedStoreIds))
            : [];

        $storeIds = $this->parseIdList($params['store_ids'] ?? $params['store_id'] ?? '');
        $orgIds = $this->parseIdList($params['org_ids'] ?? $params['manage_region_id'] ?? '');
        $excludeIds = $this->parseIdList($params['excluded_store_ids'] ?? '');

        $resolved = [];
        if ($storeIds) {
            $resolved = array_merge($resolved, $storeIds);
        }
        foreach ($orgIds as $orgId) {
            $resolved = array_merge($resolved, $this->getOrgStoreIds((int)$orgId, true));
        }
        $resolved = array_values(array_unique(array_filter(array_map('intval', $resolved))));
        if ($excludeIds) {
            $excludeFlip = array_flip($excludeIds);
            $resolved = array_values(array_filter($resolved, function ($id) use ($excludeFlip) {
                return !isset($excludeFlip[$id]);
            }));
        }
        if ($allowedSet) {
            $resolved = array_values(array_filter($resolved, function ($id) use ($allowedSet) {
                return isset($allowedSet[$id]);
            }));
        }
        return $resolved;
    }

    /**
     * 按旧架构区域 ID（manage_region_id）解析门店
     */
    public function getOrgStoreIdsByLegacyManageRegionId(int $legacyManageRegionId, bool $includeDescendants = true): array
    {
        if ($legacyManageRegionId <= 0) {
            return [];
        }
        if (!$this->isMigrated()) {
            /** @var SystemRegionManageServices $manageServices */
            $manageServices = app()->make(SystemRegionManageServices::class);
            return $manageServices->getStoreIdsByManageRegion($legacyManageRegionId, $includeDescendants);
        }
        $org = $this->orgDao->getOne(['legacy_manage_region_id' => $legacyManageRegionId, 'is_del' => 0], 'id');
        if (!$org) {
            return [];
        }
        $org = is_object($org) ? $org->toArray() : $org;
        return $this->getOrgStoreIds((int)$org['id'], $includeDescendants);
    }

    /**
     * 门店当前组织
     */
    public function getStoreOrgId(int $storeId): int
    {
        if ($storeId <= 0) {
            return 0;
        }
        if (!$this->isMigrated()) {
            return 0;
        }
        return (int)$this->orgStoreDao->value(['store_id' => $storeId], 'org_id');
    }

    /**
     * @param int $orgId
     * @return int[]
     */
    protected function collectDescendantOrgIds(int $orgId): array
    {
        $all = $this->orgDao->getList(['is_del' => 0], 'id,pid') ?: [];
        if ($this->detectCycleInOrgRows($all)) {
            throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
        }
        $children = [];
        foreach ($all as $row) {
            $id = (int)($row['id'] ?? 0);
            $pid = (int)($row['pid'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $children[$pid][] = $id;
        }
        $result = [$orgId];
        $queue = [$orgId];
        $visited = [$orgId => true];
        $guard = 0;
        $max = count($all) + 2;
        while ($queue) {
            if (++$guard > $max) {
                throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
            }
            $current = (int)array_shift($queue);
            foreach ($children[$current] ?? [] as $childId) {
                $childId = (int)$childId;
                if ($childId <= 0) {
                    continue;
                }
                if (isset($visited[$childId])) {
                    throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
                }
                $visited[$childId] = true;
                $result[] = $childId;
                $queue[] = $childId;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    public function parseIdListPublic($value): array
    {
        return $this->parseIdList($value);
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    protected function parseIdList($value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_filter(array_map('intval', $value))));
        }
        $str = trim((string)$value);
        if ($str === '') {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $str)))));
    }

    /**
     * 手机端门店选择树（组织 + 门店叶子，按当前用户可管范围裁剪）
     *
     * 节点约定：
     * - node_type=org：组织（含 children）
     * - node_type=store：门店叶子
     * - object_type 兼容旧目标树：2=组织/区域，1=门店
     *
     * @param int[] $allowedStoreIds 空数组表示无权限
     */
    /**
     * @param int[] $allowedStoreIds 空数组表示无门店权限；配合 $includeEmptyOrgs 仍可返回纯组织树
     * @param bool $includeEmptyOrgs 为 true 时保留无下级门店/组织的组织节点（人员选所属组织需要）
     */
    public function buildPickerTree(array $allowedStoreIds, bool $includeEmptyOrgs = false): array
    {
        $allowedStoreIds = array_values(array_unique(array_filter(array_map('intval', $allowedStoreIds))));
        if (!$allowedStoreIds && !$includeEmptyOrgs) {
            return [];
        }
        $allowedSet = array_flip($allowedStoreIds);

        /** @var \app\dao\store\SystemStoreDao $storeDao */
        $storeDao = app()->make(\app\dao\store\SystemStoreDao::class);
        $storeRows = $allowedStoreIds
            ? ($storeDao->getStoreList(
                ['id' => $allowedStoreIds, 'is_del' => 0],
                ['id', 'name', 'phone', 'address'],
                0,
                0
            ) ?: [])
            : [];
        $storeMap = [];
        foreach ($storeRows as $row) {
            $sid = (int)($row['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $storeMap[$sid] = [
                'id' => $sid,
                'name' => (string)($row['name'] ?? ('门店' . $sid)),
                'node_type' => 'store',
                'object_type' => 1,
                'label' => '门店',
                'desc' => trim((string)(($row['phone'] ?? '') . ' ' . ($row['address'] ?? ''))),
                'phone' => (string)($row['phone'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'children' => [],
                'disabled' => false,
            ];
        }

        if (!$this->isMigrated()) {
            return $this->buildLegacyPickerTree($allowedSet, $storeMap);
        }

        $orgList = $this->orgDao->getList(['is_del' => 0], 'id,pid,name,sort') ?: [];
        $orgByPid = [];
        foreach ($orgList as $org) {
            $pid = (int)($org['pid'] ?? 0);
            $orgByPid[$pid][] = $org;
        }
        foreach ($orgByPid as &$children) {
            usort($children, function ($a, $b) {
                $sa = (int)($a['sort'] ?? 0);
                $sb = (int)($b['sort'] ?? 0);
                if ($sa === $sb) {
                    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
                }
                return $sa <=> $sb;
            });
        }
        unset($children);

        $directStoresByOrg = [];
        if ($allowedStoreIds) {
            $bindings = $this->orgStoreDao->getList(['store_id' => $allowedStoreIds], 'org_id,store_id') ?: [];
            foreach ($bindings as $bind) {
                $oid = (int)($bind['org_id'] ?? 0);
                $sid = (int)($bind['store_id'] ?? 0);
                if ($oid > 0 && isset($storeMap[$sid])) {
                    $directStoresByOrg[$oid][] = $sid;
                    $storeMap[$sid]['org_id'] = $oid;
                }
            }
        }

        $usedStoreIds = [];
        $tree = $this->buildOrgPickerNodes(0, $orgByPid, $directStoresByOrg, $storeMap, $usedStoreIds, [], $includeEmptyOrgs);

        $orphanIds = array_values(array_filter($allowedStoreIds, function ($sid) use ($usedStoreIds, $storeMap) {
            return isset($storeMap[$sid]) && !isset($usedStoreIds[$sid]);
        }));
        if ($orphanIds) {
            $orphanChildren = [];
            foreach ($orphanIds as $sid) {
                $orphanChildren[] = $storeMap[$sid];
                $usedStoreIds[$sid] = true;
            }
            $tree[] = [
                'id' => 0,
                'name' => '未归属组织门店',
                'node_type' => 'org',
                'object_type' => 2,
                'label' => '组织',
                'desc' => count($orphanChildren) . '家门店',
                'store_count' => count($orphanChildren),
                'children' => $orphanChildren,
                'disabled' => false,
            ];
        }

        return $tree;
    }

    /**
     * @param array<int, array> $orgByPid
     * @param array<int, int[]> $directStoresByOrg
     * @param array<int, array> $storeMap
     * @param array<int, bool> $usedStoreIds
     */
    protected function buildOrgPickerNodes(
        int $pid,
        array $orgByPid,
        array $directStoresByOrg,
        array $storeMap,
        array &$usedStoreIds,
        array $ancestors = [],
        bool $includeEmptyOrgs = false
    ): array {
        $nodes = [];
        foreach ($orgByPid[$pid] ?? [] as $org) {
            $orgId = (int)($org['id'] ?? 0);
            if ($orgId <= 0) {
                continue;
            }
            if (isset($ancestors[$orgId])) {
                throw new \Exception('组织树存在循环引用，请联系技术处理后再操作');
            }
            $nextAncestors = $ancestors + [$orgId => true];
            $childOrgs = $this->buildOrgPickerNodes(
                $orgId,
                $orgByPid,
                $directStoresByOrg,
                $storeMap,
                $usedStoreIds,
                $nextAncestors,
                $includeEmptyOrgs
            );
            $storeChildren = [];
            foreach ($directStoresByOrg[$orgId] ?? [] as $sid) {
                if (!isset($storeMap[$sid]) || isset($usedStoreIds[$sid])) {
                    continue;
                }
                $storeChildren[] = $storeMap[$sid];
                $usedStoreIds[$sid] = true;
            }
            $children = array_merge($childOrgs, $storeChildren);
            if (!$children && !$includeEmptyOrgs) {
                continue;
            }
            $storeCount = $this->countPickerStoreNodes($children);
            $nodes[] = [
                'id' => $orgId,
                'name' => (string)($org['name'] ?? ''),
                'node_type' => 'org',
                'object_type' => 2,
                'label' => '组织',
                'desc' => $storeCount . '家门店',
                'store_count' => $storeCount,
                'children' => $children,
                'disabled' => false,
            ];
        }
        return $nodes;
    }

    /**
     * 未迁移时回退旧区域架构树
     * @param array<int, bool> $allowedSet
     * @param array<int, array> $storeMap
     */
    protected function buildLegacyPickerTree(array $allowedSet, array $storeMap): array
    {
        /** @var SystemRegionManageServices $manageServices */
        $manageServices = app()->make(SystemRegionManageServices::class);
        $usedStoreIds = [];
        $tree = $this->buildLegacyRegionPickerNodes(0, $manageServices, $allowedSet, $storeMap, $usedStoreIds);
        $orphanChildren = [];
        foreach ($storeMap as $sid => $node) {
            if (!isset($usedStoreIds[$sid])) {
                $orphanChildren[] = $node;
            }
        }
        if ($orphanChildren) {
            $tree[] = [
                'id' => 0,
                'name' => '管辖门店',
                'node_type' => 'org',
                'object_type' => 2,
                'label' => '组织',
                'desc' => count($orphanChildren) . '家门店',
                'store_count' => count($orphanChildren),
                'children' => $orphanChildren,
                'disabled' => false,
            ];
        }
        return $tree;
    }

    /**
     * @param array<int, bool> $allowedSet
     * @param array<int, array> $storeMap
     * @param array<int, bool> $usedStoreIds
     */
    protected function buildLegacyRegionPickerNodes(
        int $pid,
        SystemRegionManageServices $manageServices,
        array $allowedSet,
        array $storeMap,
        array &$usedStoreIds
    ): array {
        $nodes = [];
        foreach ($manageServices->getChildrenList($pid) as $regionRow) {
            $regionId = (int)($regionRow['id'] ?? 0);
            if ($regionId <= 0) {
                continue;
            }
            $childOrgs = $this->buildLegacyRegionPickerNodes(
                $regionId,
                $manageServices,
                $allowedSet,
                $storeMap,
                $usedStoreIds
            );
            $directIds = $manageServices->getStoreIdsByManageRegion($regionId, false) ?: [];
            $storeChildren = [];
            foreach ($directIds as $sid) {
                $sid = (int)$sid;
                if (!isset($allowedSet[$sid]) || !isset($storeMap[$sid]) || isset($usedStoreIds[$sid])) {
                    continue;
                }
                $storeChildren[] = $storeMap[$sid];
                $usedStoreIds[$sid] = true;
            }
            $children = array_merge($childOrgs, $storeChildren);
            if (!$children) {
                continue;
            }
            $storeCount = $this->countPickerStoreNodes($children);
            $nodes[] = [
                'id' => $regionId,
                'name' => (string)($regionRow['name'] ?? ''),
                'node_type' => 'org',
                'object_type' => 2,
                'label' => '组织',
                'desc' => $storeCount . '家门店',
                'store_count' => $storeCount,
                'legacy_manage_region_id' => $regionId,
                'children' => $children,
                'disabled' => false,
            ];
        }
        return $nodes;
    }

    protected function countPickerStoreNodes(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            if (($node['node_type'] ?? '') === 'store' || (int)($node['object_type'] ?? 0) === 1) {
                $count++;
                continue;
            }
            $count += $this->countPickerStoreNodes($node['children'] ?? []);
        }
        return $count;
    }

    /**
     * 清除请求内门店范围缓存（组织变更后调用）
     */
    public static function clearCache(): void
    {
        self::$orgStoreCache = [];
        self::$adminStoreCache = [];
    }
}
