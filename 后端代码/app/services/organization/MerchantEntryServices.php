<?php
namespace app\services\organization;

use app\services\BaseServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * I3 Gate5：手机端商家入口 / 短期会话 / 可选门店 / 选择器代理
 *
 * 口径：
 * - 身份按服务端会话可信手机号查 employee，不建 UID 绑定
 * - show_merchant_entry 须员工+任职/直属+手机入口+岗位功能全部满足
 * - 商家会话以 employee_id + auth_version；变更后旧会话失效
 *
 * 表结构对齐 Gate1/033：
 * - eb_employee.auth_version
 * - eb_employee_merchant_session(token_hash, store_id, auth_version, ...)
 */
class MerchantEntryServices extends BaseServices
{
    public const SESSION_TTL = 28800;
    public const HEADER_TOKEN = 'X-Merchant-Token';
    public const REASON_NO_PHONE = 'no_trusted_phone';
    public const REASON_NO_EMPLOYEE = 'no_employee';
    public const REASON_EMPLOYEE_DISABLED = 'employee_disabled';
    public const REASON_NO_ASSIGNMENT = 'no_valid_assignment';
    public const REASON_NO_MOBILE_ENTRY = 'no_mobile_entry';
    public const REASON_NO_MOBILE_JOB = 'no_mobile_job_function';
    public const REASON_SCHEMA = 'schema_not_ready';
    public const REASON_OK = 'ok';

    /** @var bool|null */
    protected $schemaReadyCache = null;

    public function isSchemaReady(): bool
    {
        if ($this->schemaReadyCache !== null) {
            return $this->schemaReadyCache;
        }
        $db = (string)Db::query('SELECT DATABASE() AS db')[0]['db'];
        $hasCol = (int)Db::query(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=? AND TABLE_NAME='eb_employee' AND COLUMN_NAME='auth_version'",
            [$db]
        )[0]['c'];
        $hasTbl = (int)Db::query(
            "SELECT COUNT(*) c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=? AND TABLE_NAME='eb_employee_merchant_session'",
            [$db]
        )[0]['c'];
        $hasHash = (int)Db::query(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=? AND TABLE_NAME='eb_employee_merchant_session' AND COLUMN_NAME='token_hash'",
            [$db]
        )[0]['c'];
        $this->schemaReadyCache = ($hasCol > 0 && $hasTbl > 0 && $hasHash > 0);
        return $this->schemaReadyCache;
    }

    public function assertSchemaReady(): void
    {
        if (!$this->isSchemaReady()) {
            throw new ValidateException(
                '商家会话结构未就绪（缺少 eb_employee.auth_version 或 eb_employee_merchant_session.token_hash），请先执行 Gate1/033 升级包'
            );
        }
    }

