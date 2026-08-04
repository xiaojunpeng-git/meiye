<?php
namespace app\services\organization;

use app\services\BaseServices;
use app\services\employee\EmployeeStaffWriteServices;
use app\services\mobile\merchant\MobileMerchantCapabilityCatalog;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I2 四端授权聚合读写（平台/门店/收银/手机）
 * 写路径统一走 OrganizationStrictIdempotencyServices
 */
class EmployeeAuthCenterServices extends BaseServices
{
    public function getAuthBundle(int $employeeId): array
    {
        $emp = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->find();
        if (!$emp) {
            throw new AdminException('员工不存在');
        }

        $admins = Db::name('system_admin')->where('employee_id', $employeeId)->where('is_del', 0)
            ->field('id,account,real_name,roles,status,employee_id,level')
            ->select()->toArray();

        $adminIds = array_values(array_filter(array_map(static function ($a) {
            return (int)($a['id'] ?? 0);
        }, $admins)));
        $orgAdminMap = [];
        if ($adminIds) {
            $orgRows = Db::name('organization_admin')->alias('oa')
                ->leftJoin('organization o', 'o.id = oa.org_id')
                ->whereIn('oa.admin_id', $adminIds)->where('oa.is_del', 0)
                ->field('oa.id,oa.admin_id,oa.org_id,oa.scope_mode,o.name AS org_name')
                ->select()->toArray();
            foreach ($orgRows as $oa) {
                $aid = (int)$oa['admin_id'];
                unset($oa['admin_id']);
                $orgAdminMap[$aid][] = $oa;
            }
        }
        foreach ($admins as &$a) {
            $a['org_admins'] = $orgAdminMap[(int)$a['id']] ?? [];
        }
        unset($a);

        $staffRows = Db::name('system_store_staff')->alias('s')
            ->leftJoin('system_store st', 'st.id = s.store_id')
            ->where('s.employee_id', $employeeId)->where('s.is_del', 0)
            ->field('s.id,s.store_id,s.staff_name,s.account,s.roles,s.status,s.is_store,s.is_cashier,s.is_manager,s.position,s.position_level,s.add_time,st.name AS store_name')
            ->order('s.id', 'asc')->select()->toArray();

        /** @var StaffJobPositionServices $jobSvc */
        $jobSvc = app()->make(StaffJobPositionServices::class);
        /** @var JobPositionPolicyServices $posPolicy */
        $posPolicy = app()->make(JobPositionPolicyServices::class);
        /** @var EmployeeDataScopeServices $scopeSvc */
        $scopeSvc = app()->make(EmployeeDataScopeServices::class);
        /** @var StaffTenureServices $tenureSvc */
        $tenureSvc = app()->make(StaffTenureServices::class);

        $allRoleIds = [];
        foreach ($staffRows as &$s) {
            $roleIds = array_values(array_filter(array_map('intval', explode(',', (string)($s['roles'] ?? '')))));
            $s['role_ids'] = $roleIds;
            $sid = (int)($s['id'] ?? 0);
            $storeId = (int)($s['store_id'] ?? 0);
            $s['job_positions'] = $jobSvc->listActiveJobs($sid);
            $s['channel_entries'] = $jobSvc->listEntries($sid);
            $s['function_preview'] = $jobSvc->buildFunctionPreview($sid);
            $s['tenure_periods'] = $tenureSvc->listTenurePeriods($sid);
            $s['selectable_positions'] = $storeId > 0 ? $posPolicy->listStoreSelectablePositions($storeId) : [];
            foreach ($roleIds as $rid) {
                $allRoleIds[$rid] = true;
            }
        }
        unset($s);

        $roleNameMap = [];
        if ($allRoleIds) {
            $roleNameMap = Db::name('system_role')->whereIn('id', array_keys($allRoleIds))->column('role_name', 'id');
        }
        foreach ($staffRows as &$s) {
            $names = [];
            foreach ($s['role_ids'] as $rid) {
                if (isset($roleNameMap[$rid])) {
                    $names[] = (string)$roleNameMap[$rid];
                }
            }
            $s['role_names'] = $names;
        }
        unset($s);

        $direct = Db::name('organization_employee')->alias('oe')
            ->leftJoin('organization o', 'o.id = oe.org_id')
            ->where('oe.employee_id', $employeeId)->where('oe.is_del', 0)->where('oe.status', 1)
            ->field('oe.id,oe.org_id,oe.job_title,oe.status,o.name AS org_name')
            ->select()->toArray();

        $mobile = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)->where('is_del', 0)->find();

