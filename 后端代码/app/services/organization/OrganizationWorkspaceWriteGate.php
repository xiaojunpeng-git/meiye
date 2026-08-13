<?php
namespace app\services\organization;

use think\facade\Db;

/**
 * O4 组织工作台写门禁（fail-closed）
 * - 配置直读 system_config（平台 is_store=0），绕过 sys_config 缓存
 * - 不用于 OrganizationManageServices 底层内部调用
 */
class OrganizationWorkspaceWriteGate
{
    public const CFG_WRITE_ENABLED = 'organization_workspace_write_enabled';
    public const CFG_ALLOW_LEGACY = 'organization_workspace_write_allow_legacy';

    public const REASON_WRITE_DISABLED = 'WRITE_DISABLED';
    public const REASON_LEGACY_BLOCKED = 'LEGACY_WRITE_BLOCKED';
    public const REASON_NOT_SUPER_ADMIN = 'NOT_SUPER_ADMIN';
    public const REASON_IDENTITY_UNKNOWN = 'IDENTITY_UNKNOWN';
    public const REASON_NO_STAFF_MAINTAIN = 'NO_STAFF_MAINTAIN';
    public const REASON_ORG_SCOPE = 'ORG_SCOPE_DENIED';
    public const REASON_OK = 'OK';

    /** 人员维护功能权限标识（与前端 v-auth 一致） */
    public const AUTH_STAFF_MAINTAIN = 'setting-staff-index';

    /**
     * 读取平台门禁配置（恰好一条才 ok）
     * @return array{value:int|null,ok:bool,count:int}
     */
    public function readConfigFlag(string $menuName): array
    {
        try {
            $query = Db::name('system_config')
                ->where('menu_name', $menuName)
                ->where('is_store', 0);
            if ($this->systemConfigHasRelationId()) {
                $query->where('relation_id', 0);
            }
            $rows = $query->field('id,value')->select()->toArray();
            $count = count($rows);
            if ($count !== 1) {
                return ['value' => null, 'ok' => false, 'count' => $count];
            }
            $raw = $rows[0]['value'] ?? null;
            if ($raw === null || $raw === '') {
                return ['value' => null, 'ok' => false, 'count' => $count];
            }
            $decoded = json_decode((string)$raw, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $decoded = $raw;
            }
            if (is_bool($decoded)) {
                $decoded = $decoded ? 1 : 0;
            }
            if (is_int($decoded) || is_float($decoded)) {
                $s = (string)(int)$decoded;
            } else {
                $s = trim((string)$decoded);
            }
            if ($s !== '0' && $s !== '1') {
                return ['value' => null, 'ok' => false, 'count' => $count];
            }
            return ['value' => (int)$s, 'ok' => true, 'count' => $count];
        } catch (\Throwable $e) {
            return ['value' => null, 'ok' => false, 'count' => -1];
        }
    }

