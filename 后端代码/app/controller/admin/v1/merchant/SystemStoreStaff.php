<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\controller\admin\v1\merchant;

use app\services\organization\OrganizationScopeService;
use app\services\store\StoreStaffTransferServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\store\finance\StaffFlowingWaterServices;
use app\services\system\AdminTableColumnServices;
use app\services\system\SystemRoleServices;
use app\services\user\UserServices;
use app\services\work\WorkMemberServices;
use mohe\exceptions\AdminException;
use think\facade\App;
use think\facade\Db;
use app\controller\admin\AuthController;

/**
 * 店员
 * Class SystemStoreStaff
 * @package app\controller\admin\v1\merchant
 */
class SystemStoreStaff extends AuthController
{
    /**
     * 构造方法
     * SystemStoreStaff constructor.
     * @param App $app
     * @param SystemStoreStaffServices $services
     */
    public function __construct(App $app, SystemStoreStaffServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 构建店员列表查询条件
     * @return array|null null 表示无权限/无数据应返回空列表
     */
    protected function buildStaffListWhere(): ?array
    {
        $params = $this->request->getMore([
            [['org_id', 'd'], 0],
            [['store_id', 'd'], 0],
            ['keyword', ''],
            ['status', ''],
        ]);
        $orgId = (int)$params['org_id'];
        $storeId = (int)$params['store_id'];
        $allowed = null;
        if ($this->adminType == 3 && $this->agentId) {
            /** @var OrganizationScopeService $scopeService */
            $scopeService = app()->make(OrganizationScopeService::class);
            $allowed = $scopeService->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId);
            if (!$allowed) {
                return null;
            }
        }
        $storeIds = null;
        if ($orgId > 0) {
            /** @var OrganizationScopeService $scopeService */
            $scopeService = app()->make(OrganizationScopeService::class);
            $orgStoreIds = $scopeService->getOrgStoreIds($orgId, true);
            $storeIds = $allowed !== null ? array_values(array_intersect($allowed, $orgStoreIds)) : $orgStoreIds;
        } elseif ($allowed !== null) {
            $storeIds = $allowed;
        }
        if ($storeId > 0) {
            if ($storeIds === null) {
                $storeIds = [$storeId];
            } else {
                $storeIds = in_array($storeId, $storeIds, true) ? [$storeId] : [];
            }
        }
        if ($storeIds !== null && !$storeIds) {
            // 组织部门可以没有下属门店，但仍可能有直属员工；给门店查询一个空范围，
            // 由列表服务继续合并 organization_employee，不能在控制器提前返回空结果。
            if ($orgId <= 0) {
                return null;
            }
            $storeIds = [-1];
        }
        $where = [
            'keyword' => $params['keyword'],
            'is_del' => 0,
        ];
        // 保留组织 ID 给列表服务合并“组织直属员工”；该关系不等同门店任职。
        if ($orgId > 0) {
            $where['organization_id'] = $orgId;
        }
        // 仅当用户实际点选了具体门店时，直属人员才应从结果中排除。
        if ($storeId > 0) {
            $where['organization_selected_store_id'] = $storeId;
        }
        if ($params['status'] !== '') {
            $where['status'] = (int)$params['status'];
        }
        if ($storeIds !== null) {
            $where['store_id'] = $storeIds;
        }
        return $where;
    }

	/**
	 * 获取店员列表
	 * @return mixed
	 */
    public function index()
    {
        $where = $this->buildStaffListWhere();
        if ($where === null) {
            return $this->success(['list' => [], 'count' => 0]);
        }
        return $this->success($this->services->getStoreStaffList($where, ['store', 'user']));
    }

	/**
	 * 获取店员列表
	 * @return mixed
	 */
    public function getStoreStaffList()
    {
        $where = $this->buildStaffListWhere();
        if ($where === null) {
            return $this->success(['list' => [], 'count' => 0]);
        }
        return $this->success($this->services->getStoreStaffList($where, ['store', 'user']));
    }