        $platformRoleOptions = Db::name('system_role')
            ->where('type', 0)->where('status', 1)
            ->field('id,role_name')
            ->order('id', 'asc')
            ->select()->toArray();
        $platformRoleOptions = array_map(static function ($r) {
            return ['value' => (int)$r['id'], 'label' => (string)$r['role_name']];
        }, $platformRoleOptions);

        /** @var SystemRolePublishServices $pubSvc */
        $pubSvc = app()->make(SystemRolePublishServices::class);
        $storeRoleOptions = [];
        $cashierRoleOptions = [];
        foreach ($staffRows as $s) {
            $sid = (int)($s['store_id'] ?? 0);
            if ($sid <= 0 || isset($storeRoleOptions[$sid])) {
                continue;
            }
            $storeRoleOptions[$sid] = $pubSvc->listStoreSelectableRoles($sid);
            $cashierRoleOptions[$sid] = $pubSvc->listCashierSelectableRoles($sid);
        }

        /** @var MobileMerchantCapabilityCatalog $mobileCatalog */
        $mobileCatalog = app()->make(MobileMerchantCapabilityCatalog::class);
        $mobileRuleOptions = array_map(static function (array $feature): array {
            return ['value' => (int)$feature['id'], 'label' => (string)$feature['title']];
        }, $mobileCatalog->features());

        $dataScopes = $scopeSvc->listScopes($employeeId);
        $platformPreview = $jobSvc->buildEmployeePlatformPreview($employeeId);

        $allJobs = [];
        $allEntries = [];
        $allTenure = [];
        $selectableByStore = [];
        $previewByStaff = [];
        foreach ($staffRows as $s) {
            foreach ($s['job_positions'] ?? [] as $j) {
                $allJobs[] = $j;
            }
            foreach ($s['channel_entries'] ?? [] as $e) {
                $allEntries[] = $e;
            }
            foreach ($s['tenure_periods'] ?? [] as $t) {
                $allTenure[] = $t;
            }
            $storeId = (int)($s['store_id'] ?? 0);
            if ($storeId > 0) {
                $selectableByStore[$storeId] = $s['selectable_positions'] ?? [];
            }
            $previewByStaff[] = [
                'staff_id' => (int)($s['id'] ?? 0),
                'store_id' => $storeId,
                'preview' => $s['function_preview'] ?? [],
            ];
        }

