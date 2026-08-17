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
    public const ACTION_DEFAULT_INTERNAL = 'employee_employment_type_default_internal';
    public const FAIL_INJECT_FLAG = 'ALLOW_PERSON_WRITE_FAIL_INJECT';

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveComplete(array $input, array $adminInfo, array $requestCtx, string $source): array
    {
        $source = $source === 'store' ? 'store' : 'hq';
        $this->assertNoLegacyFields($input);

        $typeCodePresent = array_key_exists('employment_type_code', $input);
        $typeVersionPresent = array_key_exists('employment_type_version', $input);
        if ($typeCodePresent !== $typeVersionPresent) {
            throw new AdminException('人员类型与版本必须同时提交');
        }
        $employmentTypeCode = null;
        $employmentTypeVersion = null;
        if ($typeCodePresent) {
            /** @var EmployeeTypeAuthorityServices $typeAuthority */
            $typeAuthority = app()->make(EmployeeTypeAuthorityServices::class);
            $employmentTypeCode = $typeAuthority->normalizeTypeCode($input['employment_type_code']);
            $employmentTypeVersion = $typeAuthority->normalizeExpectedVersion($input['employment_type_version']);
            if ($source === 'hq') {
                $typeAuthority->assertManagePermission($adminInfo);
            }
        }

        $employeeIdHint = (int)($input['employee_id'] ?? 0);
        $staffIdHint = (int)($input['staff_id'] ?? 0);
        if ($employeeIdHint <= 0 && $staffIdHint > 0) {
            $employeeIdHint = (int)Db::name('system_store_staff')
                ->where('id', $staffIdHint)->where('is_del', 0)->value('employee_id');
        }
        $scopeKey = 'employee:' . ($employeeIdHint > 0 ? $employeeIdHint : 'new') . ':' . $source;

        $positionIdsPresent = array_key_exists('position_ids', $input);
        $positionIds = $positionIdsPresent
            ? $this->normalizeIntIds($input['position_ids'])
            : [];
        // 编辑页会提交完整表单。岗位集合未变化时不能重投影旧任职权限；
        // 对尚未迁入岗位策略的历史任职，空回显同样表示“未修改”。
        if ($positionIdsPresent && $staffIdHint > 0) {
            $boundPositionIds = Db::name('staff_job_position')
                ->where('staff_id', $staffIdHint)->where('is_del', 0)
                ->where('status', 1)->where('end_time', 0)
                ->column('position_id');
            $boundPositionIds = $this->normalizeIntIds($boundPositionIds ?: []);
            if ($positionIds === $boundPositionIds) {
                $positionIdsPresent = false;
            } elseif ($positionIds === []) {
                $legacyProjection = Db::name('system_store_staff')
                    ->where('id', $staffIdHint)->where('is_del', 0)
                    ->field('roles,position,is_cashier')
                    ->find();
                if ($legacyProjection && (
                    trim((string)($legacyProjection['roles'] ?? '')) !== ''
                    || (int)($legacyProjection['position'] ?? 0) > 0
                    || (int)($legacyProjection['is_cashier'] ?? 0) === 1
                )) {
                    $positionIdsPresent = false;
                }
            }
        }
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
        $mobileEnabledPresent = array_key_exists('mobile_enabled', $input);
        $mobileEnabled = $mobileEnabledPresent && (int)$input['mobile_enabled'] === 1 ? 1 : 0;

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
            'position_ids_present' => $positionIdsPresent ? 1 : 0,
            'position_ids' => $positionIds,
            'scope_mode' => $scopeMode,
            'org_ids' => $orgIds,
            'store_ids' => $storeIds,
            'source' => $source,
            'can_choose' => (int)($input['can_choose'] ?? 1),
            'cashier_salesperson_enabled' => (int)($input['cashier_salesperson_enabled'] ?? 1),
            'cashier_craftsman_enabled' => (int)($input['cashier_craftsman_enabled'] ?? 1),
            'craftsman_performance_type' => trim((string)($input['craftsman_performance_type'] ?? EmployeeCraftsmanPerformanceTypeServices::COMMISSION)),
            'is_fencheng' => (int)($input['is_fencheng'] ?? 0),
            'mobile_enabled_present' => $mobileEnabledPresent ? 1 : 0,
            'mobile_enabled' => $mobileEnabledPresent ? $mobileEnabled : null,
            'employment_type_present' => $typeCodePresent ? 1 : 0,
            'employment_type_code' => $employmentTypeCode,
            'employment_type_version' => $employmentTypeVersion,
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
                $positionIdsPresent,
                $scopeMode,
                $orgIds,
                $storeIds,
                $typeCodePresent,
                $employmentTypeCode,
                $employmentTypeVersion,
                $mobileEnabledPresent,
                $mobileEnabled
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
                        $positionIdsPresent,
                        $scopeMode,
                        $orgIds,
                        $storeIds,
                        $typeCodePresent,
                        $employmentTypeCode,
                        $employmentTypeVersion,
                        $mobileEnabledPresent,
                        $mobileEnabled
                    ),
                ];
            },
            false
        );
    }

    /**
     * 编辑回显
     */
    public function getComplete(
        int $employeeId,
        int $staffId = 0,
        string $source = 'hq',
        bool $includeEmploymentType = false
    ): array
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
        $storeName = $storeId > 0
            ? (string)Db::name('system_store')->where('id', $storeId)->value('name')
            : '';
        $oe = Db::name('organization_employee')
            ->where('employee_id', $employeeId)
            ->where('is_del', 0)
            ->order('id', 'desc')
            ->find();
        $orgId = $oe ? (int)$oe['org_id'] : 0;
        // 员工可以同时归属多个组织；org_id 继续作为兼容的主组织回显，
        // 另返回完整关系供列表/编辑页说明，避免把最新一条误认为唯一归属。
        $organizationMembershipRows = Db::name('organization_employee')->alias('oe')
            ->leftJoin('organization o', 'o.id = oe.org_id')
            ->where('oe.employee_id', $employeeId)
            ->where('oe.is_del', 0)
            ->where('o.is_del', 0)
            ->field('oe.id,oe.org_id,oe.job_title,o.name as org_name')
            ->order('oe.id', 'asc')
            ->select()->toArray();
        $organizationMemberships = array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'org_id' => (int)($row['org_id'] ?? 0),
                'org_name' => (string)($row['org_name'] ?? ''),
                'job_title' => (string)($row['job_title'] ?? ''),
                'status' => array_key_exists('status', $row) ? (int)$row['status'] : 1,
            ];
        }, $organizationMembershipRows);
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

        $result = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'org_id' => $orgId,
            'store_id' => $storeId,
            'store_name' => $storeName,
            'org_employee_id' => $oe ? (int)$oe['id'] : 0,
            'organization_memberships' => $organizationMemberships,
            'staff_name' => (string)($emp['name'] ?? ''),
            'phone' => (string)($emp['phone'] ?? ''),
            'avatar' => (string)($emp['avatar'] ?? ''),
            // 尚未迁入统一账号表的历史任职继续回显原账号，避免编辑页把它
            // 误判为空并在后续保存时覆盖。
            'account' => $acct
                ? (string)$acct['account']
                : (string)($staff['account'] ?? ''),
            'position_ids' => $positionIds,
            'jobs' => $jobs,
            'scope' => $scope,
            'can_choose' => $staff ? (int)($staff['can_choose'] ?? 1) : 1,
            'cashier_salesperson_enabled' => $staff
                ? (int)($staff['cashier_salesperson_enabled'] ?? 1) : 1,
            'cashier_craftsman_enabled' => $staff
                ? (int)($staff['cashier_craftsman_enabled'] ?? 1) : 1,
            'is_fencheng' => $staff ? (int)($staff['is_fencheng'] ?? 0) : 0,
            'status' => (int)($emp['status'] ?? 1),
        ];
        $craftsmanType = $staff
            ? (string)($staff['craftsman_performance_type'] ?? EmployeeCraftsmanPerformanceTypeServices::COMMISSION)
            : EmployeeCraftsmanPerformanceTypeServices::COMMISSION;
        $result = array_merge($result, app()->make(EmployeeCraftsmanPerformanceTypeServices::class)->project($craftsmanType));
        // 员工授权是手机端唯一入口开关。任职渠道记录仅供兼容和审计，
        // 无店直属员工没有任职记录也可以由组织数据权限限定可操作门店。
        $mobileAuthOn = $this->hasEmployeeMobileAuthTable()
            && (int)Db::name('employee_mobile_auth')
                ->where('employee_id', $employeeId)->where('is_del', 0)->where('status', 1)->count() > 0;
        $result['mobile_enabled'] = $mobileAuthOn ? 1 : 0;
        if ($staff) {
            foreach ([
                'work_member_id', 'notify', 'is_customer', 'customer_url', 'is_reservable',
                'employee_number', 'id_card', 'age', 'join_area', 'join_date', 'birthday_date',
                'birthday_type', 'birthday_area', 'now_area', 'contract_begin', 'contract_end',
                'salary_status', 'department',
                'mentor_employee_id',
            ] as $field) {
                $result[$field] = $staff[$field] ?? null;
            }
        }
        if ($source === 'hq' && $includeEmploymentType) {
            /** @var EmployeeTypeAuthorityServices $typeAuthority */
            $typeAuthority = app()->make(EmployeeTypeAuthorityServices::class);
            $result = array_merge($result, $typeAuthority->readSnapshot($employeeId, 'hq'));
        } elseif ($source === 'store') {
            /** @var EmployeeTypeAuthorityServices $typeAuthority */
            $typeAuthority = app()->make(EmployeeTypeAuthorityServices::class);
            $result = array_merge($result, $typeAuthority->readSnapshot($employeeId, 'store', $staffId, $storeId));
        }
        return $result;
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
        bool $positionIdsPresent,
        string $scopeMode,
        array $orgIds,
        array $storeIds,
        bool $typeCodePresent,
        ?string $employmentTypeCode,
        ?int $employmentTypeVersion,
        bool $mobileEnabledPresent,
        int $mobileEnabled
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
        $employeeId = $this->upsertEmployee($employeeIdHint, $phone, $staffName, $avatar, $opCtx, $source);

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
        $legacyOrderStatus = null;
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
                if (!$positionIdsPresent) {
                    $legacyOrderStatus = (int)($existStaff['order_status'] ?? 0) === 1 ? 1 : 0;
                }
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
                'cashier_salesperson_enabled' => (int)($input['cashier_salesperson_enabled'] ?? 1) === 1 ? 1 : 0,
                'cashier_craftsman_enabled' => (int)($input['cashier_craftsman_enabled'] ?? 1) === 1 ? 1 : 0,
                'craftsman_performance_type' => app()->make(EmployeeCraftsmanPerformanceTypeServices::class)
                    ->normalize($input['craftsman_performance_type'] ?? EmployeeCraftsmanPerformanceTypeServices::COMMISSION),
                'is_fencheng' => (int)($input['is_fencheng'] ?? 0) === 1 ? 1 : 0,
                'is_reservable' => array_key_exists('is_reservable', $input)
                    ? ((int)$input['is_reservable'] === 1 ? 1 : 0) : 1,
                'verify_status' => array_key_exists('verify_status', $input)
                    ? ((int)$input['verify_status'] === 1 ? 1 : 0) : 1,
                'is_cashier' => (int)($input['is_cashier'] ?? 0) === 1 ? 1 : 0,
                'is_customer' => (int)($input['is_customer'] ?? 0) === 1 ? 1 : 0,
                'salary_status' => array_key_exists('salary_status', $input)
                    ? ((int)$input['salary_status'] === 1 ? 1 : 0) : 1,
            ];
            if ($account !== '') {
                $staffPayload['account'] = $account;
            }
            foreach ([
                'work_member_id', 'notify', 'is_customer', 'customer_url', 'is_reservable',
                'employee_number', 'id_card', 'age', 'join_area', 'join_date', 'birthday_date',
                'birthday_type', 'birthday_area', 'now_area', 'contract_begin', 'contract_end',
                'salary_status', 'department',
                'mentor_employee_id',
            ] as $field) {
                if (array_key_exists($field, $input)) {
                    $staffPayload[$field] = $input[$field];
                }
            }
            if ($source === 'store') {
                unset(
                    $staffPayload['is_fencheng'],
                    $staffPayload['verify_status'],
                    $staffPayload['is_cashier']
                );
            }
            $ret = $staffWrite->saveStaffAssignment($staffId, $staffPayload, $opCtx, [
                'use_outer_transaction' => true,
            ]);
            $staffId = (int)$ret['staff_id'];
            $employeeId = (int)$ret['employee_id'];
            if ($legacyOrderStatus !== null) {
                // Preserve legacy order access when this request did not change jobs.
                Db::name('system_store_staff')->where('id', $staffId)->update([
                    'order_status' => $legacyOrderStatus,
                ]);
            }
        } elseif ($staffIdHint > 0) {
            throw new AdminException('无店直属不可绑定伪造门店任职');
        }

        $employmentTypeOut = null;
        if ($typeCodePresent) {
            $this->maybeFail('employment_type', $input);
            /** @var EmployeeTypeAuthorityServices $typeAuthority */
            $typeAuthority = app()->make(EmployeeTypeAuthorityServices::class);
            $employmentTypeOut = $typeAuthority->saveTypeInTx(
                $employeeId,
                (string)$employmentTypeCode,
                (int)$employmentTypeVersion,
                $adminInfo,
                $auditMeta,
                $source,
                $staffId,
                $storeId
            );
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
        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        if ($positionIdsPresent) {
            $this->maybeFail('jobs', $input);
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
        } else {
            $jobsRet = [
                'staff_id' => $staffId,
                'store_id' => $storeId > 0 ? $storeId : 0,
                'position_ids' => [],
                'jobs' => $staffId > 0
                    ? $jobSvc->listActiveJobs($staffId)
                    : $jobSvc->listActiveJobs(0, $employeeId),
            ];
        }

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

        // 6c) 员工档案的唯一手机端开关。已有任职同步兼容入口；无店直属
        // 员工只写员工授权，后续由组织数据权限限定可见、可操作门店。
        // 未提交该字段的旧调用只重投影岗位，绝不根据岗位擅自把历史关闭授权重新打开。
        $mobileAccessOut = null;
        if ($mobileEnabledPresent) {
            $mobileAccessOut = $jobSvc->setEmployeeMobileAccessInTx(
                $employeeId,
                $staffId,
                $mobileEnabled,
                $adminInfo,
                $auditMeta,
                $source
            );
            $authProjection = ['auth_version' => $mobileAccessOut['auth_version']];
        } else {
            $authProjection = $jobSvc->afterEmployeeAuthChanged($employeeId);
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
            'cashier_salesperson_enabled' => $staffId > 0
                ? (int)Db::name('system_store_staff')->where('id', $staffId)->value('cashier_salesperson_enabled')
                : 1,
            'cashier_craftsman_enabled' => $staffId > 0
                ? (int)Db::name('system_store_staff')->where('id', $staffId)->value('cashier_craftsman_enabled')
                : 1,
            'craftsman_performance_type' => $staffId > 0
                ? (string)Db::name('system_store_staff')->where('id', $staffId)->value('craftsman_performance_type')
                : EmployeeCraftsmanPerformanceTypeServices::COMMISSION,
            'mobile_enabled' => $mobileAccessOut !== null
                ? (int)$mobileAccessOut['enabled']
                : ($this->hasEmployeeMobileAuthTable()
                    && (int)Db::name('employee_mobile_auth')->where('employee_id', $employeeId)->where('is_del', 0)->where('status', 1)->count() > 0 ? 1 : 0),
            'auth_version' => (int)($authProjection['auth_version'] ?? 0),
        ];
        $out = array_merge($out, app()->make(EmployeeCraftsmanPerformanceTypeServices::class)
            ->project((string)($out['craftsman_performance_type'] ?? EmployeeCraftsmanPerformanceTypeServices::COMMISSION)));
        if ($employmentTypeOut !== null) {
            $out['employment_type_code'] = (string)$employmentTypeOut['employment_type_code'];
            $out['employment_type_version'] = (int)$employmentTypeOut['employment_type_version'];
        }
        if ($entriesOut !== null) {
            $out['entries'] = $entriesOut['entries'] ?? [];
        }
        if ($mobileAccessOut !== null) {
            $out['mobile_access'] = $mobileAccessOut;
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

    private function hasEmployeeMobileAuthTable(): bool
    {
        $connection = Db::connect();
        $database = (string)$connection->getConfig('database');
        $table = (string)$connection->getConfig('prefix') . 'employee_mobile_auth';
        if ($database === '') {
            return false;
        }
        $row = Db::query(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table]
        );
        return (int)($row[0]['c'] ?? 0) > 0;
    }

    protected function upsertEmployee(
        int $employeeId,
        string $phone,
        string $name,
        string $avatar,
        array $opCtx,
        string $source
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
                /** @var EmployeeStaffWriteServices $staffWrite */
                $staffWrite = app()->make(EmployeeStaffWriteServices::class);
                $restored = $staffWrite->restoreDeletedEmployeeByPhone($phone, $opCtx);
                if (!$restored) {
                    throw new AdminException('员工主档状态已变化，请重试');
                }
                return (int)$restored['employee_id'];
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

        $employeeData = [
            'name' => $name,
            'phone' => $phone,
            'avatar' => $avatar,
            'avatar_type' => $avatarType,
            'uid' => null,
            'status' => 1,
            'is_del' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ];
        if ($source === 'store') {
            $employeeData['employment_type_code'] = 'internal';
            $employeeData['employment_type_version'] = 1;
        }
        $employeeId = (int)Db::name('employee')->insertGetId($employeeData);
        if ($source === 'store') {
            Db::name('employee_change_log')->insert([
                'employee_id' => $employeeId,
                'action' => self::ACTION_DEFAULT_INTERNAL,
                'target_type' => 'employee_employment_type',
                'target_id' => $employeeId,
                'source' => 'store',
                'before_data' => '{"employment_type_code":null,"employment_type_version":0}',
                'after_data' => '{"employment_type_code":"internal","employment_type_version":1}',
                'reason' => '门店新建员工默认归类为内部员工',
                'operator_type' => 'admin',
                'operator_id' => (int)($opCtx['operator_id'] ?? 0),
                'operator_name' => (string)($opCtx['operator_name'] ?? ''),
                'operator_ip' => (string)($opCtx['operator_ip'] ?? ''),
                'request_id' => (string)($opCtx['request_id'] ?? ''),
                'add_time' => $now,
            ]);
        }
        return $employeeId;
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