    /**
     * relation_id 仅在存在时约束；不永久缓存 false
     */
    protected function systemConfigHasRelationId(): bool
    {
        static $positive = false;
        if ($positive) {
            return true;
        }
        try {
            $cols = Db::query("SHOW COLUMNS FROM `eb_system_config` LIKE 'relation_id'");
            $positive = !empty($cols);
            return $positive;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isWriteEnabled(): bool
    {
        $f = $this->readConfigFlag(self::CFG_WRITE_ENABLED);
        return $f['ok'] && (int)$f['value'] === 1;
    }

    public function isLegacyWriteAllowed(): bool
    {
        $f = $this->readConfigFlag(self::CFG_ALLOW_LEGACY);
        return $f['ok'] && (int)$f['value'] === 1;
    }

    /**
     * 必须同时明确存在 id、level、admin_type；缺一即 IDENTITY_UNKNOWN
     * @param array $adminInfo 会话管理员
     * @return array{ok:bool,reason_code:string,reason_text:string}
     */
    public function assertSuperAdmin(array $adminInfo): array
    {
        if (!is_array($adminInfo)
            || !array_key_exists('id', $adminInfo)
            || !array_key_exists('level', $adminInfo)
            || !array_key_exists('admin_type', $adminInfo)
        ) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_IDENTITY_UNKNOWN,
                'reason_text' => '无法确认登录身份，禁止写入',
            ];
        }
        if (!$this->isNonNegativeIntLike($adminInfo['id'])
            || !$this->isIntLike($adminInfo['level'])
            || !$this->isIntLike($adminInfo['admin_type'])
        ) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_IDENTITY_UNKNOWN,
                'reason_text' => '无法确认登录身份，禁止写入',
            ];
        }
        $id = (int)$adminInfo['id'];
        $level = (int)$adminInfo['level'];
        $adminType = (int)$adminInfo['admin_type'];
        if ($id <= 0) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_IDENTITY_UNKNOWN,
                'reason_text' => '无法确认登录身份，禁止写入',
            ];
        }
        if ($level !== 0 || $adminType === 3) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_NOT_SUPER_ADMIN,
                'reason_text' => '无权限执行该操作',
            ];
        }
        return ['ok' => true, 'reason_code' => self::REASON_OK, 'reason_text' => ''];
    }

    /**
     * @param mixed $v
     */
    protected function isIntLike($v): bool
    {
        if (is_bool($v) || is_array($v) || is_object($v) || $v === null || $v === '') {
            return false;
        }
        if (is_int($v)) {
            return true;
        }
        if (is_float($v)) {
            return $v === (float)(int)$v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            return true;
        }
        return false;
    }

    /**
     * @param mixed $v
     */
    protected function isNonNegativeIntLike($v): bool
    {
        return $this->isIntLike($v) && (int)$v >= 0;
    }

    public function isSuperAdmin(array $adminInfo): bool
    {
        return $this->assertSuperAdmin($adminInfo)['ok'];
    }

    /**
     * 平台人员新建/编辑保存权限：岗位功能权限，不要求 level=0。
     * - 账号须有效、未删除
     * - 仅平台后台账号；admin_type=3 代理拒绝
     * - 须具备 setting-staff-index（角色 rules → menus.unique_auth）
     * - 禁止信任前端传来的角色/权限字段
     *
     * @return array{ok:bool,reason_code:string,reason_text:string}
     */
    public function assertPlatformStaffMaintainPermission(array $adminInfo): array
    {
        if (!is_array($adminInfo)
            || !array_key_exists('id', $adminInfo)
            || !array_key_exists('level', $adminInfo)
            || !array_key_exists('admin_type', $adminInfo)
        ) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_IDENTITY_UNKNOWN,
                'reason_text' => '无法确认登录身份，禁止写入',
            ];
        }
        if (!$this->isNonNegativeIntLike($adminInfo['id'])
            || !$this->isIntLike($adminInfo['level'])
            || !$this->isIntLike($adminInfo['admin_type'])
        ) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_IDENTITY_UNKNOWN,
                'reason_text' => '无法确认登录身份，禁止写入',
            ];
        }
        $id = (int)$adminInfo['id'];
        $adminType = (int)$adminInfo['admin_type'];
        if ($id <= 0) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_IDENTITY_UNKNOWN,
                'reason_text' => '无法确认登录身份，禁止写入',
            ];
        }
        if ($adminType === 3) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_NO_STAFF_MAINTAIN,
                'reason_text' => '当前岗位未配置“人员维护”权限，请联系总部管理员授权。',
            ];
        }

        $row = Db::name('system_admin')->where('id', $id)->where('is_del', 0)->find();
        if (!$row) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_IDENTITY_UNKNOWN,
                'reason_text' => '无法确认登录身份，禁止写入',
            ];
        }
        if ((int)($row['status'] ?? 0) !== 1) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_IDENTITY_UNKNOWN,
                'reason_text' => '账号已停用，禁止写入',
            ];
        }
        if ((int)($row['admin_type'] ?? 0) === 3) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_NO_STAFF_MAINTAIN,
                'reason_text' => '当前岗位未配置“人员维护”权限，请联系总部管理员授权。',
            ];
        }

        $level = (int)($row['level'] ?? 1);
        // level=0 超管：平台菜单全集，具备人员维护
        if ($level === 0) {
            return ['ok' => true, 'reason_code' => self::REASON_OK, 'reason_text' => ''];
        }

        $roleIds = array_values(array_filter(array_map('intval', explode(',', (string)($row['roles'] ?? '')))));
        if (!$roleIds) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_NO_STAFF_MAINTAIN,
                'reason_text' => '当前岗位未配置“人员维护”权限，请联系总部管理员授权。',
            ];
        }

        /** @var \app\services\system\SystemMenusServices $menusSvc */
        $menusSvc = app()->make(\app\services\system\SystemMenusServices::class);
        [, $uniqueAuth] = $menusSvc->getMenusList($roleIds, $level, 1, (int)($row['admin_type'] ?? 0));
        $uniqueAuth = is_array($uniqueAuth) ? $uniqueAuth : [];
        $ok = in_array(self::AUTH_STAFF_MAINTAIN, $uniqueAuth, true);
        if (!$ok) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_NO_STAFF_MAINTAIN,
                'reason_text' => '当前岗位未配置“人员维护”权限，请联系总部管理员授权。',
            ];
        }
        return ['ok' => true, 'reason_code' => self::REASON_OK, 'reason_text' => ''];
    }

    /**
     * 人员维护权限之外，还必须校验目标组织在操作者的数据权限范围内。
     * 总部超管返回通过；普通“集团/组织”权限按组织及下级范围判断。
     *
     * @return array{ok:bool,reason_code:string,reason_text:string}
     */
    public function assertOrganizationManagePermission(int $orgId, array $adminInfo): array
    {
        if ($orgId <= 0) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_ORG_SCOPE,
                'reason_text' => '目标组织无效，禁止写入',
            ];
        }
        $staff = $this->assertPlatformStaffMaintainPermission($adminInfo);
        if (!$staff['ok']) {
            return $staff;
        }
        if ($this->isSuperAdmin($adminInfo)) {
            return ['ok' => true, 'reason_code' => self::REASON_OK, 'reason_text' => ''];
        }

        /** @var EmployeeDataScopeServices $scopeService */
        $scopeService = app()->make(EmployeeDataScopeServices::class);
        $allowed = $scopeService->resolveOperatorManageableOrgIds($adminInfo);
        if ($allowed === null || in_array($orgId, $allowed, true)) {
            return ['ok' => true, 'reason_code' => self::REASON_OK, 'reason_text' => ''];
        }
        return [
            'ok' => false,
            'reason_code' => self::REASON_ORG_SCOPE,
            'reason_text' => '超出当前账号的数据权限范围，不能维护该组织人员',
        ];
    }

    /**
     * @return array{ok:bool,reason_code:string,reason_text:string,source_mode:string,write_enabled:bool}
     */
    public function evaluateWriteGate(): array
    {
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        $sourceMode = (string)$scope->getSourceMode();

        $enabledFlag = $this->readConfigFlag(self::CFG_WRITE_ENABLED);
        if (!$enabledFlag['ok'] || (int)$enabledFlag['value'] !== 1) {
            return [
                'ok' => false,
                'reason_code' => self::REASON_WRITE_DISABLED,
                'reason_text' => '当前为组织架构只读阶段，写入未开放',
                'source_mode' => $sourceMode,
                'write_enabled' => false,
            ];
        }

        if ($sourceMode === OrganizationScopeService::MODE_LEGACY) {
            $allowLegacy = $this->readConfigFlag(self::CFG_ALLOW_LEGACY);
            if (!$allowLegacy['ok'] || (int)$allowLegacy['value'] !== 1) {
                return [
                    'ok' => false,
                    'reason_code' => self::REASON_LEGACY_BLOCKED,
                    'reason_text' => '当前数据源模式下禁止组织写入',
                    'source_mode' => $sourceMode,
                    'write_enabled' => true,
                ];
            }
        }

        return [
            'ok' => true,
            'reason_code' => self::REASON_OK,
            'reason_text' => '',
            'source_mode' => $sourceMode,
            'write_enabled' => true,
        ];
    }

    /**
     * @throws \Exception
     */
    public function assertCanWrite(): void
    {
        $gate = $this->evaluateWriteGate();
        if (!$gate['ok']) {
            throw new \Exception($gate['reason_text']);
        }
    }

    /**
     * @return array{write_enabled:bool,can_write:bool,reason_code:string,reason_text:string,source_mode:string,is_super_admin:bool}
     */
    public function getWriteStatus(array $adminInfo): array
    {
        $gate = $this->evaluateWriteGate();
        $super = $this->assertSuperAdmin($adminInfo);
        $canWrite = $gate['ok'] && $super['ok'];
        $reasonCode = self::REASON_OK;
        $reasonText = '';
        if (!$gate['ok']) {
            $reasonCode = $gate['reason_code'];
            $reasonText = $gate['reason_text'];
        } elseif (!$super['ok']) {
            $reasonCode = $super['reason_code'];
            $reasonText = $super['reason_text'];
        }
        return [
            'write_enabled' => (bool)$gate['write_enabled'],
            'can_write' => $canWrite,
            'reason_code' => $reasonCode,
            'reason_text' => $reasonText,
            'source_mode' => (string)$gate['source_mode'],
            'is_super_admin' => (bool)$super['ok'],
        ];
    }
}