        return [
            'employee' => [
                'id' => (int)$emp['id'],
                'name' => (string)$emp['name'],
                'phone' => (string)$emp['phone'],
                'avatar' => (string)($emp['avatar'] ?? ''),
                'status' => (int)($emp['status'] ?? 1),
            ],
            'platform' => $admins,
            'direct_memberships' => $direct,
            'store_assignments' => $staffRows,
            'jobs' => $allJobs,
            'entries' => $allEntries,
            'data_scopes' => $dataScopes,
            'tenure' => $allTenure,
            'function_preview' => [
                'platform' => $platformPreview,
                'by_staff' => $previewByStaff,
            ],
            'selectable_hints' => [
                'by_store' => $selectableByStore,
            ],
            'mobile' => $mobile ? $this->presentMobile($mobile) : null,
            'platform_role_options' => $platformRoleOptions,
            'store_role_options' => $storeRoleOptions,
            'cashier_role_options' => $cashierRoleOptions,
            'mobile_rule_options' => $mobileRuleOptions,
            'help' => $this->authBundleHelpTexts(),
        ];
    }

    /**
     * 用户可见通俗说明（与前端抽屉共用）
     * @return array<string, string>
     */
    public function authBundleHelpTexts(): array
    {
        return [
            'jobs' => '岗位决定能做什么功能。一人可在同一门店兼任多个岗位，功能权限按有效岗位取并集；岗位不会自动扩大数据查看范围。',
            'entries' => '入口只决定能不能登录对应端。开通前须已有有效岗位覆盖该端功能，否则无法保存空入口。',
            'data_scope' => '数据权限由人员决定。总部可设个人/组织/门店；门店只能设个人或本店。未配置时默认只能看本人参与的数据。',
            'function_preview' => '功能权限预览只读展示当前岗位计算结果，请到岗位策略里调整，不要在人员上直接勾功能菜单。',
            'tenure_suspend' => '停职只关闭本店任职、岗位、入口和本店来源数据授权，不影响其他门店与员工主档，历史订单仍保留。',
            'tenure_resume' => '复职会新开任职期间，不会自动恢复岗位和入口，需重新设置。',
            'tenure_delete' => '删除本店关系后，本人看不到本店历史；其他门店不受影响；门店订单和账务仍保留。',
            'platform_compat' => '以下平台/门店/收银/手机授权接口仍可用，但仅作入口与兼容投影；功能正文请以岗位为准。',
        ];
    }

    public function listAudits(int $employeeId, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = min(50, max(1, $limit));
        $q = Db::name('employee_change_log')->where('employee_id', $employeeId);
        $count = (int)$q->count();
        $list = Db::name('employee_change_log')->where('employee_id', $employeeId)
            ->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return compact('count', 'list');
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function savePlatformAuth(int $employeeId, array $data, array $adminInfo, array $requestCtx): array
    {
        $roles = $this->normalizeIntIds($data['roles'] ?? []);
        $payload = [
            'employee_id' => $employeeId,
            'admin_id' => (int)($data['admin_id'] ?? 0),
            'action' => (string)($data['action'] ?? 'bind'),
            'roles' => $roles,
            'status' => array_key_exists('status', $data) ? ((int)$data['status'] === 1 ? 1 : 0) : null,
            'pwd_modified' => !empty($data['pwd_modified']) ? 1 : 0,
            'reason' => (string)($data['reason'] ?? ''),
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'employee_auth_platform',
            'employee:' . $employeeId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $data, $roles, $adminInfo) {
                $this->assertEmployee($employeeId);
                $adminId = (int)($data['admin_id'] ?? 0);
                $action = (string)($data['action'] ?? 'bind');
                if ($adminId <= 0) {
                    throw new AdminException('请指定平台账号');
                }
                $admin = Db::name('system_admin')->where('id', $adminId)->where('is_del', 0)->lock(true)->find();
                if (!$admin) {
                    throw new AdminException('平台账号不存在');
                }
                $boundEmp = (int)($admin['employee_id'] ?? 0);
                if ($boundEmp > 0 && $boundEmp !== $employeeId) {
                    throw new AdminException('该账号已绑定其他员工，禁止错绑');
                }
                if ($boundEmp === 0 && $action !== 'bind') {
                    throw new AdminException('账号未绑定员工，请先绑定');
                }

                if ($action === 'bind') {
                    if (!$roles) {
                        throw new AdminException('绑定须指定有效平台角色');
                    }
                    $this->assertPlatformRoles($roles);
                    /** @var StaffJobPositionServices $jobSvc */
                    $jobSvc = app()->make(StaffJobPositionServices::class);
                    $jobSvc->assertChannelCoveredByJobs(0, JobPositionPolicyServices::CHANNEL_PLATFORM, $employeeId);
                    Db::name('system_admin')->where('id', $adminId)->update([
                        'employee_id' => $employeeId,
                        'roles' => implode(',', $roles),
                    ]);
                } elseif ($action === 'update') {
                    $upd = [];
                    if (array_key_exists('roles', $data)) {
                        if ($roles) {
                            $this->assertPlatformRoles($roles);
                            /** @var StaffJobPositionServices $jobSvc */
                            $jobSvc = app()->make(StaffJobPositionServices::class);
                            $jobSvc->assertChannelCoveredByJobs(0, JobPositionPolicyServices::CHANNEL_PLATFORM, $employeeId);
                        }
                        $upd['roles'] = $roles ? implode(',', $roles) : '';
                    }
                    if (array_key_exists('status', $data)) {
                        if ((int)$data['status'] === 1) {
                            /** @var StaffJobPositionServices $jobSvc */
                            $jobSvc = app()->make(StaffJobPositionServices::class);
                            $jobSvc->assertChannelCoveredByJobs(0, JobPositionPolicyServices::CHANNEL_PLATFORM, $employeeId);
                        }
                        $upd['status'] = (int)$data['status'] === 1 ? 1 : 0;
                    }
                    if ($upd) {
                        Db::name('system_admin')->where('id', $adminId)->update($upd);
                    }
                } elseif ($action === 'disable') {
                    Db::name('system_admin')->where('id', $adminId)->update(['status' => 0]);
                } elseif ($action === 'enable') {
                    /** @var StaffJobPositionServices $jobSvc */
                    $jobSvc = app()->make(StaffJobPositionServices::class);
                    $jobSvc->assertChannelCoveredByJobs(0, JobPositionPolicyServices::CHANNEL_PLATFORM, $employeeId);
                    Db::name('system_admin')->where('id', $adminId)->update(['status' => 1]);
                } else {
                    throw new AdminException('不支持的平台授权动作');
                }

                $pwdTouched = !empty($data['pwd_modified']);
                $after = [
                    'admin_id' => $adminId,
                    'action' => $action,
                    'roles' => $roles,
                    'password' => $pwdTouched ? '已修改' : '未修改',
                ];
                $this->writeAudit(
                    $employeeId,
                    'employee_auth_platform',
                    $adminId,
                    $after,
                    $adminInfo,
                    $auditMeta,
                    (string)($data['reason'] ?? '平台授权')
                );
                return ['msg' => '保存成功', 'data' => ['ok' => true, 'admin_id' => $adminId, 'action' => $action]];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveStoreBackendAuth(int $employeeId, array $data, array $adminInfo, array $requestCtx): array
    {
        $roles = $this->normalizeIntIds($data['roles'] ?? []);
        $staffId = (int)($data['staff_id'] ?? 0);
        $payload = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'store_id' => (int)($data['store_id'] ?? 0),
            'roles' => $roles,
            'status' => array_key_exists('status', $data) ? ((int)$data['status'] === 1 ? 1 : 0) : null,
            'reason' => (string)($data['reason'] ?? ''),
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'employee_auth_store',
            'employee:' . $employeeId . ':staff:' . $staffId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $data, $roles, $staffId, $adminInfo) {
                $this->assertEmployee($employeeId);
                if ($staffId <= 0) {
                    throw new AdminException('请指定门店任职');
                }
                $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->lock(true)->find();
                if (!$staff || (int)$staff['employee_id'] !== $employeeId) {
                    throw new AdminException('任职与员工不匹配');
                }
                $reqStoreId = (int)($data['store_id'] ?? 0);
                if ($reqStoreId > 0 && (int)$staff['store_id'] !== $reqStoreId) {
                    throw new AdminException('越权门店任职');
                }
                $storeId = (int)$staff['store_id'];
                /** @var StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(StaffJobPositionServices::class);
                $jobSvc->assertChannelCoveredByJobs($staffId, JobPositionPolicyServices::CHANNEL_STORE_BACKEND, $employeeId);
                /** @var EmployeeStaffWriteServices $write */
                $write = app()->make(EmployeeStaffWriteServices::class);
                $write->assertStoreRolesUsable($roles, $storeId, 'admin');

                Db::name('system_store_staff')->where('id', $staffId)->update([
                    'roles' => implode(',', $roles),
                    'is_store' => 1,
                    'status' => array_key_exists('status', $data) ? ((int)$data['status'] === 1 ? 1 : 0) : (int)$staff['status'],
                ]);
                // 同步入口表（兼容投影）
                $this->upsertEntryCompat($employeeId, $staffId, $storeId, JobPositionPolicyServices::CHANNEL_STORE_BACKEND, 1, $auditMeta);
                $after = ['staff_id' => $staffId, 'store_id' => $storeId, 'roles' => $roles];
                $this->writeAudit(
                    $employeeId,
                    'employee_auth_store',
                    $staffId,
                    $after,
                    $adminInfo,
                    $auditMeta,
                    (string)($data['reason'] ?? '门店后台授权')
                );
                return ['msg' => '保存成功', 'data' => ['ok' => true, 'staff_id' => $staffId, 'roles' => $roles]];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveCashierAuth(int $employeeId, array $data, array $adminInfo, array $requestCtx): array
    {
        $roles = $this->normalizeIntIds($data['roles'] ?? []);
        $staffId = (int)($data['staff_id'] ?? 0);
        $isCashier = (int)($data['is_cashier'] ?? 0) === 1 ? 1 : 0;
        $payload = [
            'employee_id' => $employeeId,
            'staff_id' => $staffId,
            'is_cashier' => $isCashier,
            'roles' => $roles,
            'pwd_modified' => !empty($data['pwd_modified']) ? 1 : 0,
            'reason' => (string)($data['reason'] ?? ''),
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'employee_auth_cashier',
            'employee:' . $employeeId . ':staff:' . $staffId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $data, $roles, $staffId, $isCashier, $adminInfo) {
                $this->assertEmployee($employeeId);
                if ($staffId <= 0) {
                    throw new AdminException('请指定门店任职');
                }
                $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->lock(true)->find();
                if (!$staff || (int)$staff['employee_id'] !== $employeeId) {
                    throw new AdminException('任职与员工不匹配');
                }
                $storeId = (int)$staff['store_id'];
                $oldRoles = array_values(array_filter(array_map('intval', explode(',', (string)($staff['roles'] ?? '')))));

                if ($isCashier === 0) {
                    $cashierPublishedIds = $this->listCashierPublishedRoleIds($storeId);
                    $kept = array_values(array_filter($oldRoles, static function ($rid) use ($cashierPublishedIds) {
                        return !in_array((int)$rid, $cashierPublishedIds, true);
                    }));
                    Db::name('system_store_staff')->where('id', $staffId)->update([
                        'is_cashier' => 0,
                        'roles' => implode(',', $kept),
                    ]);
                    $this->upsertEntryCompat($employeeId, $staffId, $storeId, JobPositionPolicyServices::CHANNEL_CASHIER, 0, $auditMeta);
                    $after = [
                        'staff_id' => $staffId,
                        'is_cashier' => 0,
                        'roles' => $kept,
                        'password' => !empty($data['pwd_modified']) ? '已修改' : '未修改',
                    ];
                    $this->writeAudit(
                        $employeeId,
                        'employee_auth_cashier',
                        $staffId,
                        $after,
                        $adminInfo,
                        $auditMeta,
                        (string)($data['reason'] ?? '撤销收银台授权')
                    );
                    $revoked = $this->assertCashierAccessRevoked($staffId);
                    return [
                        'msg' => '保存成功',
                        'data' => [
                            'ok' => true,
                            'staff_id' => $staffId,
                            'is_cashier' => 0,
                            'cashier_revoked' => $revoked,
                            'roles' => $kept,
                        ],
                    ];
                }

                /** @var StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(StaffJobPositionServices::class);
                $jobSvc->assertChannelCoveredByJobs($staffId, JobPositionPolicyServices::CHANNEL_CASHIER, $employeeId);

                if ($roles) {
                    $this->assertCashierPublishedRoles($roles, $storeId);
                }
                $merged = array_values(array_unique(array_merge($oldRoles, $roles)));
                $cashierOnStaff = array_values(array_intersect($merged, $this->listCashierPublishedRoleIds($storeId)));
                if (!$cashierOnStaff) {
                    throw new AdminException('开启收银权限须至少绑定一个本店已发布的收银角色');
                }

                Db::name('system_store_staff')->where('id', $staffId)->update([
                    'is_cashier' => 1,
                    'roles' => implode(',', $merged),
                ]);
                $this->upsertEntryCompat($employeeId, $staffId, $storeId, JobPositionPolicyServices::CHANNEL_CASHIER, 1, $auditMeta);
                $after = [
                    'staff_id' => $staffId,
                    'is_cashier' => 1,
                    'roles_added' => $roles,
                    'roles' => $merged,
                    'password' => !empty($data['pwd_modified']) ? '已修改' : '未修改',
                ];
                $this->writeAudit(
                    $employeeId,
                    'employee_auth_cashier',
                    $staffId,
                    $after,
                    $adminInfo,
                    $auditMeta,
                    (string)($data['reason'] ?? '收银台授权')
                );
                return ['msg' => '保存成功', 'data' => ['ok' => true, 'staff_id' => $staffId, 'is_cashier' => 1, 'roles' => $merged]];
            }
        );
    }

    /**
     * 收银权限已撤销：is_cashier=0 且 staff.roles 中无本店收银通道已发布角色
     */
    public function assertCashierAccessRevoked(int $staffId): bool
    {
        $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->find();
        if (!$staff) {
            return false;
        }
        if ((int)($staff['is_cashier'] ?? 0) !== 0) {
            return false;
        }
        $roles = array_values(array_filter(array_map('intval', explode(',', (string)($staff['roles'] ?? '')))));
        if (!$roles) {
            return true;
        }
        $cashierIds = $this->listCashierPublishedRoleIds((int)$staff['store_id']);
        foreach ($roles as $rid) {
            if (in_array((int)$rid, $cashierIds, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 手机授权：范围/开关可写；rules 只能由岗位 mobile 投影写入，禁止业务页手填
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveMobileAuth(int $employeeId, array $data, array $adminInfo, array $requestCtx): array
    {
        $action = (string)($data['action'] ?? 'save');
        $scopeMode = (string)($data['scope_mode'] ?? 'all');
        $orgIds = $this->normalizeIntIds($data['org_ids'] ?? []);
        $storeIds = $this->normalizeIntIds($data['store_ids'] ?? []);
        if (array_key_exists('rules', $data) && trim((string)$data['rules']) !== '') {
            throw new AdminException('手机端功能权限由岗位策略投影自动写入，禁止直接填写 rules');
        }
        $payload = [
            'employee_id' => $employeeId,
            'action' => $action,
            'scope_mode' => $scopeMode,
            'org_ids' => $orgIds,
            'store_ids' => $storeIds,
            'role_name' => trim((string)($data['role_name'] ?? '')),
            'status' => array_key_exists('status', $data) ? ((int)$data['status'] === 1 ? 1 : 0) : 1,
            'reason' => (string)($data['reason'] ?? ''),
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'employee_auth_mobile',
            'employee:' . $employeeId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($employeeId, $data, $action, $scopeMode, $orgIds, $storeIds, $adminInfo) {
                $this->assertEmployee($employeeId);
                $now = time();
                $row = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)->lock(true)->find();
                /** @var StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(StaffJobPositionServices::class);

                if ($action === 'revoke') {
                    if (!$row) {
                        throw new AdminException('无手机授权可撤销');
                    }
                    $ver = (int)$row['version'] + 1;
                    Db::name('employee_mobile_auth')->where('id', (int)$row['id'])->update([
                        'status' => 0,
                        'version' => $ver,
                        'update_time' => $now,
                    ]);
                    $jobSvc->afterEmployeeAuthChanged($employeeId);
                    $after = ['action' => 'revoke', 'version' => $ver];
                    $this->writeAudit(
                        $employeeId,
                        'employee_auth_mobile',
                        (int)$row['id'],
                        $after,
                        $adminInfo,
                        $auditMeta,
                        '撤销手机授权'
                    );
                    return ['msg' => '已撤销', 'data' => ['ok' => true, 'action' => 'revoke', 'version' => $ver, 'id' => (int)$row['id']]];
                }

                [$orgIds, $storeIds] = $this->normalizeMobileScope($scopeMode, $orgIds, $storeIds);
                $roleName = trim((string)($data['role_name'] ?? '岗位投影-手机'));
                if ($roleName === '') {
                    $roleName = '岗位投影-手机';
                }
                $status = array_key_exists('status', $data) ? ((int)$data['status'] === 1 ? 1 : 0) : 1;
                $token = (string)($auditMeta['request_token'] ?? '');

                // rules 一律由岗位 mobile channel 投影
                $proj = $jobSvc->projectMobileAuthRules($employeeId, $status);
                $rules = (string)($proj['rules'] ?? '');
                $id = (int)($proj['id'] ?? 0);
                if ($id <= 0 && $status === 1) {
                    throw new AdminException('当前有效岗位未包含手机端功能，无法开通手机授权');
                }
                if ($id > 0) {
                    $ver = (int)Db::name('employee_mobile_auth')->where('id', $id)->value('version');
                    Db::name('employee_mobile_auth')->where('id', $id)->update([
                        'scope_mode' => $scopeMode,
                        'org_ids' => json_encode($orgIds, JSON_UNESCAPED_UNICODE),
                        'store_ids' => json_encode($storeIds, JSON_UNESCAPED_UNICODE),
                        'role_name' => $roleName,
                        'rules' => $rules,
                        'status' => $status,
                        'request_token' => $token !== '' ? $token : ('mob-' . $employeeId . '-' . $now),
                        'is_del' => 0,
                        'update_time' => $now,
                    ]);
                    $act = $row ? 'update' : 'create';
                } else {
                    // 关闭且无历史：不建行
                    $ver = 0;
                    $act = 'noop';
                }

                $jobSvc->bumpEmployeeAuthVersion($employeeId);
                $jobSvc->invalidateMerchantSessions($employeeId);

                $after = [
                    'action' => $act,
                    'scope_mode' => $scopeMode,
                    'org_ids' => $orgIds,
                    'store_ids' => $storeIds,
                    'status' => $status,
                    'rules_projected' => true,
                    'version' => $ver,
                ];
                $this->writeAudit(
                    $employeeId,
                    'employee_auth_mobile',
                    $id,
                    $after,
                    $adminInfo,
                    $auditMeta,
                    (string)($data['reason'] ?? '手机授权')
                );
                if ($id <= 0) {
                    return ['msg' => '保存成功', 'data' => ['ok' => true, 'status' => 0, 'rules' => '', 'rules_projected' => true]];
                }
                $present = $this->presentMobile(Db::name('employee_mobile_auth')->where('id', $id)->find());
                $present['rules_projected'] = true;
                return ['msg' => '保存成功', 'data' => $present];
            }
        );
    }

    /**
     * @return array{0:int[],1:int[]}
     */
    protected function normalizeMobileScope(string $scopeMode, array $orgIds, array $storeIds): array
    {
        if (!in_array($scopeMode, ['all', 'org', 'store'], true)) {
            throw new AdminException('手机授权范围无效');
        }
        if ($scopeMode === 'all') {
            return [[], []];
        }
        if ($scopeMode === 'org') {
            if (!$orgIds) {
                throw new AdminException('组织范围须指定有效组织');
            }
            $found = Db::name('organization')->whereIn('id', $orgIds)->where('is_del', 0)->column('id');
            $found = array_map('intval', $found);
            sort($found);
            $want = $orgIds;
            sort($want);
            if ($found !== $want) {
                throw new AdminException('组织范围包含无效或已删除组织');
            }
            return [$found, []];
        }
        // store
        if (!$storeIds) {
            throw new AdminException('门店范围须指定有效门店');
        }
        $found = Db::name('system_store')->whereIn('id', $storeIds)->where('is_del', 0)->where('is_show', 1)->column('id');
        $found = array_map('intval', $found);
        sort($found);
        $want = $storeIds;
        sort($want);
        if ($found !== $want) {
            throw new AdminException('门店范围包含无效或未启用门店');
        }
        return [[], $found];
    }

    protected function normalizeMobileRules(string $rules): string
    {
        $rules = trim($rules);
        if ($rules === '') {
            return '';
        }
        if (!preg_match('/^\d+(,\d+)*$/', $rules)) {
            throw new AdminException('手机功能规则格式无效');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $rules)))));
        sort($ids);
        if (!$ids) {
            return '';
        }
        $found = Db::name('system_menus')
            ->whereIn('id', $ids)
            ->where('is_del', 0)
            ->whereIn('type', [3, 4])
            ->column('id');
        $found = array_map('intval', $found);
        sort($found);
        if ($found !== $ids) {
            throw new AdminException('手机功能规则包含无效菜单');
        }
        return implode(',', $ids);
    }

    /**
     * @return int[]
     */
    protected function listCashierPublishedRoleIds(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }
        $rows = Db::name('system_role_store_publish')->alias('p')
            ->join('system_role r', 'r.id = p.store_role_id')
            ->where('p.store_id', $storeId)
            ->where('p.channel', SystemRolePublishServices::CHANNEL_CASHIER)
            ->where('p.status', 1)
            ->where('r.status', 1)
            ->field('r.id,r.cashier_rules')
            ->select()->toArray();
        $out = [];
        foreach ($rows as $row) {
            if (trim((string)($row['cashier_rules'] ?? '')) === '') {
                continue;
            }
            $out[] = (int)$row['id'];
        }
        return array_values(array_unique($out));
    }

    /**
     * @param int[] $roleIds
     */
    protected function assertCashierPublishedRoles(array $roleIds, int $storeId): void
    {
        $allowed = $this->listCashierPublishedRoleIds($storeId);
        foreach ($roleIds as $rid) {
            if (!in_array((int)$rid, $allowed, true)) {
                throw new AdminException('只能使用本店已发布且含收银规则的收银角色');
            }
        }
    }

    protected function presentMobile(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'employee_id' => (int)($row['employee_id'] ?? 0),
            'status' => (int)($row['status'] ?? 0),
            'scope_mode' => (string)($row['scope_mode'] ?? ''),
            'org_ids' => json_decode((string)($row['org_ids'] ?? '[]'), true) ?: [],
            'store_ids' => json_decode((string)($row['store_ids'] ?? '[]'), true) ?: [],
            'role_name' => (string)($row['role_name'] ?? ''),
            'rules' => (string)($row['rules'] ?? ''),
            'version' => (int)($row['version'] ?? 1),
        ];
    }

    protected function assertEmployee(int $employeeId): void
    {
        $emp = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->find();
        if (!$emp) {
            throw new AdminException('员工不存在');
        }
    }

    /**
     * @param int[] $roleIds
     */
    protected function assertPlatformRoles(array $roleIds): void
    {
        foreach ($roleIds as $rid) {
            $role = Db::name('system_role')->where('id', $rid)->find();
            if (!$role || (int)$role['type'] !== 0 || (int)($role['status'] ?? 0) !== 1) {
                throw new AdminException('平台角色无效或已停用');
            }
        }
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

    protected function writeAudit(
        int $employeeId,
        string $action,
        int $targetId,
        array $after,
        array $operator,
        array $auditMeta,
        string $reason
    ): void {
        $json = json_encode($after, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new AdminException('审计序列化失败');
        }
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => $action,
            'target_type' => 'employee_auth',
            'target_id' => $targetId,
            'source' => 'admin',
            'before_data' => '',
            'after_data' => $json,
            'reason' => $reason,
            'operator_type' => 'admin',
            'operator_id' => (int)($auditMeta['operator_id'] ?? $operator['id'] ?? 0),
            'operator_name' => (string)($auditMeta['operator_name'] ?? $operator['real_name'] ?? $operator['account'] ?? ''),
            'operator_ip' => (string)($auditMeta['operator_ip'] ?? ''),
            'request_id' => (string)($auditMeta['request_id'] ?? ''),
            'add_time' => time(),
        ]);
    }

    /**
     * 兼容旧授权写接口：同步 staff_channel_entry
     */
    protected function upsertEntryCompat(
        int $employeeId,
        int $staffId,
        int $storeId,
        string $channel,
        int $status,
        array $auditMeta
    ): void {
        $now = time();
        $token = (string)($auditMeta['request_token'] ?? '');
        $exist = Db::name('staff_channel_entry')
            ->where('staff_id', $staffId)
            ->where('channel', $channel)
            ->find();
        $row = [
            'employee_id' => $employeeId,
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'channel' => $channel,
            'status' => $status === 1 ? 1 : 0,
            'is_del' => 0,
            'operator_id' => (int)($auditMeta['operator_id'] ?? 0),
            'operator_name' => (string)($auditMeta['operator_name'] ?? ''),
            'update_time' => $now,
        ];
        if ($exist) {
            Db::name('staff_channel_entry')->where('id', (int)$exist['id'])->update($row);
        } else {
            $row['request_token'] = $token !== '' ? ($token . '#compat#' . $channel) : ('compat-' . $staffId . '-' . $channel . '-' . $now);
            $row['add_time'] = $now;
            Db::name('staff_channel_entry')->insert($row);
        }
    }
}
