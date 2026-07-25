<?php
namespace app\services\employee;

use app\services\BaseServices;
use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\OrganizationEmployeeServices;
use app\services\organization\OrganizationStrictIdempotencyServices;
use app\services\organization\StaffJobPositionServices;
use app\services\store\SystemStoreStaffServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * 人员完整保存编排：employee + organization_employee + staff + 统一账号 + 岗位 + 数据权限
 * 单次幂等事务，禁止再调用会开 idem->run 的公开写入口
 */
class EmployeePersonCompleteWriteServices extends BaseServices
{
    public const ACTION = 'employee_person_complete_save';
    public const FAIL_INJECT_FLAG = 'ALLOW_PERSON_WRITE_FAIL_INJECT';

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveComplete(array $input, array $adminInfo, array $requestCtx, string $source): array
    {
        $source = $source === 'store' ? 'store' : 'hq';
        $this->assertNoLegacyFields($input);

        $employeeIdHint = (int)($input['employee_id'] ?? 0);
        $staffIdHint = (int)($input['staff_id'] ?? 0);
        if ($employeeIdHint <= 0 && $staffIdHint > 0) {
            $employeeIdHint = (int)Db::name('system_store_staff')
                ->where('id', $staffIdHint)->where('is_del', 0)->value('employee_id');
        }
        $scopeKey = 'employee:' . ($employeeIdHint > 0 ? $employeeIdHint : 'new') . ':' . $source;

        $positionIds = $this->normalizeIntIds($input['position_ids'] ?? []);
        $orgIds = $this->normalizeIntIds($input['org_ids'] ?? []);
        $storeIds = $this->normalizeIntIds($input['store_ids'] ?? []);
        $scopeMode = trim((string)($input['scope_mode'] ?? EmployeeDataScopeServices::MODE_PERSONAL));
        $storeId = (int)($input['store_id'] ?? 0);
        $orgId = (int)($input['org_id'] ?? 0);
        $account = trim((string)($input['account'] ?? ''));
        $pwd = (string)($input['pwd'] ?? '');
        $staffName = trim((string)($input['staff_name'] ?? $input['name'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $avatar = trim((string)($input['avatar'] ?? ''));

        $payload = [
            'employee_id' => $employeeIdHint,
            'staff_id' => $staffIdHint,
            'org_id' => $orgId,
            'store_id' => $storeId,
            'staff_name' => $staffName,
            'phone' => $phone,
            'avatar' => $avatar,
            'account' => $account,
            'pwd_set' => $pwd !== '' ? 1 : 0,
            'position_ids' => $positionIds,
            'scope_mode' => $scopeMode,
            'org_ids' => $orgIds,
            'store_ids' => $storeIds,
            'source' => $source,
            'can_choose' => (int)($input['can_choose'] ?? 1),
            'is_fencheng' => (int)($input['is_fencheng'] ?? 0),
        ];

        if (empty($requestCtx['body_token']) && isset($input['request_token'])) {
            $requestCtx['body_token'] = trim((string)$input['request_token']);
        }

        // 总部：人员保存按「人员维护」功能权限，不再要求 level=0 超管
        // 门店：不套平台人员维护校验，继续依赖幂等内写门禁 + 本店范围
        if ($source === 'hq') {
            /** @var \app\services\organization\OrganizationWorkspaceWriteGate $gate */
            $gate = app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
            $gate->assertCanWrite();
            $staffAuth = $gate->assertPlatformStaffMaintainPermission($adminInfo);
            if (!$staffAuth['ok']) {
                throw new AdminException($staffAuth['reason_text'] !== ''
                    ? $staffAuth['reason_text']
                    : '当前岗位未配置“人员维护”权限，请联系总部管理员授权。');
            }
        }

        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            self::ACTION,
            $scopeKey,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use (
                $input,
                $adminInfo,
                $source,
                $employeeIdHint,
                $staffIdHint,
                $orgId,
                $storeId,
                $staffName,
                $phone,
                $avatar,
                $account,
                $pwd,
                $positionIds,
                $scopeMode,
                $orgIds,
                $storeIds
            ) {
                return [
                    'msg' => '保存成功',
                    'data' => $this->doSaveCompleteInTx(
                        $input,
                        $adminInfo,
                        $auditMeta,
                        $source,
                        $employeeIdHint,
                        $staffIdHint,
                        $orgId,
                        $storeId,
                        $staffName,
                        $phone,
                        $avatar,
                        $account,
                        $pwd,
                        $positionIds,
                        $scopeMode,
                        $orgIds,
                        $storeIds
                    ),
                ];
            },
            false
        );
    }

    /**
     * 编辑回显
     */
    public function getComplete(int $employeeId, int $staffId = 0, string $source = 'hq'): array
    {
        $source = $source === 'store' ? 'store' : 'hq';
        if ($employeeId <= 0 && $staffId > 0) {
            $employeeId = (int)Db::name('system_store_staff')
                ->where('id', $staffId)->where('is_del', 0)->value('employee_id');
        }
        if ($employeeId <= 0) {
            throw new AdminException('员工不存在');
        }
        $emp = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->find();
        if (!$emp) {
            throw new AdminException('员工不存在');
        }

        $staff = null;
        if ($staffId > 0) {
            $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->find();
            if (!$staff || (int)($staff['employee_id'] ?? 0) !== $employeeId) {
                throw new AdminException('任职与员工不匹配');
            }
        } else {
            $staff = Db::name('system_store_staff')
                ->where('employee_id', $employeeId)
                ->where('is_del', 0)
                ->where('store_id', '>', 0)
                ->where('status', 1)
                ->order('id', 'desc')
                ->find();
            $staffId = $staff ? (int)$staff['id'] : 0;
        }

        $storeId = $staff ? (int)$staff['store_id'] : 0;
        $oe = Db::name('organization_employee')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->order('id', 'desc')
            ->find();
        $orgId = $oe ? (int)$oe['org_id'] : 0;
        if ($orgId <= 0 && $storeId > 0) {
            $orgId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        }

        /** @var EmployeeInternalAccountServices $acctSvc */
        $acctSvc = app()->make(EmployeeInternalAccountServices::class);
        $acct = $acctSvc->getByEmployeeId($employeeId);

        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        $jobs = $staffId > 0
            ? $jobSvc->listActiveJobs($staffId)
            : $jobSvc->listActiveJobs(0, $employeeId);
        $positionIds = array_values(array_map(static function ($j) {
            return (int)($j['position_id'] ?? 0);
        }, $jobs));

        /** @var EmployeeDataScopeServices $scopeSvc */
        $scopeSvc = app()->make(EmployeeDataScopeServices::class);
        $forStore = $source === 'store' ? $storeId : 0;
        $scopes = $scopeSvc->listScopes($employeeId, $forStore);
        $scope = null;
        foreach ($scopes as $sc) {
            if ($source === 'store') {
                if ((string)($sc['source_type'] ?? '') === EmployeeDataScopeServices::SOURCE_STORE
                    && (int)($sc['source_store_id'] ?? 0) === $storeId) {
                    $scope = $sc;
                    break;
                }
            } else {
                if ((string)($sc['source_type'] ?? '') === EmployeeDataScopeServices::SOURCE_HQ) {
                    $scope = $sc;
                    break;
                }
            }
        }
        if ($scope === null) {
            $scope = [
                'scope_mode' => EmployeeDataScopeServices::MODE_PERSONAL,
                'org_ids' => [],
                'store_ids' => [],
                'source_type' => $source === 'store' ? EmployeeDataScopeServices::SOURCE_STORE : EmployeeDataScopeServices::SOURCE_HQ,
                'source_store_id' => $source === 'store' ? $storeId : 0,
            ];
        }
        if ((string)($scope['scope_mode'] ?? '') === EmployeeDataScopeServices::MODE_STORE) {
            $scope['store_ids'] = $scopeSvc->resolveCurrentActiveStoreIds($employeeId);
            $scope['store_ids_auto'] = true;
        }
        if ((string)($scope['scope_mode'] ?? '') === EmployeeDataScopeServices::MODE_ORG) {
            $oids = array_values(array_filter(array_map('intval', (array)($scope['org_ids'] ?? []))));
            $names = $oids
                ? Db::name('organization')->whereIn('id', $oids)->where('is_del', 0)->column('name', 'id')
                : [];
            $scope['org_labels'] = [];
            foreach ($oids as $oid) {
                $scope['org_labels'][] = [
                    'id' => $oid,
                    'name' => (string)($names[$oid] ?? ('#' . $oid)),
                ];
            }
        }

        return [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'org_id' => $orgId,
            'store_id' => $storeId,
            'org_employee_id' => $oe ? (int)$oe['id'] : 0,
            'staff_name' => (string)($emp['name'] ?? ''),
            'phone' => (string)($emp['phone'] ?? ''),
            'avatar' => (string)($emp['avatar'] ?? ''),
            'account' => $acct ? (string)$acct['account'] : '',
            'position_ids' => $positionIds,
            'jobs' => $jobs,
            'scope' => $scope,
            'can_choose' => $staff ? (int)($staff['can_choose'] ?? 1) : 1,
            'is_fencheng' => $staff ? (int)($staff['is_fencheng'] ?? 0) : 0,
            'status' => (int)($emp['status'] ?? 1),
        ];
    }

    /**
     * @param int[] $positionIds
     * @param int[] $orgIds
     * @param int[] $storeIds
     */
    protected function doSaveCompleteInTx(
        array $input,
        array $adminInfo,
        array $auditMeta,
        string $source,
        int $employeeIdHint,
        int $staffIdHint,
        int $orgId,
        int $storeId,
        string $staffName,
        string $phone,
        string $avatar,
        string $account,
        string $pwd,
        array $positionIds,
        string $scopeMode,
        array $orgIds,
        array $storeIds
    ): array {
        /** @var EmployeeStaffWriteServices $staffWrite */
        $staffWrite = app()->make(EmployeeStaffWriteServices::class);
        $phone = $staffWrite->assertStrictPhone($phone);
        if ($staffName === '') {
            throw new AdminException('请填写员工姓名');
        }
        if ($avatar === '') {
            $avatar = EmployeeStaffWriteServices::DEFAULT_AVATAR;
        }

        if ($source === 'store') {
            if ($storeId <= 0) {
                throw new AdminException('请选择门店');
            }
            if ($scopeMode === EmployeeDataScopeServices::MODE_ORG) {
                throw new AdminException('门店不能设置组织数据范围');
            }
            if (!in_array($scopeMode, [
                EmployeeDataScopeServices::MODE_PERSONAL,
                EmployeeDataScopeServices::MODE_STORE_SELF,
            ], true)) {
                throw new AdminException('门店只能设置个人或本店数据范围');
            }
            if ($orgId <= 0) {
                $orgId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
            }
        } else {
            if ($orgId <= 0) {
                throw new AdminException('请选择所属组织');
            }
            if ($storeId < 0) {
                throw new AdminException('门店无效');
            }
            // 基本信息：选了门店时，所属组织以门店真实归属为准（禁止门店 ID 写入 org_id，禁止错绑）
            if ($storeId > 0) {
                $realOrgId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
                if ($realOrgId <= 0) {
                    throw new AdminException('所选门店未归属任何组织，请先完成门店组织绑定');
                }
                // 校验门店存在且未删除
                $storeOk = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->find();
                if (!$storeOk) {
                    throw new AdminException('所选门店无效或已删除');
                }
                if ($orgId !== $realOrgId) {
                    $orgId = $realOrgId;
                }
            } else {
                // 无店：org_id 必须是组织
                $orgOk = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->find();
                if (!$orgOk) {
                    throw new AdminException('所属组织无效或已删除');
                }
            }
            if ($scopeMode === '') {
                $scopeMode = EmployeeDataScopeServices::MODE_PERSONAL;
            }
            if (!in_array($scopeMode, [
                EmployeeDataScopeServices::MODE_PERSONAL,
                EmployeeDataScopeServices::MODE_ORG,
                EmployeeDataScopeServices::MODE_STORE,
            ], true)) {
                throw new AdminException('总部数据范围类型无效');
            }
        }
        if ($orgId <= 0) {
            throw new AdminException('请选择所属组织');
        }

        $opCtx = [
            'operator_id' => (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0),
            'operator_name' => (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? ''),
            'operator_ip' => (string)($auditMeta['operator_ip'] ?? ''),
            'request_id' => (string)($auditMeta['request_id'] ?? ''),
            'source' => $source === 'store' ? 'store' : 'admin',
            'reason' => '人员完整保存',
            'allow_profile_update' => true,
        ];

        // 1) employee
        $this->maybeFail('employee', $input);
        $employeeId = $this->upsertEmployee($employeeIdHint, $phone, $staffName, $avatar, $opCtx);

        // 2) organization_employee
        $this->maybeFail('org_employee', $input);
        /** @var OrganizationEmployeeServices $oeSvc */
        $oeSvc = app()->make(OrganizationEmployeeServices::class);
        $oeRet = $oeSvc->save([
            'org_id' => $orgId,
            'employee_id' => $employeeId,
            'job_title' => trim((string)($input['job_title'] ?? '')),
            'status' => 1,
        ], $opCtx, ['use_outer_transaction' => true]);
        $orgEmployeeId = (int)($oeRet['id'] ?? 0);

        // 3) staff（仅 store_id>0；禁止 store_id=0 伪造任职）
        $staffId = 0;
        if ($storeId > 0) {
            $this->maybeFail('staff', $input);
            if ($staffIdHint > 0) {
                $existStaff = Db::name('system_store_staff')->where('id', $staffIdHint)->where('is_del', 0)->find();
                if (!$existStaff) {
                    throw new AdminException('店员不存在');
                }
                if ((int)($existStaff['employee_id'] ?? 0) > 0
                    && (int)$existStaff['employee_id'] !== $employeeId) {
                    throw new AdminException('任职与员工不匹配');
                }
                $staffId = $staffIdHint;
            } else {
                $existStaff = Db::name('system_store_staff')
                    ->where('employee_id', $employeeId)
                    ->where('store_id', $storeId)
                    ->where('is_del', 0)
                    ->find();
                if ($existStaff) {
                    $staffId = (int)$existStaff['id'];
                }
            }

            $staffPayload = [
                'store_id' => $storeId,
                'staff_name' => $staffName,
                'phone' => $phone,
                'avatar' => $avatar,
                'status' => (int)($input['status'] ?? 1) === 1 ? 1 : 0,
                'can_choose' => (int)($input['can_choose'] ?? 1) === 1 ? 1 : 0,
                'is_fencheng' => (int)($input['is_fencheng'] ?? 0) === 1 ? 1 : 0,
                'is_reservable' => array_key_exists('is_reservable', $input)
                    ? ((int)$input['is_reservable'] === 1 ? 1 : 0) : 1,
                'verify_status' => array_key_exists('verify_status', $input)
                    ? ((int)$input['verify_status'] === 1 ? 1 : 0) : 1,
                'is_cashier' => (int)($input['is_cashier'] ?? 0) === 1 ? 1 : 0,
                'is_customer' => (int)($input['is_customer'] ?? 0) === 1 ? 1 : 0,
                'salary_status' => array_key_exists('salary_status', $input)
                    ? ((int)$input['salary_status'] === 1 ? 1 : 0) : 1,
                'account' => $account,
            ];
            if ($source === 'store') {
                unset(
                    $staffPayload['can_choose'],
                    $staffPayload['is_fencheng'],
                    $staffPayload['is_reservable'],
                    $staffPayload['verify_status'],
                    $staffPayload['is_cashier'],
                    $staffPayload['is_customer']
                );
            }
            $ret = $staffWrite->saveStaffAssignment($staffId, $staffPayload, $opCtx, [
                'use_outer_transaction' => true,
            ]);
            $staffId = (int)$ret['staff_id'];
            $employeeId = (int)$ret['employee_id'];
        } elseif ($staffIdHint > 0) {
            throw new AdminException('无店直属不可绑定伪造门店任职');
        }

        // 4) 统一内部账号
        if ($account !== '') {
            $this->maybeFail('account', $input);
            /** @var EmployeeInternalAccountServices $acctSvc */
            $acctSvc = app()->make(EmployeeInternalAccountServices::class);
            $isNewAccount = !$acctSvc->getByEmployeeId($employeeId);
            if ($isNewAccount && $pwd === '') {
                throw new AdminException('请设置登录密码');
            }
            $acctSvc->saveAccount(
                $employeeId,
                $account,
                $pwd !== '' ? $pwd : null,
                1,
                $opCtx,
                ['use_outer_transaction' => true]
            );
        } else {
            $acct = app()->make(EmployeeInternalAccountServices::class)->getByEmployeeId($employeeId);
            $account = $acct ? (string)$acct['account'] : '';
        }

        // 5) 岗位
        $this->maybeFail('jobs', $input);
        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        $jobsRet = $jobSvc->bindJobsInTx(
            $employeeId,
            $staffId,
            $storeId > 0 ? $storeId : 0,
            $positionIds,
            $adminInfo,
            $auditMeta,
            $source,
            false
        );
        if ($staffId > 0) {
            $jobSvc->projectStaffRoles($staffId);
            $jobSvc->syncManagerFlagFromJobs($staffId);
        }
        $jobSvc->projectPlatformAdminRoles($employeeId);
        $jobSvc->bumpEmployeeAuthVersion($employeeId);
        $jobSvc->projectMobileAuthRules($employeeId);
        $jobSvc->invalidateMerchantSessions($employeeId);

        // 6) 数据权限
        $this->maybeFail('scope', $input);
        /** @var EmployeeDataScopeServices $scopeSvc */
        $scopeSvc = app()->make(EmployeeDataScopeServices::class);
        $scopeData = [
            'source_type' => $source === 'store'
                ? EmployeeDataScopeServices::SOURCE_STORE
                : EmployeeDataScopeServices::SOURCE_HQ,
            'source_store_id' => $source === 'store' ? $storeId : 0,
            'scope_mode' => $scopeMode,
            'org_ids' => $orgIds,
            'store_ids' => $storeIds,
            'appointment_store_id' => $storeId,
        ];
        $scope = $scopeSvc->saveScopeInTx($employeeId, $scopeData, $adminInfo, $auditMeta);
        // 门店模式回显：把当前任职门店写入返回的 store_ids（仅展示，非人工可编辑范围）
        if (($scope['scope_mode'] ?? '') === EmployeeDataScopeServices::MODE_STORE) {
            $scope['store_ids'] = $scopeSvc->resolveCurrentActiveStoreIds($employeeId) ?: ($storeId > 0 ? [$storeId] : []);
            $scope['store_ids_auto'] = true;
        }

        // 6b) 可选渠道入口（同事务，避免 staffAuthSave 半成功）
        $entriesOut = null;
        if ($staffId > 0 && array_key_exists('entries', $input) && is_array($input['entries'])) {
            $normalized = $jobSvc->normalizeChannelEntries($input['entries'], $source);
            if ($normalized) {
                $entriesOut = $jobSvc->bindChannelEntriesInTx(
                    $employeeId,
                    $staffId,
                    $normalized,
                    $adminInfo,
                    $auditMeta,
                    $source,
                    false
                );
            }
        }

        // 7) 编排审计
        $this->maybeFail('audit', $input);
        $out = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'org_id' => $orgId,
            'store_id' => $storeId > 0 ? $storeId : 0,
            'org_employee_id' => $orgEmployeeId,
            'account' => $account,
            'position_ids' => $positionIds,
            'jobs' => $jobsRet['jobs'] ?? ($staffId > 0
                ? $jobSvc->listActiveJobs($staffId)
                : $jobSvc->listActiveJobs(0, $employeeId)),
            'scope' => $scope,
        ];
        if ($entriesOut !== null) {
            $out['entries'] = $entriesOut['entries'] ?? [];
        }
        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new AdminException('审计序列化失败');
        }
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => self::ACTION,
            'target_type' => 'employee',
            'target_id' => $employeeId,
            'source' => $source === 'store' ? 'store' : 'admin',
            'before_data' => '',
            'after_data' => $json,
            'reason' => '人员完整保存',
            'operator_type' => 'admin',
            'operator_id' => (int)($auditMeta['operator_id'] ?? 0),
            'operator_name' => (string)($auditMeta['operator_name'] ?? ''),
            'operator_ip' => (string)($auditMeta['operator_ip'] ?? ''),
            'request_id' => (string)($auditMeta['request_id'] ?? ''),
            'add_time' => time(),
        ]);

        return $out;
    }

    protected function upsertEmployee(
        int $employeeId,
        string $phone,
        string $name,
        string $avatar,
        array $opCtx
    ): int {
        $now = time();
        $avatarType = strpos($avatar, 'http') === 0 ? 2 : 1;
        if ($employeeId > 0) {
            $emp = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
            if (!$emp || (int)($emp['is_del'] ?? 0) === 1) {
                throw new AdminException('员工不存在');
            }
            $owner = Db::name('employee')->where('phone', $phone)->where('id', '<>', $employeeId)->lock(true)->find();
            if ($owner) {
                throw new AdminException('该手机号已被其他员工使用');
            }
            Db::name('employee')->where('id', $employeeId)->update([
                'name' => $name,
                'phone' => $phone,
                'avatar' => $avatar,
                'avatar_type' => $avatarType,
                'update_time' => $now,
            ]);
            return $employeeId;
        }

        $exist = Db::name('employee')->where('phone', $phone)->lock(true)->find();
        if ($exist) {
            if ((int)($exist['is_del'] ?? 0) === 1) {
                throw new AdminException('该手机号对应员工已删除');
            }
            $employeeId = (int)$exist['id'];
            Db::name('employee')->where('id', $employeeId)->update([
                'name' => $name,
                'phone' => $phone,
                'avatar' => $avatar,
                'avatar_type' => $avatarType,
                'update_time' => $now,
            ]);
            return $employeeId;
        }

        return (int)Db::name('employee')->insertGetId([
            'name' => $name,
            'phone' => $phone,
            'avatar' => $avatar,
            'avatar_type' => $avatarType,
            'uid' => null,
            'status' => 1,
            'is_del' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }

    protected function assertNoLegacyFields(array $input): void
    {
        SystemStoreStaffServices::assertNoLegacyStaffAuthBypass($input);
        if (array_key_exists('position', $input) && (int)$input['position'] !== 0) {
            throw new AdminException('职位字段已停用，请改用岗位');
        }
        if (array_key_exists('position_level', $input) && (int)$input['position_level'] !== 0) {
            throw new AdminException('职级字段已停用，请改用岗位');
        }
    }

    protected function maybeFail(string $at, array $input): void
    {
        $want = trim((string)($input['__fail_at'] ?? ''));
        if ($want === '' || $want !== $at) {
            return;
        }
        $flag = root_path() . 'runtime/' . self::FAIL_INJECT_FLAG;
        if (!is_file($flag)) {
            return;
        }
        throw new AdminException('FAIL_INJECT:' . $at);
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    protected function normalizeIntIds($raw): array
    {
        if (!is_array($raw)) {
            $raw = $raw !== '' && $raw !== null ? explode(',', (string)$raw) : [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
        sort($ids);
        return $ids;
    }
}