    /**
     * 本地闭环兜底：与 033 正式包字段对齐（幂等）
     */
    public function bootstrapEnsureSchema(): void
    {
        $db = (string)Db::query('SELECT DATABASE() AS db')[0]['db'];
        $hasCol = (int)Db::query(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=? AND TABLE_NAME='eb_employee' AND COLUMN_NAME='auth_version'",
            [$db]
        )[0]['c'];
        if ($hasCol === 0) {
            Db::execute(
                "ALTER TABLE `eb_employee`
                 ADD COLUMN `auth_version` int(10) unsigned NOT NULL DEFAULT 1
                 COMMENT '权限版本(会话失效)' AFTER `status`"
            );
        }
        Db::execute(
            "CREATE TABLE IF NOT EXISTS `eb_employee_merchant_session` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `employee_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '员工主档ID',
              `token_hash` char(64) NOT NULL DEFAULT '' COMMENT '会话token哈希(SHA256 hex)',
              `store_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '当前门店上下文',
              `auth_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '签发时权限版本',
              `expire_time` int(11) NOT NULL DEFAULT '0' COMMENT '过期时间戳',
              `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1有效0失效',
              `is_del` tinyint(1) NOT NULL DEFAULT '0',
              `add_time` int(11) NOT NULL DEFAULT '0',
              `update_time` int(11) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_token_hash` (`token_hash`),
              KEY `idx_emp_status` (`employee_id`,`status`,`is_del`),
              KEY `idx_emp_store` (`employee_id`,`store_id`,`status`),
              KEY `idx_expire` (`expire_time`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='员工商家端短期会话'"
        );
        $this->schemaReadyCache = null;
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $phone = preg_replace('/\s+/', '', $phone);
        if (strpos($phone, '+86') === 0) {
            $phone = substr($phone, 3);
        }
        if (strpos($phone, '86') === 0 && strlen($phone) === 13) {
            $phone = substr($phone, 2);
        }
        return $phone;
    }

    public function isValidCnMobile(string $phone): bool
    {
        return (bool)preg_match('/^1[3-9]\d{9}$/', $phone);
    }

    public function resolveTrustedPhoneFromRequest($request): string
    {
        $phone = '';
        if (!is_object($request)) {
            return '';
        }
        try {
            // app\Request 通过 __call + 属性闭包提供 user()，method_exists('user') 为 false
            if (method_exists($request, 'hasMacro') && $request->hasMacro('user')) {
                $phone = (string)$request->user('phone');
            } elseif (method_exists($request, 'user')) {
                $phone = (string)$request->user('phone');
            } elseif (isset($request->phone)) {
                $phone = (string)$request->phone; // FakeReq / 测试夹具
            }
        } catch (\Throwable $e) {
            $phone = '';
        }
        $phone = $this->normalizePhone($phone);
        if ($phone !== '' && $this->isValidCnMobile($phone)) {
            return $phone;
        }
        // 回退：已登录 uid → 用户表可信手机号
        $uid = 0;
        try {
            if (method_exists($request, 'hasMacro') && $request->hasMacro('uid')) {
                $uid = (int)$request->uid();
            } elseif (method_exists($request, 'uid')) {
                $uid = (int)$request->uid();
            } elseif (isset($request->uid) && (is_int($request->uid) || is_numeric($request->uid))) {
                $uid = (int)$request->uid;
            }
        } catch (\Throwable $e) {
            $uid = 0;
        }
        if ($uid > 0) {
            $phone = $this->normalizePhone((string)Db::name('user')->where('uid', $uid)->value('phone'));
        }
        return $phone;
    }

    /**
     * @return array|null
     */
    public function resolveEmployeeByPhone(string $phone): ?array
    {
        $phone = $this->normalizePhone($phone);
        if (!$this->isValidCnMobile($phone)) {
            return null;
        }
        $row = Db::name('employee')
            ->where('phone', $phone)
            ->where('is_del', 0)
            ->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    /**
     * @return array{show_merchant_entry:bool,reason:string,employee_id:int,qualifying_store_ids:int[]}
     */
    public function canShowMerchantEntry(int $employeeId): array
    {
        $empty = [
            'show_merchant_entry' => false,
            'reason' => self::REASON_NO_EMPLOYEE,
            'employee_id' => 0,
            'qualifying_store_ids' => [],
        ];
        if ($employeeId <= 0) {
            return $empty;
        }
        $emp = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->find();
        if (!$emp) {
            return $empty;
        }
        if ((int)($emp['status'] ?? 0) !== 1) {
            return [
                'show_merchant_entry' => false,
                'reason' => self::REASON_EMPLOYEE_DISABLED,
                'employee_id' => $employeeId,
                'qualifying_store_ids' => [],
            ];
        }

        $hasOrgDirect = (int)Db::name('organization_employee')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->count() > 0;

        $staffRows = Db::name('system_store_staff')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->field('id,store_id')
            ->select()->toArray() ?: [];

        if (!$staffRows && !$hasOrgDirect) {
            return [
                'show_merchant_entry' => false,
                'reason' => self::REASON_NO_ASSIGNMENT,
                'employee_id' => $employeeId,
                'qualifying_store_ids' => [],
            ];
        }

        /** @var OrganizationOpsStatusServices $ops */
        $ops = app()->make(OrganizationOpsStatusServices::class);
        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);

        $legacyMobileOk = (int)Db::name('employee_mobile_auth')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->count() > 0;

        $qualifying = [];
        $sawMobileEntry = false;
        $sawMobileJob = false;

        foreach ($staffRows as $staff) {
            $staffId = (int)($staff['id'] ?? 0);
            $storeId = (int)($staff['store_id'] ?? 0);
            if ($staffId <= 0 || $storeId <= 0) {
                continue;
            }
            if (!$ops->isStoreBusinessEnabled($storeId)) {
                continue;
            }
            $entryOn = (int)Db::name('staff_channel_entry')
                ->where('staff_id', $staffId)
                ->where('channel', JobPositionPolicyServices::CHANNEL_MOBILE)
                ->where('is_del', 0)
                ->where('status', 1)
                ->count() > 0;
            if (!$entryOn && !$legacyMobileOk) {
                continue;
            }
            $sawMobileEntry = true;
            $rules = $jobSvc->computeChannelRulesUnion($staffId, JobPositionPolicyServices::CHANNEL_MOBILE);
            if (!$rules) {
                continue;
            }
            $sawMobileJob = true;
            $qualifying[] = $storeId;
        }

        if (!$qualifying && $hasOrgDirect && $legacyMobileOk) {
            try {
                $jobSvc->assertChannelCoveredByJobs(0, JobPositionPolicyServices::CHANNEL_MOBILE, $employeeId);
                return [
                    'show_merchant_entry' => true,
                    'reason' => self::REASON_OK,
                    'employee_id' => $employeeId,
                    'qualifying_store_ids' => [],
                ];
            } catch (\Throwable $e) {
                $sawMobileEntry = true;
            }
        }

        if (!$qualifying) {
            $reason = self::REASON_NO_MOBILE_ENTRY;
            if ($sawMobileEntry && !$sawMobileJob) {
                $reason = self::REASON_NO_MOBILE_JOB;
            }
            return [
                'show_merchant_entry' => false,
                'reason' => $reason,
                'employee_id' => $employeeId,
                'qualifying_store_ids' => [],
            ];
        }

        return [
            'show_merchant_entry' => true,
            'reason' => self::REASON_OK,
            'employee_id' => $employeeId,
            'qualifying_store_ids' => array_values(array_unique(array_map('intval', $qualifying))),
        ];
    }

    /**
     * @return array{show_merchant_entry:bool,reason:string,employee_id:int,phone_bound:bool,qualifying_store_ids?:int[]}
     */
    public function resolveEntryForRequest($request): array
    {
        $phone = $this->resolveTrustedPhoneFromRequest($request);
        if ($phone === '' || !$this->isValidCnMobile($phone)) {
            return [
                'show_merchant_entry' => false,
                'reason' => self::REASON_NO_PHONE,
                'employee_id' => 0,
                'phone_bound' => false,
                'qualifying_store_ids' => [],
            ];
        }
        $emp = $this->resolveEmployeeByPhone($phone);
        if (!$emp) {
            return [
                'show_merchant_entry' => false,
                'reason' => self::REASON_NO_EMPLOYEE,
                'employee_id' => 0,
                'phone_bound' => true,
                'qualifying_store_ids' => [],
            ];
        }
        $judge = $this->canShowMerchantEntry((int)$emp['id']);
        $judge['phone_bound'] = true;
        return $judge;
    }

    public function getEmployeeAuthVersion(int $employeeId): int
    {
        $this->assertSchemaReady();
        if ($employeeId <= 0) {
            return 0;
        }
        return max(1, (int)Db::name('employee')->where('id', $employeeId)->value('auth_version'));
    }

    public function bumpAuthVersion(int $employeeId, string $reason = ''): int
    {
        $this->assertSchemaReady();
        if ($employeeId <= 0) {
            return 0;
        }
        // 复用 Gate2 已落地的 bump + 会话失效，避免双写分叉
        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        $ver = $jobSvc->bumpEmployeeAuthVersion($employeeId);
        $jobSvc->invalidateMerchantSessions($employeeId);
        return $ver;
    }

    public function invalidateByAuthVersion(int $employeeId, ?int $currentVersion = null): int
    {
        $this->assertSchemaReady();
        if ($employeeId <= 0) {
            return 0;
        }
        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        return $jobSvc->invalidateMerchantSessions($employeeId);
    }

    /**
     * @return array{merchant_token:string,employee_id:int,auth_version:int,active_store_id:int,expire_time:int,stores:array}
     */
    public function openSession(int $employeeId, int $uid = 0, int $activeStoreId = 0): array
    {
        $this->assertSchemaReady();
        $judge = $this->canShowMerchantEntry($employeeId);
        if (!$judge['show_merchant_entry']) {
            throw new ValidateException($this->reasonMessage($judge['reason']));
        }
        $stores = $this->listSelectableStoresForMerchant($employeeId);
        $allowed = array_column($stores, 'store_id');
        if ($activeStoreId > 0 && $allowed && !in_array($activeStoreId, $allowed, true)) {
            throw new ValidateException('所选门店不在可切换范围内');
        }
        if ($activeStoreId <= 0 && $allowed) {
            $activeStoreId = (int)$allowed[0];
        }
        if ($activeStoreId > 0) {
            /** @var OrganizationOpsStatusServices $ops */
            $ops = app()->make(OrganizationOpsStatusServices::class);
            if (!$ops->isStoreBusinessEnabled($activeStoreId)) {
                throw new ValidateException('门店已停用或所属组织已停用，暂不可进入商家端');
            }
        }

        $authVersion = $this->getEmployeeAuthVersion($employeeId);
        $now = time();
        $token = bin2hex(random_bytes(32));
        $tokenHash = $this->hashToken($token);
        $expire = $now + self::SESSION_TTL;

        Db::name('employee_merchant_session')
            ->where('employee_id', $employeeId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->update(['status' => 0, 'update_time' => $now]);

        Db::name('employee_merchant_session')->insert([
            'employee_id' => $employeeId,
            'token_hash' => $tokenHash,
            'store_id' => $activeStoreId,
            'auth_version' => $authVersion,
            'expire_time' => $expire,
            'status' => 1,
            'is_del' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ]);

        return [
            'merchant_token' => $token,
            'employee_id' => $employeeId,
            'auth_version' => $authVersion,
            'active_store_id' => $activeStoreId,
            'store_id' => $activeStoreId,
            'expire_time' => $expire,
            'stores' => $stores,
        ];
    }

    /**
     * @return array{session:array,employee_id:int,auth_version:int,active_store_id:int,qualifying_store_ids:int[]}
     */
    public function assertSession(string $token): array
    {
        $this->assertSchemaReady();
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            throw new ValidateException('商家会话无效，请重新进入');
        }
        $hash = $this->hashToken($token);
        $row = Db::name('employee_merchant_session')
            ->where('token_hash', $hash)
            ->where('is_del', 0)
            ->find();
        if (!$row || (int)($row['status'] ?? 0) !== 1) {
            throw new ValidateException('商家会话已失效，请重新进入');
        }
        if ((int)($row['expire_time'] ?? 0) < time()) {
            Db::name('employee_merchant_session')->where('id', (int)$row['id'])->update([
                'status' => 0,
                'update_time' => time(),
            ]);
            throw new ValidateException('商家会话已过期，请重新进入');
        }
        $employeeId = (int)$row['employee_id'];
        $currentVer = $this->getEmployeeAuthVersion($employeeId);
        if ($currentVer !== (int)$row['auth_version']) {
            Db::name('employee_merchant_session')->where('id', (int)$row['id'])->update([
                'status' => 0,
                'update_time' => time(),
            ]);
            throw new ValidateException('权限已变更，商家会话已失效，请重新进入');
        }
        $judge = $this->canShowMerchantEntry($employeeId);
        if (!$judge['show_merchant_entry']) {
            Db::name('employee_merchant_session')->where('id', (int)$row['id'])->update([
                'status' => 0,
                'update_time' => time(),
            ]);
            throw new ValidateException($this->reasonMessage($judge['reason']));
        }
        $activeStoreId = (int)($row['store_id'] ?? 0);
        if ($activeStoreId > 0) {
            $allowed = $judge['qualifying_store_ids'];
            if ($allowed && !in_array($activeStoreId, $allowed, true)) {
                throw new ValidateException('当前门店权限已收回');
            }
            /** @var OrganizationOpsStatusServices $ops */
            $ops = app()->make(OrganizationOpsStatusServices::class);
            if (!$ops->isStoreBusinessEnabled($activeStoreId)) {
                throw new ValidateException('门店已停用或所属组织已停用');
            }
        }
        return [
            'session' => is_array($row) ? $row : $row->toArray(),
            'employee_id' => $employeeId,
            'auth_version' => $currentVer,
            'active_store_id' => $activeStoreId,
            'qualifying_store_ids' => $judge['qualifying_store_ids'],
        ];
    }

    /**
     * 归属校验内核：token 对应会话的 employee_id 必须与当前登录员工完全一致。
     * 查会话行（含已失效），不一致 fail-closed；不修改任何会话。
     * 无会话行时返回 null（允许当前员工新开）；有行且归属正确时返回行数组。
     *
     * @return array|null
     */
    public function assertMerchantTokenBelongsToEmployee(string $token, int $employeeId): ?array
    {
        $token = trim($token);
        $employeeId = (int)$employeeId;
        if ($token === '' || $employeeId <= 0) {
            throw new ValidateException('商家会话无效，请重新进入');
        }
        $this->assertSchemaReady();
        $hash = $this->hashToken($token);
        $row = Db::name('employee_merchant_session')
            ->where('token_hash', $hash)
            ->where('is_del', 0)
            ->find();
        if (!$row) {
            return null;
        }
        $rowArr = is_array($row) ? $row : $row->toArray();
        if ((int)($rowArr['employee_id'] ?? 0) !== $employeeId) {
            throw new ValidateException('商家会话与当前登录员工不匹配');
        }
        return $rowArr;
    }

    /**
     * 从请求解析当前可信员工，并校验 token 归属（所有接收 merchant_token 的入口共用）。
     *
     * @return array{employee:array,session_row:?array}
     */
    public function assertMerchantTokenOwnedByRequest(string $token, $request): array
    {
        $phone = $this->resolveTrustedPhoneFromRequest($request);
        if ($phone === '' || !$this->isValidCnMobile($phone)) {
            throw new ValidateException('请先完成可信手机号授权');
        }
        $emp = $this->resolveEmployeeByPhone($phone);
        if (!$emp) {
            throw new ValidateException('当前手机号未关联有效员工');
        }
        $sessionRow = $this->assertMerchantTokenBelongsToEmployee($token, (int)$emp['id']);
        return [
            'employee' => $emp,
            'session_row' => $sessionRow,
        ];
    }

    public function switchSessionStore(string $token, int $storeId): array
    {
        $ctx = $this->assertSession($token);
        $employeeId = (int)$ctx['employee_id'];
        $stores = $this->listSelectableStoresForMerchant($employeeId);
        $allowed = array_column($stores, 'store_id');
        if ($storeId <= 0 || !in_array($storeId, $allowed, true)) {
            throw new ValidateException('所选门店不在可切换范围内');
        }
        $now = time();
        $hash = $this->hashToken($token);
        Db::name('employee_merchant_session')
            ->where('token_hash', $hash)
            ->where('status', 1)
            ->where('is_del', 0)
            ->update([
                'store_id' => $storeId,
                'update_time' => $now,
            ]);
        $opened = $this->assertSession($token);
        return [
            'merchant_token' => $token,
            'employee_id' => $employeeId,
            'auth_version' => (int)$opened['auth_version'],
            'active_store_id' => $storeId,
            'store_id' => $storeId,
            'stores' => $stores,
        ];
    }

    /**
     * @return array<int, array{store_id:int,id:int,name:string,org_id:int}>
     */
    public function listSelectableStoresForMerchant(int $employeeId): array
    {
        $judge = $this->canShowMerchantEntry($employeeId);
        $storeIds = $judge['qualifying_store_ids'];
        if (!$storeIds) {
            return [];
        }
        $rows = Db::name('system_store')
            ->whereIn('id', $storeIds)
            ->where('is_del', 0)
            ->field('id,name')
            ->order('id', 'asc')
            ->select()->toArray() ?: [];
        $orgMap = Db::name('organization_store')
            ->whereIn('store_id', $storeIds)
            ->column('org_id', 'store_id');
        $list = [];
        foreach ($rows as $row) {
            $sid = (int)$row['id'];
            $list[] = [
                'store_id' => $sid,
                'id' => $sid,
                'name' => (string)$row['name'],
                'org_id' => (int)($orgMap[$sid] ?? 0),
            ];
        }
        return $list;
    }

    public function selectorForMerchant(int $employeeId, array $params): array
    {
        $judge = $this->canShowMerchantEntry($employeeId);
        if (!$judge['show_merchant_entry']) {
            throw new ValidateException($this->reasonMessage($judge['reason']));
        }
        $allowedStoreIds = $judge['qualifying_store_ids'];
        /** @var OrganizationResourceSelectorServices $sel */
        $sel = app()->make(OrganizationResourceSelectorServices::class);
        $result = $sel->searchScoped($params, $allowedStoreIds);
        $resource = (string)($result['resource'] ?? '');
        foreach ($result['list'] as &$row) {
            $id = (int)($row['id'] ?? $row['value'] ?? 0);
            if ($resource === 'organization') {
                $row['org_id'] = $id;
            } elseif ($resource === 'store') {
                $row['store_id'] = $id;
                if (!isset($row['org_id'])) {
                    $row['org_id'] = (int)Db::name('organization_store')->where('store_id', $id)->value('org_id');
                }
            } elseif ($resource === 'employee') {
                $row['employee_id'] = $id;
            }
        }
        unset($row);
        return $result;
    }

    /**
     * @return array{employee_id:int,auth_version:int,active_store_id:int,session:array,phone:string,qualifying_store_ids:int[]}
     */
    public function assertMerchantApiRequest($request): array
    {
        $token = $this->extractMerchantToken($request);
        if ($token === '') {
            throw new ValidateException('商家会话无效，请重新进入');
        }
        // 先归属，再 assertSession（过期/撤权继续拒绝）
        $owned = $this->assertMerchantTokenOwnedByRequest($token, $request);
        $emp = $owned['employee'];
        $phone = $this->resolveTrustedPhoneFromRequest($request);
        $ctx = $this->assertSession($token);
        return [
            'employee_id' => (int)$emp['id'],
            'auth_version' => (int)$ctx['auth_version'],
            'active_store_id' => (int)$ctx['active_store_id'],
            'session' => $ctx['session'],
            'phone' => $phone,
            'qualifying_store_ids' => $ctx['qualifying_store_ids'],
        ];
    }

    public function extractMerchantToken($request): string
    {
        $token = '';
        if (is_object($request)) {
            if (method_exists($request, 'header')) {
                $token = (string)$request->header(self::HEADER_TOKEN, '');
                if ($token === '') {
                    $token = (string)$request->header('Merchant-Token', '');
                }
            }
            if ($token === '' && method_exists($request, 'param')) {
                $token = (string)$request->param('merchant_token', '');
            }
        }
        return trim($token);
    }

    public function reasonMessage(string $reason): string
    {
        $map = [
            self::REASON_NO_PHONE => '请先完成可信手机号授权',
            self::REASON_NO_EMPLOYEE => '当前手机号未关联有效员工',
            self::REASON_EMPLOYEE_DISABLED => '员工已停用',
            self::REASON_NO_ASSIGNMENT => '无有效任职或组织直属关系',
            self::REASON_NO_MOBILE_ENTRY => '未开通手机端入口',
            self::REASON_NO_MOBILE_JOB => '当前岗位未包含手机端功能',
            self::REASON_SCHEMA => '商家会话结构未就绪',
            self::REASON_OK => '',
        ];
        return $map[$reason] ?? '暂无商家权限';
    }
}