    /**
     * 门店店员下拉（轻量，仅 id/名称）
     */
    public function getStaffSelect()
    {
        $where = $this->request->getMore([
            ['store_id', 0],
            ['keyword', ''],
        ]);
        $where['status'] = 1;
        $where['is_del'] = 0;
        if (!(int)$where['store_id']) {
            return $this->success([]);
        }
        if (!$this->checkStaffStoreAccess((int)$where['store_id'])) {
            return $this->success([]);
        }
        return $this->success($this->services->getSelectList($where));
    }

    /**
     * 获取店员专属客户
     * @param UserServices $userServices
     * @param $id
     * @return mixed
     */
    public function getStoreStaffCustomer(UserServices $userServices, $id)
    {
        $where = $this->request->getMore([
            ['keyword', ''],
            ['data', ''],
        ]);
        if (!$id) {
            return $this->fail('参数有误！');
        }
        $where['salesman_id'] = $id;
        return $this->success($userServices->getStaffCustomerList($where));
    }

    /**
     * 获取店员业绩列表
     * @param StaffFlowingWaterServices $waterServices
     * @param $id
     * @return mixed
     */
    public function getStaffPerformance(StaffFlowingWaterServices $waterServices, $id)
    {
        $where = $this->request->getMore([
            ['data', ''],
            ['keyword', ''],
            ['link_id', ''],
            ['price', ''],
            ['performance', ''],
        ]);
        if (!$id) {
            return $this->fail('参数有误！');
        }
        $where['staff_id'] = $id;
        return $this->success($waterServices->getgetStaffPerformanceData($where));
    }

