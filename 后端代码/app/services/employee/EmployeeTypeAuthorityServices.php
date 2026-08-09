<?php

namespace app\services\employee;

use app\services\BaseServices;
use app\services\organization\OrganizationStrictIdempotencyServices;
use app\services\organization\OrganizationWorkspaceWriteGate;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * 员工人员类型权威源。
 *
 * 类型属于员工主档，不属于某一次门店任职。旧 is_fencheng/is_hezuofang
 * 只保留兼容语义，禁止反向投影到本权威字段。
 */
class EmployeeTypeAuthorityServices extends BaseServices
{
    public const ACTION = 'employee_employment_type_save';
    public const AUTH_MANAGE = 'setting-staff-employment-type';

    public const TYPE_INTERNAL = 'internal';
    public const TYPE_PARTNER = 'partner';
    public const TYPE_OUTSOURCED = 'outsourced';

    /** @return string[] */
    public static function typeCodes(): array
    {
        return [self::TYPE_INTERNAL, self::TYPE_PARTNER, self::TYPE_OUTSOURCED];
    }

    /**
     * 独立保存入口。人员完整保存应调用 saveTypeInTx，避免嵌套事务和嵌套幂等。
     *
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveType(
        int $employeeId,
        $typeCode,
        $expectedVersion,
        array $adminInfo,
        array $requestCtx
    ): array {
        if ($employeeId <= 0) {
            throw new AdminException('员工不存在');
        }
        $typeCode = $this->normalizeTypeCode($typeCode);
        $expectedVersion = $this->normalizeExpectedVersion($expectedVersion);
        $this->assertManagePermission($adminInfo);

        if (empty($requestCtx['body_token']) && isset($requestCtx['request_token'])) {
            $requestCtx['body_token'] = trim((string)$requestCtx['request_token']);
        }
        $payload = [
            'employee_id' => $employeeId,
            'employment_type_code' => $typeCode,
            'employment_type_version' => $expectedVersion,
        ];

        /** @var OrganizationStrictIdempotencyServices $idempotency */
        $idempotency = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idempotency->run(
            self::ACTION,
            'employee:' . $employeeId . ':employment_type',
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use (
                $employeeId,
                $typeCode,
                $expectedVersion,
                $adminInfo
            ): array {
                return [
                    'msg' => '人员类型保存成功',
                    'data' => $this->saveTypeInTx(
                        $employeeId,
                        $typeCode,
                        $expectedVersion,
                        $adminInfo,
                        $auditMeta,
                        'hq'
                    ),
                ];
            },
            false
        );
    }

    /**
     * 必须由调用方事务包裹。
     *
     * @return array{employment_type_code:string,employment_type_version:int,changed:bool}
     */
    public function saveTypeInTx(
        int $employeeId,
        $typeCode,
        $expectedVersion,
        array $adminInfo,
        array $auditMeta,
        string $source = 'hq',
        int $staffId = 0,
        int $storeId = 0
    ): array {
        $this->assertInTransaction();
        if ($source === 'store') {
            $this->assertStoreManagePermission($adminInfo, $employeeId, $staffId, $storeId);
        } elseif ($source === 'hq') {
            $this->assertManagePermission($adminInfo);
        } else {
            throw new AdminException('人员类型来源无效');
        }
        $typeCode = $this->normalizeTypeCode($typeCode);
        $expectedVersion = $this->normalizeExpectedVersion($expectedVersion);

        $employee = Db::name('employee')
            ->where('id', $employeeId)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!$employee) {
            throw new AdminException('员工不存在');
        }

        [$currentCode, $currentVersion] = $this->assertStoredInvariant($employee);
        if ($expectedVersion !== $currentVersion) {
            throw new AdminException('人员类型已被其他操作修改，请刷新后重试');
        }
        if ($currentCode === $typeCode) {
            return [
                'employment_type_code' => $currentCode,
                'employment_type_version' => $currentVersion,
                'changed' => false,
            ];
        }
        if ($currentVersion >= PHP_INT_MAX) {
            throw new AdminException('人员类型版本无效，请联系管理员');
        }

        $nextVersion = $currentVersion + 1;
        $update = Db::name('employee')
            ->where('id', $employeeId)
            ->where('is_del', 0)
            ->where('employment_type_version', $currentVersion);
        if ($currentCode === null) {
            $update->whereNull('employment_type_code');
        } else {
            $update->where('employment_type_code', $currentCode);
        }
        $affected = $update->update([
            'employment_type_code' => $typeCode,
            'employment_type_version' => $nextVersion,
        ]);
        if ((int)$affected !== 1) {
            throw new AdminException('人员类型已被其他操作修改，请刷新后重试');
        }

        $before = [
            'employment_type_code' => $currentCode,
            'employment_type_version' => $currentVersion,
        ];
        $after = [
            'employment_type_code' => $typeCode,
            'employment_type_version' => $nextVersion,
        ];
        $beforeJson = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $afterJson = json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($beforeJson === false || $afterJson === false) {
            throw new AdminException('人员类型审计序列化失败');
        }
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => self::ACTION,
            'target_type' => 'employee_employment_type',
            'target_id' => $employeeId,
            'source' => $source === 'store' ? 'store' : 'admin',
            'before_data' => $beforeJson,
            'after_data' => $afterJson,
            'reason' => $source === 'store' ? '门店员工人员类型变更' : '员工人员类型变更',
            'operator_type' => 'admin',
            'operator_id' => (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0),
            'operator_name' => (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? ''),
            'operator_ip' => (string)($auditMeta['operator_ip'] ?? ''),
            'request_id' => (string)($auditMeta['request_id'] ?? ''),
            'add_time' => time(),
        ]);

        return [
            'employment_type_code' => $typeCode,
            'employment_type_version' => $nextVersion,
            'changed' => true,
        ];
    }

    /**
     * 总部人员详情使用的只读快照。门店接口必须 fail-closed。
     *
     * @return array{employment_type_code:?string,employment_type_version:int}
     */
    public function readSnapshot(int $employeeId, string $source = 'hq', int $staffId = 0, int $storeId = 0): array
    {
        if ($source === 'store') {
            $this->assertStoreTargetAssignment($employeeId, $staffId, $storeId);
        } elseif ($source !== 'hq') {
            throw new AdminException('人员类型来源无效');
        }
        $employee = Db::name('employee')
            ->where('id', $employeeId)
            ->where('is_del', 0)
            ->field('id,employment_type_code,employment_type_version')
            ->find();
        if (!$employee) {
            throw new AdminException('员工不存在');
        }
        [$code, $version] = $this->assertStoredInvariant($employee);
        return [
            'employment_type_code' => $code,
            'employment_type_version' => $version,
        ];
    }

    public function normalizeTypeCode($typeCode): string
    {
        if (!is_string($typeCode)) {
            throw new AdminException('人员类型无效');
        }
        $typeCode = trim($typeCode);
        if (!in_array($typeCode, self::typeCodes(), true)) {
            throw new AdminException('人员类型无效');
        }
        return $typeCode;
    }

    public function normalizeExpectedVersion($expectedVersion): int
    {
        if (is_bool($expectedVersion)
            || is_array($expectedVersion)
            || is_object($expectedVersion)
            || $expectedVersion === null
            || $expectedVersion === ''
        ) {
            throw new AdminException('人员类型版本无效，请刷新后重试');
        }
        if (is_int($expectedVersion)) {
            $version = $expectedVersion;
        } elseif (is_string($expectedVersion) && preg_match('/^\d+$/', $expectedVersion)) {
            $version = (int)$expectedVersion;
        } elseif (is_float($expectedVersion) && $expectedVersion === (float)(int)$expectedVersion) {
            $version = (int)$expectedVersion;
        } else {
            throw new AdminException('人员类型版本无效，请刷新后重试');
        }
        if ($version < 0) {
            throw new AdminException('人员类型版本无效，请刷新后重试');
        }
        return $version;
    }

    public function assertManagePermission(array $adminInfo): void
    {
        if (!array_key_exists('id', $adminInfo)
            || !array_key_exists('level', $adminInfo)
            || !array_key_exists('admin_type', $adminInfo)
        ) {
            throw new AdminException('无法确认登录身份，禁止修改人员类型');
        }
        $adminId = (int)$adminInfo['id'];
        if ($adminId <= 0 || (int)$adminInfo['admin_type'] === 3) {
            throw new AdminException('当前账号无人员类型管理权限');
        }

        /** @var OrganizationWorkspaceWriteGate $gate */
        $gate = app()->make(OrganizationWorkspaceWriteGate::class);
        $basePermission = $gate->assertPlatformStaffMaintainPermission($adminInfo);
        if (empty($basePermission['ok'])) {
            throw new AdminException((string)($basePermission['reason_text'] ?? '当前账号无人员类型管理权限'));
        }

        $admin = Db::name('system_admin')
            ->where('id', $adminId)
            ->where('is_del', 0)
            ->where('status', 1)
            ->find();
        if (!$admin || (int)($admin['admin_type'] ?? 0) === 3) {
            throw new AdminException('当前账号无人员类型管理权限');
        }
        if ((int)($admin['level'] ?? 1) === 0) {
            return;
        }

        $roleIds = array_values(array_filter(array_map('intval', explode(',', (string)($admin['roles'] ?? '')))));
        if (!$roleIds) {
            throw new AdminException('当前账号无人员类型管理权限');
        }
        /** @var \app\services\system\SystemMenusServices $menus */
        $menus = app()->make(\app\services\system\SystemMenusServices::class);
        [, $uniqueAuth] = $menus->getMenusList(
            $roleIds,
            (int)($admin['level'] ?? 1),
            1,
            (int)($admin['admin_type'] ?? 0)
        );
        if (!is_array($uniqueAuth) || !in_array(self::AUTH_MANAGE, $uniqueAuth, true)) {
            throw new AdminException('当前账号无人员类型管理权限');
        }
    }

    public function canManagePermission(array $adminInfo): bool
    {
        try {
            $this->assertManagePermission($adminInfo);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * 门店端只能通过已登录且在当前门店任职的员工，修改同一门店任职员工的类型。
     * 路由角色中间件仍负责“员工列表维护”功能权限；这里补齐服务层的门店边界。
     */
    public function assertStoreManagePermission(array $adminInfo, int $employeeId, int $staffId, int $storeId): void
    {
        $operatorStaffId = (int)($adminInfo['id'] ?? 0);
        if ($operatorStaffId <= 0 || $employeeId <= 0 || $staffId <= 0 || $storeId <= 0) {
            throw new AdminException('无法确认门店任职，禁止修改人员类型');
        }
        $operator = Db::name('system_store_staff')
            ->where('id', $operatorStaffId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->lock(true)->find();
        if (!$operator) {
            throw new AdminException('当前账号无本门店人员类型管理权限');
        }
        $this->assertStoreTargetAssignment($employeeId, $staffId, $storeId);
    }

    protected function assertStoreTargetAssignment(int $employeeId, int $staffId, int $storeId): void
    {
        if ($employeeId <= 0 || $staffId <= 0 || $storeId <= 0) {
            throw new AdminException('无法确认门店任职，禁止读取或修改人员类型');
        }
        $assignment = Db::name('system_store_staff')
            ->where('id', $staffId)->where('employee_id', $employeeId)->where('store_id', $storeId)
            ->where('is_del', 0)->lock(true)->find();
        if (!$assignment) {
            throw new AdminException('员工不属于当前门店');
        }
    }

    /** @return array{0:?string,1:int} */
    protected function assertStoredInvariant(array $employee): array
    {
        if (!array_key_exists('employment_type_code', $employee)
            || !array_key_exists('employment_type_version', $employee)
        ) {
            throw new AdminException('人员类型数据库升级未完成');
        }
        $rawCode = $employee['employment_type_code'];
        $version = (int)$employee['employment_type_version'];
        if ($rawCode === null && $version === 0) {
            return [null, 0];
        }
        if (!is_string($rawCode)
            || !in_array($rawCode, self::typeCodes(), true)
            || $version <= 0
        ) {
            throw new AdminException('人员类型数据异常，请联系管理员');
        }
        return [$rawCode, $version];
    }

    protected function assertInTransaction(): void
    {
        $connection = Db::connect();
        try {
            // Think-Swoole exposes the underlying connection through __call(),
            // so method_exists() incorrectly returns false for a live proxy.
            $pdo = $connection->getPdo();
        } catch (\Throwable $exception) {
            $pdo = null;
        }
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw new AdminException('人员类型保存必须在事务中执行');
        }
    }
}