	/**
	 * 门店列表
	 * @param SystemStoreServices $services
	 * @return mixed
	 */
    public function store_list(SystemStoreServices $services)
    {
		$where = ['status' => 1];
		if ($this->adminType == 3 && $this->agentId) {
            /** @var OrganizationScopeService $scopeService */
            $scopeService = app()->make(OrganizationScopeService::class);
			$storeIds = $scopeService->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId);
			if ($storeIds) {
				$where['id'] = $storeIds;
			} else {
				return $this->success([]);
			}
		}
        return $this->success($services->getStore($where));
    }

    /**
     * 店员新增表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function create()
    {
        return $this->success($this->services->createForm());
    }

    /**
     * 店员修改表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function edit()
    {
        [$id] = $this->request->getMore([
            [['id', 'd'], 0],
        ], true);
        return $this->success($this->services->updateForm($id));
    }

    /**
     * 保存店员信息
     */
    public function save($id = 0)
    {
        $data = $this->request->postMore([
            ['image', ''],
            ['uid', 0],
            ['avatar', ''],
            ['store_id', ''],
            ['staff_name', ''],
            ['phone', ''],
            ['verify_status', 1],
            ['status', 1],
        ]);
        if ($data['store_id'] == '') {
            return $this->fail('请选择所属门店');
        }
        if ($data['staff_name'] == '') {
            return $this->fail('请填写核销员名称');
        }
        if ($data['phone'] == '') {
            return $this->fail('请填写核销员电话');
        }
        // I1：核销员不再强制绑定商城用户，禁止新写/回写 staff.uid
        if (!$id) {
            if (is_array($data['image']) && !empty($data['image']['image'])) {
                $data['avatar'] = (string)$data['image']['image'];
            }
        } else {
            if (is_string($data['image']) && $data['image'] !== '') {
                $data['avatar'] = $data['image'];
            } elseif (is_array($data['image']) && !empty($data['image']['image'])) {
                $data['avatar'] = (string)$data['image']['image'];
            }
        }
        unset($data['image'], $data['uid']);
        $data['is_store'] = 1;
        $data['roles'] = $data['roles'] ?? [];
        if (!$this->checkStaffStoreAccess((int)$data['store_id'])) {
            return $this->fail('无权在该门店操作核销员');
        }
        try {
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            /** @var \app\services\employee\EmployeeStaffWriteServices $write */
            $write = app()->make(\app\services\employee\EmployeeStaffWriteServices::class);
            $ret = $write->saveStaffAssignment((int)$id, $data, [
                'operator_id' => (int)$this->adminId,
                'operator_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'admin',
                'reason' => $id ? '总后台编辑核销员' : '总后台新建核销员',
                'allow_profile_update' => (int)$id > 0,
            ]);
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '保存失败');
        }
        return $this->success($id ? '编辑成功' : '核销员添加成功', [
            'staff_id' => (int)$ret['staff_id'],
            'employee_id' => (int)$ret['employee_id'],
        ]);
    }

    /**
     * 设置单个店员是否开启
     * @param string $is_show
     * @param string $id
     * @return mixed
     */
    public function set_show($is_show = '', $id = '')
    {
        if ($is_show === '' || $id === '') {
            return $this->fail('缺少参数');
        }
        $id = (int)$id;
        $isShow = (int)$is_show;
        if ($isShow === 1) {
            return $this->fail('请通过编辑员工重新启用并选择门店角色');
        }
        if ($isShow !== 0) {
            return $this->fail('参数有误');
        }
        try {
            // H3：写门禁 → 管理员范围校验 → leaveStoreAssignment
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            $staff = $this->services->getStaffInfo($id);
            if (!$this->checkStaffStoreAccess((int)$staff['store_id'])) {
                return $this->fail('无权操作该店员');
            }
            /** @var \app\services\employee\EmployeeStaffWriteServices $write */
            $write = app()->make(\app\services\employee\EmployeeStaffWriteServices::class);
            $write->leaveStoreAssignment($id, [
                'operator_id' => (int)$this->adminId,
                'operator_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'admin',
                'reason' => '总后台关闭门店任职',
            ], false);
            return $this->success('关闭成功');
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '关闭失败');
        }
    }

    /**
     * 删除店员（软删：单店离职清权后 is_del=1，保留历史身份）
     * @param $id
     */
    public function delete($id)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('数据不存在');
        }
        try {
            // H3：写门禁 → 管理员范围校验 → leaveStoreAssignment
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            $staff = $this->services->getStaffInfo($id);
            if (!$this->checkStaffStoreAccess((int)$staff['store_id'])) {
                return $this->fail('无权操作该店员');
            }
            /** @var \app\services\employee\EmployeeStaffWriteServices $write */
            $write = app()->make(\app\services\employee\EmployeeStaffWriteServices::class);
            $write->leaveStoreAssignment($id, [
                'operator_id' => (int)$this->adminId,
                'operator_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'admin',
                'reason' => '总后台删除门店任职',
            ], true);
            return $this->success('删除成功!');
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '删除失败,请稍候再试!');
        }
    }

    /**
     * 校验当前管理员是否有权操作该门店店员
     * @param int $storeId
     * @return bool
     */
    protected function checkStaffStoreAccess(int $storeId): bool
    {
        if ($this->adminType == 3 && $this->agentId) {
            /** @var OrganizationScopeService $scopeService */
            $scopeService = app()->make(OrganizationScopeService::class);
            $storeIds = $scopeService->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId);
            if (!$storeIds) {
                return false;
            }
            return in_array($storeId, $storeIds, true);
        }
        return true;
    }

    /**
     * 获取店员详情（编辑页）
     * @param int $id
     * @return mixed
     */
    public function read($id)
    {
        if (!$id) {
            return $this->fail('缺少店员id');
        }
        $staff = $this->services->getStaffInfo((int)$id);
        if (!$this->checkStaffStoreAccess((int)$staff['store_id'])) {
            return $this->fail('无权操作该店员');
        }
        return $this->success($this->services->read((int)$id));
    }

    /**
     * 保存店员信息（总后台编辑/新建）——委托人员完整保存编排
     * @param int $id staff_id；无店直属新建可为 0，编辑可传 employee_id
     * @return mixed
     */
    public function saveStaff($id = 0)
    {
        return $this->saveStaffByIdentity((int)$id, false);
    }

    /**
     * 组织工作台人员完整保存：路径 ID 明确为 employee_id。
     * 不允许 employee_id 数值碰撞到不相关的 system_store_staff.id。
     *
     * @param int $employeeId employee_id；新建传 0
     * @return mixed
     */
    public function savePersonComplete($employeeId = 0)
    {
        return $this->saveStaffByIdentity((int)$employeeId, true);
    }

    /**
     * @param int $id staff_id（兼容入口）或 employee_id（明确人员入口）
     * @param bool $explicitEmployee true 时路径 ID 只能作为 employee_id 解析
     * @return mixed
     */
    protected function saveStaffByIdentity(int $id, bool $explicitEmployee)
    {
        $raw = $this->request->post();
        if (!is_array($raw)) {
            $raw = [];
        }
        try {
            \app\services\store\SystemStoreStaffServices::assertNoLegacyStaffAuthBypass($raw);
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        }

        $employeeId = (int)($raw['employee_id'] ?? 0);
        $storeId = (int)($raw['store_id'] ?? 0);
        $orgId = (int)($raw['org_id'] ?? 0);
        if ($explicitEmployee) {
            if ($id > 0) {
                if ($employeeId > 0 && $employeeId !== $id) {
                    return $this->fail('路径员工与提交员工不一致');
                }
                $employee = Db::name('employee')->where('id', $id)->where('is_del', 0)->find();
                if (!$employee) {
                    return $this->fail('员工不存在');
                }
                $employeeId = $id;
            } elseif ($employeeId > 0) {
                return $this->fail('新建人员不能提交员工ID');
            }

            // staff_id 只可作为同一员工既有任职的辅助信息，不能改变保存对象。
            $requestedStaffId = (int)($raw['staff_id'] ?? 0);
            if ($requestedStaffId > 0) {
                $requestedStaff = $this->services->get($requestedStaffId);
                $requestedStaffArr = is_object($requestedStaff) ? $requestedStaff->toArray() : (array)$requestedStaff;
                if (!$requestedStaffArr || $employeeId <= 0
                    || (int)($requestedStaffArr['employee_id'] ?? 0) !== $employeeId) {
                    return $this->fail('任职与员工不匹配');
                }
                $id = $requestedStaffId;
            } else {
                $id = 0;
            }
        }
        if ($orgId <= 0 && $employeeId > 0) {
            $orgId = (int)Db::name('organization_employee')
                ->where('employee_id', $employeeId)->where('is_del', 0)->order('id', 'desc')->value('org_id');
        }
        if ($id > 0) {
            $staff = $this->services->get($id);
            if (!$staff) {
                // 兼容：URL id 为 employee_id（无店直属）
                $emp = Db::name('employee')->where('id', $id)->where('is_del', 0)->find();
                if ($emp) {
                    $employeeId = $id;
                    $id = 0;
                } else {
                    return $this->fail('店员不存在');
                }
            } else {
                $staffArr = is_object($staff) ? $staff->toArray() : (array)$staff;
                if ((int)($staffArr['store_id'] ?? 0) > 0 && !$this->checkStaffStoreAccess((int)$staffArr['store_id'])) {
                    return $this->fail('无权操作该店员');
                }
                $storeId = (int)$staffArr['store_id'];
                $employeeId = (int)($staffArr['employee_id'] ?? $employeeId);
                if ($orgId <= 0 && $employeeId > 0) {
                    $orgId = (int)Db::name('organization_employee')
                        ->where('employee_id', $employeeId)->where('is_del', 0)->order('id', 'desc')->value('org_id');
                }
                if ($orgId <= 0 && $storeId > 0) {
                    $orgId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
                }
            }
        } elseif ($storeId > 0) {
            if (!$this->checkStaffStoreAccess($storeId)) {
                return $this->fail('无权在该门店添加店员');
            }
        }

        $avatar = trim((string)($raw['avatar'] ?? ''));
        if ($avatar === '' && !empty($raw['image']) && is_array($raw['image'])) {
            $avatar = (string)($raw['image']['image'] ?? '');
        }

        $headerToken = trim((string)$this->request->header('X-Request-Token', ''));
        if ($headerToken === '') {
            $headerToken = trim((string)$this->request->header('Request-Token', ''));
        }
        $bodyToken = trim((string)($raw['request_token'] ?? $this->request->param('request_token', '')));

        $positionIds = array_key_exists('position_ids', $raw) ? ($raw['position_ids'] ?? []) : null;
        if ($positionIds === null) {
            if ($id > 0) {
                $positionIds = array_map(static function ($j) {
                    return (int)($j['position_id'] ?? 0);
                }, app()->make(\app\services\organization\StaffJobPositionServices::class)->listActiveJobs($id));
            } elseif ($employeeId > 0) {
                $positionIds = array_map(static function ($j) {
                    return (int)($j['position_id'] ?? 0);
                }, app()->make(\app\services\organization\StaffJobPositionServices::class)->listActiveJobs(0, $employeeId));
            } else {
                $positionIds = [];
            }
        }
        $scopeMode = array_key_exists('scope_mode', $raw)
            ? trim((string)$raw['scope_mode'])
            : '';
        $orgIds = array_key_exists('org_ids', $raw) ? ($raw['org_ids'] ?? []) : null;
        $storeIdsScope = array_key_exists('store_ids', $raw) ? ($raw['store_ids'] ?? []) : null;
        if ($scopeMode === '' && $employeeId > 0) {
            $scopes = app()->make(\app\services\organization\EmployeeDataScopeServices::class)->listScopes($employeeId, 0);
            $scopeMode = 'personal';
            $orgIds = [];
            $storeIdsScope = [];
            foreach ($scopes as $sc) {
                if ((string)($sc['source_type'] ?? '') === 'hq') {
                    $scopeMode = (string)($sc['scope_mode'] ?? 'personal');
                    $orgIds = $sc['org_ids'] ?? [];
                    $storeIdsScope = $sc['store_ids'] ?? [];
                    break;
                }
            }
        }
        if ($scopeMode === '') {
            $scopeMode = 'personal';
        }
        if ($orgIds === null) {
            $orgIds = [];
        }
        if ($storeIdsScope === null) {
            $storeIdsScope = [];
        }
        // 账号字段缺失表示完整档案编辑未改登录设置；不得回填后再触发账号写入。
        // 只有前端明确提交 account 时，才让完整保存服务处理账号命令。
        $account = array_key_exists('account', $raw)
            ? trim((string)$raw['account'])
            : '';

        $input = [
            'employee_id' => $employeeId,
            'staff_id' => $id,
            'org_id' => $orgId,
            'store_id' => $storeId,
            'staff_name' => trim((string)($raw['staff_name'] ?? '')),
            'phone' => trim((string)($raw['phone'] ?? '')),
            'avatar' => $avatar,
            'account' => $account,
            'pwd' => (string)($raw['pwd'] ?? ''),
            'position_ids' => is_array($positionIds) ? $positionIds : [],
            'scope_mode' => $scopeMode,
            'org_ids' => $orgIds,
            'store_ids' => $storeIdsScope,
            'can_choose' => (int)($raw['can_choose'] ?? 1),
            'cashier_salesperson_enabled' => (int)($raw['cashier_salesperson_enabled'] ?? 1),
            'cashier_craftsman_enabled' => (int)($raw['cashier_craftsman_enabled'] ?? 1),
            'is_fencheng' => (int)($raw['is_fencheng'] ?? 0),
            'is_reservable' => (int)($raw['is_reservable'] ?? 1),
            'verify_status' => (int)($raw['verify_status'] ?? 1),
            'is_cashier' => (int)($raw['is_cashier'] ?? 0),
            'is_customer' => (int)($raw['is_customer'] ?? 0),
            'status' => (int)($raw['status'] ?? 1),
            'request_token' => $bodyToken,
        ];
        foreach (['roles', 'role_ids', 'save_roles', 'is_manager', 'is_butler', 'position', 'position_level'] as $k) {
            if (array_key_exists($k, $raw)) {
                $input[$k] = $raw[$k];
            }
        }
        foreach (['employment_type_code', 'employment_type_version'] as $k) {
            if (array_key_exists($k, $raw)) {
                $input[$k] = $raw[$k];
            }
        }
        if (array_key_exists('mobile_enabled', $raw)) {
            $input['mobile_enabled'] = (int)$raw['mobile_enabled'] === 1 ? 1 : 0;
        }

        try {
            /** @var \app\services\employee\EmployeePersonCompleteWriteServices $complete */
            $complete = app()->make(\app\services\employee\EmployeePersonCompleteWriteServices::class);
            $ret = $complete->saveComplete(
                $input,
                [
                    'id' => (int)$this->adminId,
                    'account' => (string)($this->adminInfo['account'] ?? ''),
                    'real_name' => (string)($this->adminInfo['real_name'] ?? ''),
                    'level' => (int)($this->adminInfo['level'] ?? 0),
                    'admin_type' => (int)($this->adminInfo['admin_type'] ?? $this->adminType ?? 0),
                ],
                [
                    'header_token' => $headerToken,
                    'body_token' => $bodyToken,
                    'operator_ip' => (string)$this->request->ip(),
                ],
                'hq'
            );
            $data = is_array($ret['data'] ?? null) ? $ret['data'] : [];
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '保存失败');
        }
        return $this->success($id || $employeeId ? '编辑成功' : '添加成功', $data);
    }

    /**
     * 人员完整详情（含岗位与数据权限）
     * @param int $id employee_id；可选 query staff_id
     */
    public function personComplete($id = 0)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return $this->fail('缺少员工id');
        }
        $staffId = (int)$this->request->get('staff_id', 0);
        try {
            /** @var \app\services\employee\EmployeePersonCompleteWriteServices $complete */
            $complete = app()->make(\app\services\employee\EmployeePersonCompleteWriteServices::class);
            /** @var \app\services\employee\EmployeeTypeAuthorityServices $typeAuthority */
            $typeAuthority = app()->make(\app\services\employee\EmployeeTypeAuthorityServices::class);
            $canReadEmploymentType = $typeAuthority->canManagePermission([
                'id' => (int)$this->adminId,
                'account' => (string)($this->adminInfo['account'] ?? ''),
                'real_name' => (string)($this->adminInfo['real_name'] ?? ''),
                'level' => (int)($this->adminInfo['level'] ?? 0),
                'admin_type' => (int)($this->adminInfo['admin_type'] ?? $this->adminType ?? 0),
            ]);
            return $this->success($complete->getComplete($id, $staffId, 'hq', $canReadEmploymentType));
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '读取失败');
        }
    }

    /**
     * 店员调店
     * @param int $id
     * @return mixed
     */
    public function transfer($id = 0)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('缺少店员id');
        }
        $data = $this->request->postMore([
            [['target_store_id', 'd'], 0],
            ['roles', []],
            ['reason', ''],
            [['immediate', 'd'], 1],
        ]);
        if (!$data['target_store_id']) {
            return $this->fail('请选择目标门店');
        }
        $staff = $this->services->getStaffInfo($id);
        if (!$this->checkStaffStoreAccess((int)$staff['store_id'])) {
            return $this->fail('无权操作该店员');
        }
        if (!$this->checkStaffStoreAccess((int)$data['target_store_id'])) {
            return $this->fail('无权将店员调整至所选门店');
        }
        /** @var StoreStaffTransferServices $transferServices */
        $transferServices = app()->make(StoreStaffTransferServices::class);
        try {
            $transferServices->transfer(
                $id,
                (int)$data['target_store_id'],
                (array)$data['roles'],
                (string)$data['reason'],
                (int)$data['immediate'],
                [
                    'id' => (int)$this->adminId,
                    'name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                    'type' => 1,
                ]
            );
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('调店成功');
    }

    /**
     * 调店记录
     * @return mixed
     */
    public function transferLog()
    {
        $where = $this->request->getMore([
            [['staff_id', 'd'], 0],
            [['from_store_id', 'd'], 0],
            [['to_store_id', 'd'], 0],
            ['data', ''],
        ]);
        if ($this->adminType == 3 && $this->agentId) {
            /** @var OrganizationScopeService $scopeService */
            $scopeService = app()->make(OrganizationScopeService::class);
            $allowed = $scopeService->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId);
            if (!$allowed) {
                return $this->success(['list' => [], 'count' => 0]);
            }
            if (!empty($where['from_store_id']) && !in_array((int)$where['from_store_id'], $allowed, true)) {
                return $this->success(['list' => [], 'count' => 0]);
            }
            if (!empty($where['to_store_id']) && !in_array((int)$where['to_store_id'], $allowed, true)) {
                return $this->success(['list' => [], 'count' => 0]);
            }
        }
        /** @var StoreStaffTransferServices $transferServices */
        $transferServices = app()->make(StoreStaffTransferServices::class);
        return $this->success($transferServices->getTransferLogList($where));
    }

    /**
     * 获取列表列配置
     * @param AdminTableColumnServices $columnServices
     * @return mixed
     */
    public function getColumnSetting(AdminTableColumnServices $columnServices)
    {
        [$tableKey] = $this->request->getMore([['table_key', '']], true);
        $tableKey = $tableKey ?: 'staff_list_admin';
        return $this->success($columnServices->getColumnSetting(1, (int)$this->adminId, $tableKey));
    }

    /**
     * 保存列表列配置
     * @param AdminTableColumnServices $columnServices
     * @return mixed
     */
    public function saveColumnSetting(AdminTableColumnServices $columnServices)
    {
        $data = $this->request->postMore([
            ['table_key', 'staff_list_admin'],
            ['columns', []],
        ]);
        try {
            $columnServices->saveColumnSetting(1, (int)$this->adminId, (string)$data['table_key'], $data['columns']);
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('保存成功');
    }

    /**
     * 获取企业微信员工列表
     * @param WorkMemberServices $services
     * @return mixed
     */
    public function getWorkMemberList(WorkMemberServices $services)
    {
        return $this->success($services->getMemberList(['status' => 1, 'enable' => 1], ['id', 'name', 'qr_code']));
    }

    /**
     * 获取店员角色列表
     * @param SystemRoleServices $roleServices
     * @return mixed
     */
    public function staffRoleList(SystemRoleServices $roleServices)
    {
        [$store_id] = $this->request->getMore([['store_id', 'd'], 0], true);
        return $this->success($roleServices->getRoleFormSelect(1, 1, (int)$store_id));
    }

    /**
     * 职位列表
     * @return mixed
     */
    public function staffPosition()
    {
        $list = Db::name('position')->select();
        $options = [];
        foreach ($list as $nv) {
            $options[] = ['label' => $nv['name'], 'value' => $nv['id']];
        }
        return $this->success($options);
    }

    /**
     * 职级列表
     * @return mixed
     */
    public function staffPositionLevel()
    {
        $list = Db::name('position_level')->select();
        $options = [];
        foreach ($list as $nv) {
            $options[] = ['label' => $nv['name'], 'value' => $nv['id']];
        }
        return $this->success($options);
    }
}
