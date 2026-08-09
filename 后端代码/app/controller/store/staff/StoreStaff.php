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
namespace app\controller\store\staff;

use app\services\order\store\BranchOrderServices;
use app\services\store\finance\StaffFlowingWaterServices;
use app\services\store\finance\StoreFinanceFlowServices;
use app\services\store\StoreStaffShiftHandoverServices;
use app\services\store\StoreUserServices;
use app\services\store\SystemStoreServices;
use app\services\system\AdminTableColumnServices;
use app\services\system\SystemRoleServices;
use app\services\user\UserServices;
use app\services\work\WorkMemberServices;
use mohe\exceptions\AdminException;
use think\facade\App;
use think\facade\Db;
use app\controller\store\AuthController;
use app\services\store\SystemStoreStaffServices;

/**
 * 店员
 * Class SystemStoreStaff
 * @package app\controller\store\staff
 */
class StoreStaff extends AuthController
{
    protected $level = null;

    /**
     * @var SystemStoreStaffServices
     */
    protected $services;

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
        $this->level = $this->storeStaffInfo['level'] ?? 0;
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
     * 当前门店可选岗位。与人员完整保存使用同一岗位发布策略，避免前端展示不可保存的岗位。
     */
    public function selectablePositions()
    {
        /** @var \app\services\organization\JobPositionPolicyServices $positions */
        $positions = app()->make(\app\services\organization\JobPositionPolicyServices::class);
        return $this->success($positions->listStoreSelectablePositions((int)$this->storeId));
    }

    /**
     * 获取店员列表
     * @return mixed
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['keyword', ''],
            ['field_key', ''],
            ['status', ''],
        ]);
        if ($where['field_key'] == 'all') $where['field_key'] = '';
        $where['store_id'] = $this->storeId;
//        if ($this->level) {
//            $where['level'] = $this->level + 1;
//        }
        $where['is_del'] = 0;
        if ($where['status'] === '') {
            unset($where['status']);
        } else {
            $where['status'] = (int)$where['status'];
        }
        return app('json')->success($this->services->getStoreStaffList($where, [], true));
    }

    /**
     * 获取店员select
     * @param SystemStoreStaffServices $services
     * @return mixed
     */
    public function getStaffSelect()
    {
        $where['store_id'] = $this->storeId;
        $where['is_del'] = 0;
        $where['status'] = 1;
        /** @var \app\services\store\StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(\app\services\store\StoreStaffScheduleServices::class);
        if (!$scheduleServices->isScheduleManageEnabled()) {
            $where['is_reservable'] = 1;
        }
        return app('json')->success($this->services->getSelectList($where));
    }

    /**
     * 获取某个店员信息
     * @param $id
     * @return mixed
     */
    public function read($id)
    {
        if (!$id) {
            return app('json')->fail('缺少店员id');
        }
        try {
            $this->assertStaffInCurrentStore((int)$id);
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        }
        return app('json')->success($this->services->read((int)$id, true));
    }

    /**
     * 店员
     * @param $id
     * @return mixed
     */
    public function staffDetail($id)
    {
        $data = $this->request->getMore([
            ['type', ''],
        ]);
        $id = (int)$id;
        if ($data['type'] == '' || !$id) return $this->fail('缺少参数');
        return $this->success($this->services->staffDetail($id, $data['type']));
    }

    /**
     * 店员新增表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function create()
    {
        return app('json')->success($this->services->createStoreStaffForm((int)$this->storeId, $this->storeStaffInfo['level'] + 1));
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
        return app('json')->success($this->services->updateStoreStaffForm($id, $this->storeStaffInfo['level'] + 1));
    }

	/**
	 * 保存店员信息
	 * @param $id
	 * @return \think\Response|void
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function save($id = 0)
    {
        $raw = $this->request->post();
        if (!is_array($raw)) {
            $raw = [];
        }
        try {
            \app\services\store\SystemStoreStaffServices::assertNoLegacyStaffAuthBypass($raw);
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        }
        $id = (int)$id;
        $employeeId = 0;
        if ($id > 0) {
            try {
                $staff = $this->assertStaffInCurrentStore($id);
                $employeeId = (int)($staff['employee_id'] ?? 0);
            } catch (AdminException $e) {
                return app('json')->fail($e->getMessage());
            }
        }

        $headerToken = trim((string)$this->request->header('X-Request-Token', ''));
        if ($headerToken === '') {
            $headerToken = trim((string)$this->request->header('Request-Token', ''));
        }
        $bodyToken = trim((string)($raw['request_token'] ?? $this->request->param('request_token', '')));
        $avatar = trim((string)($raw['avatar'] ?? ''));
        if ($avatar === '' && !empty($raw['image']) && is_array($raw['image'])) {
            $avatar = (string)($raw['image']['image'] ?? '');
        }

        $positionIds = $raw['position_ids'] ?? null;
        if ($positionIds === null && $id > 0) {
            $positionIds = array_map(static function ($j) {
                return (int)($j['position_id'] ?? 0);
            }, app()->make(\app\services\organization\StaffJobPositionServices::class)->listActiveJobs($id));
        }
        $scopeMode = array_key_exists('scope_mode', $raw)
            ? trim((string)$raw['scope_mode'])
            : '';
        if ($scopeMode === '' && $employeeId > 0) {
            $scopes = app()->make(\app\services\organization\EmployeeDataScopeServices::class)
                ->listScopes($employeeId, (int)$this->storeId);
            $scopeMode = 'personal';
            foreach ($scopes as $sc) {
                if ((string)($sc['source_type'] ?? '') === 'store'
                    && (int)($sc['source_store_id'] ?? 0) === (int)$this->storeId) {
                    $scopeMode = (string)($sc['scope_mode'] ?? 'personal');
                    break;
                }
            }
        }
        if ($scopeMode === '') {
            $scopeMode = 'personal';
        }
        $account = trim((string)($raw['account'] ?? ''));
        if ($account === '' && $employeeId > 0) {
            $acct = Db::name('employee_internal_account')
                ->where('employee_id', $employeeId)->where('is_del', 0)->value('account');
            $account = trim((string)$acct);
        }

        $input = [
            'employee_id' => $employeeId,
            'staff_id' => $id,
            'org_id' => (int)($raw['org_id'] ?? 0),
            'store_id' => (int)$this->storeId,
            'staff_name' => trim((string)($raw['staff_name'] ?? '')),
            'phone' => trim((string)($raw['phone'] ?? '')),
            'avatar' => $avatar,
            'account' => $account,
            'pwd' => (string)($raw['pwd'] ?? ''),
            'position_ids' => is_array($positionIds) ? $positionIds : [],
            'scope_mode' => $scopeMode,
            'org_ids' => [],
            'store_ids' => [],
            'status' => (int)($raw['status'] ?? 1),
            'cashier_salesperson_enabled' => (int)($raw['cashier_salesperson_enabled'] ?? 1),
            'cashier_craftsman_enabled' => (int)($raw['cashier_craftsman_enabled'] ?? 1),
            'request_token' => $bodyToken,
        ];
        // 档案字段全部由人员完整保存编排在同一事务内落库；不接受角色、规则或门店范围等扩权字段。
        foreach ([
            'work_member_id', 'notify', 'is_customer', 'customer_url', 'is_reservable',
            'employee_number', 'id_card', 'age', 'join_area', 'join_date', 'birthday_date',
            'birthday_type', 'birthday_area', 'now_area', 'contract_begin', 'contract_end',
            'salary_status', 'department',
        ] as $key) {
            if (array_key_exists($key, $raw)) {
                $input[$key] = $raw[$key];
            }
        }
        foreach (['employment_type_code', 'employment_type_version'] as $key) {
            if (array_key_exists($key, $raw)) {
                $input[$key] = $raw[$key];
            }
        }
        foreach (['roles', 'role_ids', 'save_roles', 'is_manager', 'is_butler', 'position', 'position_level'] as $k) {
            if (array_key_exists($k, $raw)) {
                $input[$k] = $raw[$k];
            }
        }

        try {
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            app()->make(\app\services\organization\OrganizationOpsStatusServices::class)
                ->assertStoreBusinessWritable((int)$this->storeId);
            /** @var \app\services\employee\EmployeePersonCompleteWriteServices $complete */
            $complete = app()->make(\app\services\employee\EmployeePersonCompleteWriteServices::class);
            $ret = $complete->saveComplete(
                $input,
                [
                    'id' => (int)$this->storeStaffId,
                    'level' => 1,
                    'admin_type' => 3,
                    'account' => (string)($this->storeStaffInfo['account'] ?? ''),
                    'real_name' => (string)($this->storeStaffInfo['staff_name'] ?? ''),
                ],
                [
                    'header_token' => $headerToken,
                    'body_token' => $bodyToken,
                    'operator_ip' => (string)$this->request->ip(),
                ],
                'store'
            );
            $data = is_array($ret['data'] ?? null) ? $ret['data'] : [];
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage() ?: '保存失败');
        }
        return app('json')->success($id ? '编辑成功' : '添加成功', $data);
    }

    /**
     * 人员完整详情（含岗位与数据权限）；:id 为 staff_id
     */
    public function personComplete($id = 0)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return app('json')->fail('缺少店员id');
        }
        try {
            $staff = $this->assertStaffInCurrentStore($id);
            $employeeId = (int)($staff['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                return app('json')->fail('该店员未关联员工主档');
            }
            /** @var \app\services\employee\EmployeePersonCompleteWriteServices $complete */
            $complete = app()->make(\app\services\employee\EmployeePersonCompleteWriteServices::class);
            return app('json')->success($complete->getComplete($employeeId, $id, 'store'));
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage() ?: '读取失败');
        }
    }

    /**
     * 校验店员属于当前门店
     * @param int $id
     * @return array|\think\Model
     */
    protected function assertStaffInCurrentStore(int $id)
    {
        $staff = $this->services->get($id);
        if (!$staff || (int)($staff['is_del'] ?? 0) === 1 || (int)$staff['store_id'] !== (int)$this->storeId) {
            throw new AdminException('店员不存在');
        }
        return $staff;
    }

    /**
     * 店员专属客户
     * @param UserServices $userServices
     * @param int $id
     * @return mixed
     */
    public function getStaffCustomer(UserServices $userServices, $id = 0)
    {
        $id = (int)$id;
        if (!$id) {
            return app('json')->fail('参数有误！');
        }
        try {
            $this->assertStaffInCurrentStore($id);
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        }
        $where = $this->request->getMore([
            ['keyword', ''],
            ['data', ''],
        ]);
        $where['salesman_id'] = $id;
        return app('json')->success($userServices->getStaffCustomerList($where));
    }

    /**
     * 店员业绩订单
     * @param StaffFlowingWaterServices $waterServices
     * @param int $id
     * @return mixed
     */
    public function getStaffPerformance(StaffFlowingWaterServices $waterServices, $id = 0)
    {
        $id = (int)$id;
        if (!$id) {
            return app('json')->fail('参数有误！');
        }
        try {
            $this->assertStaffInCurrentStore($id);
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        }
        $where = $this->request->getMore([
            ['data', ''],
            ['keyword', ''],
            ['link_id', ''],
            ['price', ''],
            ['performance', ''],
        ]);
        $where['staff_id'] = $id;
        $where['store_id'] = (int)$this->storeId;
        return app('json')->success($waterServices->getgetStaffPerformanceData($where));
    }

    /**
     * 获取列表列配置
     * @param AdminTableColumnServices $columnServices
     * @return mixed
     */
    public function getColumnSetting(AdminTableColumnServices $columnServices)
    {
        [$tableKey] = $this->request->getMore([['table_key', '']], true);
        $tableKey = $tableKey ?: 'staff_list_store';
        return app('json')->success($columnServices->getColumnSetting(2, (int)$this->storeStaffId, $tableKey));
    }

    /**
     * 保存列表列配置
     * @param AdminTableColumnServices $columnServices
     * @return mixed
     */
    public function saveColumnSetting(AdminTableColumnServices $columnServices)
    {
        $data = $this->request->postMore([
            ['table_key', 'staff_list_store'],
            ['columns', []],
        ]);
        try {
            $columnServices->saveColumnSetting(2, (int)$this->storeStaffId, (string)$data['table_key'], $data['columns']);
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        }
        return app('json')->success('保存成功');
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
            return app('json')->fail('缺少参数');
        }
        $id = (int)$id;
        $isShow = (int)$is_show;
        if ($isShow === 1) {
            return app('json')->fail('请通过编辑员工重新启用并选择门店角色');
        }
        if ($isShow !== 0) {
            return app('json')->fail('参数有误');
        }
        try {
            // H3：写门禁 → 本店范围校验 → leaveStoreAssignment
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            $this->assertStaffInCurrentStore($id);
            /** @var \app\services\employee\EmployeeStaffWriteServices $write */
            $write = app()->make(\app\services\employee\EmployeeStaffWriteServices::class);
            $write->leaveStoreAssignment($id, [
                'operator_id' => (int)$this->storeStaffId,
                'operator_name' => (string)($this->storeStaffInfo['staff_name'] ?? $this->storeStaffInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'store',
                'reason' => '门店后台关闭本店任职',
            ], false);
            return app('json')->success('关闭成功');
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage() ?: '关闭失败');
        }
    }

    /**
     * 修改当前登录店员信息
     * @return mixed
     */
    public function updateStaffPwd()
    {
        $data = $this->request->postMore([
            ['real_name', ''],
            ['pwd', ''],
            ['new_pwd', ''],
            ['conf_pwd', ''],
            ['avatar', ''],
        ]);
        if (!preg_match('/^(?![^a-zA-Z]+$)(?!\D+$).{6,}$/', $data['new_pwd'])) {
            return $this->fail('设置的密码过于简单(不小于六位包含数字字母)');
        }
        if ($this->services->updateStaffPwd($this->storeStaffId, $data))
            return $this->success('修改成功');
        else
            return $this->fail('修改失败');
    }

    /**
     * 删除店员
     * @param $id
     */
    public function delete($id)
    {
        $id = (int)$id;
        if (!$id) {
            return app('json')->fail('数据不存在');
        }
        try {
            // H3：写门禁 → 本店范围校验 → leaveStoreAssignment
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            $staff = $this->assertStaffInCurrentStore($id);
            if (!(int)($staff['level'] ?? 0)) {
                return app('json')->fail('门店超级管理员账号不能删除');
            }
            /** @var \app\services\employee\EmployeeStaffWriteServices $write */
            $write = app()->make(\app\services\employee\EmployeeStaffWriteServices::class);
            $write->leaveStoreAssignment($id, [
                'operator_id' => (int)$this->storeStaffId,
                'operator_name' => (string)($this->storeStaffInfo['staff_name'] ?? $this->storeStaffInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'store',
                'reason' => '门店后台删除本店任职',
            ], true);
            return app('json')->success('删除成功!');
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage() ?: '删除失败,请稍候再试!');
        }
    }

    /**
     * 店员绑定uid
     * @param UserServices $userServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function bandingUser(UserServices $userServices, StoreUserServices $storeUserServices)
    {
        // I1：停止通过商城用户写/清零 staff.uid；历史 uid 保留不动
        return app('json')->fail('已停止通过商城用户绑定店员账号，请使用员工手机号与任职管理');
    }

    /**
     * 店员交易统计
     * @param StoreFinanceFlowServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function statistics(StoreFinanceFlowServices $services, BranchOrderServices $orderServices)
    {
        $where = $this->request->getMore([
            ['staff_id', -1],
            ['time_key', 'add_time'],
            ['data', '', '', 'time'],
            ['type', 0]
        ]);
        if ($where['time_key'] && !in_array($where['time_key'], ['add_time', 'delivery_time', 'write_time'])) {
            return app('json')->fail('参数错误');
        }
        $where['time_key'] = 'add_time';
        $where['staff_id'] = $where['staff_id'] ?: -1;
        $where['store_id'] = $this->storeId;
        $where['trade_type'] = 2;
        if (!$where['type']) {
            $where['type'] = [7, 8, 9, 10, 11, 12, 13];
        } elseif ($where['type'] == 11) {
            $where['type'] = [11, 12, 13];
        }
        $where['time'] = $orderServices->timeHandle($where['time']);
        $where['is_del'] = 0;
        return app('json')->success($services->getList($where));
    }

    /**
     * 店员业绩
     * @param StaffFlowingWaterServices $services
     * @param BranchOrderServices $orderServices
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStaffStatistics(StaffFlowingWaterServices $services, BranchOrderServices $orderServices)
    {
        $where = $this->request->getMore([
            ['staff_id', -1],
            ['time_key', 'add_time'],
            ['data', '', '', 'time'],
            ['type', 0]
        ]);
        if ($where['time_key'] && !in_array($where['time_key'], ['add_time', 'delivery_time', 'write_time'])) {
            return app('json')->fail('参数错误');
        }
        $where['time_key'] = 'add_time';
        $where['staff_id'] = $where['staff_id'] ?: -1;
        $where['store_id'] = $this->storeId;
        $where['time'] = $orderServices->timeHandle($where['time']);
        $where['is_del'] = 0;
        return app('json')->success($services->getList($where));
    }

    /**
     * 店员交易统计头部数据
     * @return mixed
     */
    public function getStaffStatisticsHeader(StaffFlowingWaterServices $services, BranchOrderServices $orderServices)
    {
        $where = $this->request->getMore([
            ['staff_id', -1],
            ['time_key', 'add_time'],
            ['data', '', '', 'time'],
            ['type', 0]
//            ['group', '']
        ]);
        if ($where['time_key'] && !in_array($where['time_key'], ['add_time', 'delivery_time', 'write_time'])) {
            return app('json')->fail('参数错误');
        }
        $where['time_key'] = 'add_time';
        $where['staff_id'] = $where['staff_id'] ?: -1;
        $where['store_id'] = $this->storeId;
        $where['is_del'] = 0;
        if ($where['staff_id'] == -1) {
            $where['time'] = $orderServices->timeHandle($where['time']);
            $data = $services->getStatisticsHeader($where);
        } else {
            $time = $orderServices->timeHandle($where['time'], true);
            $data = $services->getTypeHeader($where, $time);
        }
        return app('json')->success($data);
    }

    public function statisticsHeader(StoreFinanceFlowServices $services, BranchOrderServices $orderServices)
    {
        $where = $this->request->getMore([
            ['staff_id', -1],
            ['time_key', 'add_time'],
            ['data', '', '', 'time'],
            ['type', 0]
//            ['group', '']
        ]);
        if ($where['time_key'] && !in_array($where['time_key'], ['add_time', 'delivery_time', 'write_time'])) {
            return app('json')->fail('参数错误');
        }
        $where['time_key'] = 'add_time';
        $where['staff_id'] = $where['staff_id'] ?: -1;
        $where['store_id'] = $this->storeId;
        $where['trade_type'] = 2;
        if (!$where['type']) {
            $where['type'] = [7, 8, 9, 10, 11, 12, 13];
        } elseif ($where['type'] == 11) {
            $where['type'] = [11, 12, 13];
        }
        $where['is_del'] = 0;
        if ($where['staff_id'] == -1) {
            $where['time'] = $orderServices->timeHandle($where['time']);
            $data = $services->getStatisticsHeader($where);
        } else {
            $time = $orderServices->timeHandle($where['time'], true);
            $data = $services->getTypeHeader($where, $time);
        }
        return app('json')->success($data);
    }

    /**
     * 获取登录店员详情
     * @return mixed
     */
    public function info()
    {
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $storeInfo = $storeServices->get($this->storeId, ['product_category_status','coupon_self_built_status']);
        $this->storeStaffInfo['product_category_status'] = $storeInfo['product_category_status'];
        $this->storeStaffInfo['coupon_self_built_status'] = $storeInfo['coupon_self_built_status'];
        return app('json')->success($this->storeStaffInfo);
    }

    /**
     * 登录收银台
     * @param \app\services\cashier\LoginServices $services
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function loginCashier(\app\services\cashier\LoginServices $services, $id)
    {
        $storeStaffInfo = $services->get($id);
        if (!$storeStaffInfo) {
            return app('json')->fail('账号不存在!');
        }
        if ($storeStaffInfo->is_del) {
            return app('json')->fail('账号不存在');
        }

        return app('json')->success($services->getLoginResult($id, 'cashier', $storeStaffInfo));
    }

    /**
     * 店员交接班
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getShiftHandover(StoreStaffShiftHandoverServices $services)
    {
        $where = $this->request->getMore([
            ['staff_id', ''],
            ['shift_time', ''],
        ]);
        $where['relation_id'] = $this->storeId;
        return app('json')->success($services->getStoreStaffShiftHandoverData($where));
    }

    /**
     * 店员交接班时业绩
     * @param StoreStaffShiftHandoverServices $services
     * @param int $staff_id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function shiftHandover(StoreStaffShiftHandoverServices $services, int $id = 0)
    {
        if(!$id) return app('json')->fail('参数有误！');
        $staffInfo = $services->get($id);
        if (!$staffInfo) {
            return app('json')->fail('记录不存在！');
        }
        return app('json')->success($services->getStaffShiftHandoverData($this->storeId,$staffInfo['staff_id'],$staffInfo['shift_start_time'],$staffInfo['shift_end_time'],true));
    }

    /** I1：门店发起调店申请（不改任职） */
    public function transferApplyCreate()
    {
        $data = $this->request->postMore([
            ['request_token', ''],
            [['source_staff_id', 'd'], 0],
            [['to_store_id', 'd'], 0],
            ['reason', ''],
        ]);
        try {
            /** @var \app\services\store\StoreStaffTransferApplyServices $svc */
            $svc = app()->make(\app\services\store\StoreStaffTransferApplyServices::class);
            $ret = $svc->createByStore($data, [
                'id' => (int)$this->storeStaffId,
                'store_id' => (int)$this->storeId,
                'name' => (string)($this->storeStaffInfo['staff_name'] ?? ''),
            ]);
            return app('json')->success('申请已提交', $ret);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** I2：门店可选总部已发布角色 */
    public function publishedRoles()
    {
        try {
            $list = app()->make(\app\services\organization\SystemRolePublishServices::class)
                ->listStoreSelectableRoles((int)$this->storeId);
            return app('json')->success([
                'list' => [],
                'help' => '人员功能权限只通过岗位配置，角色模板不再作为人员可选业务项。',
                'deprecated' => true,
            ]);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** 门店绑定已发布角色模板（已收口：人员只选岗位，禁止单独绑角色模板） */
    public function staffPublishedRolesSave($id = 0)
    {
        return app('json')->fail('人员功能权限只通过岗位配置，不能再单独选择角色模板');
    }

    /** I2：门店可选岗位 */
    public function selectableJobs()
    {
        try {
            $list = app()->make(\app\services\organization\JobPositionPolicyServices::class)
                ->listStoreSelectablePositions((int)$this->storeId);
            return app('json')->success([
                'list' => $list,
                'help' => '仅显示已启用且门店可用的岗位。关闭门店可用后，已有岗位可保留，但不能新增勾选。平台后台权限不会下发给门店员工。',
            ]);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * I2：门店保存本店岗位 + 数据权限 —— 委托人员完整保存编排（禁止半成功）
     * 渠道入口 entries 若提交则仍在同次事务后单独落库（使用派生 token）
     */
    public function staffAuthSave($id = 0)
    {
        $raw = $this->request->post();
        if (!is_array($raw)) {
            $raw = [];
        }
        try {
            \app\services\store\SystemStoreStaffServices::assertNoLegacyStaffAuthBypass($raw);
        } catch (AdminException $e) {
            return app('json')->fail($e->getMessage());
        }
        $data = $this->request->postMore([
            [['position_ids', 'a'], []],
            [['role_ids', 'a'], []],
            [['entries', 'a'], []],
            [['scope_mode', 's'], 'personal'],
            [['request_token', 's'], ''],
            [['reason', 's'], ''],
            [['save_jobs', 'd'], 1],
            [['save_roles', 'd'], 0],
            [['save_entries', 'd'], 1],
            [['save_scope', 'd'], 1],
        ]);
        try {
            $staff = $this->assertStaffInCurrentStore((int)$id);
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            app()->make(\app\services\organization\OrganizationOpsStatusServices::class)
                ->assertStoreBusinessWritable((int)$this->storeId);

            $employeeId = (int)($staff['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                return app('json')->fail('该店员未关联员工主档，请先关联后再设置岗位与权限');
            }
            if ((int)$data['save_roles'] === 1 || (is_array($data['role_ids']) && count(array_filter(array_map('intval', $data['role_ids']))) > 0)) {
                return app('json')->fail('人员功能权限只通过岗位配置，不能再单独选择角色模板');
            }

            $emp = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->find();
            if (!$emp) {
                return app('json')->fail('员工主档不存在');
            }
            $acct = Db::name('employee_internal_account')
                ->where('employee_id', $employeeId)->where('is_del', 0)->find();

            $headerToken = trim((string)$this->request->header('X-Request-Token', ''));
            if ($headerToken === '') {
                $headerToken = trim((string)$this->request->header('Request-Token', ''));
            }
            $bodyToken = trim((string)($data['request_token'] ?? ''));
            $adminInfo = [
                'id' => (int)$this->storeStaffId,
                'level' => 1,
                'admin_type' => 3,
                'account' => (string)($this->storeStaffInfo['account'] ?? ''),
                'real_name' => (string)($this->storeStaffInfo['staff_name'] ?? ''),
            ];

            /** @var \app\services\organization\StaffJobPositionServices $jobSvc */
            $jobSvc = app()->make(\app\services\organization\StaffJobPositionServices::class);
            $positionIds = (int)$data['save_jobs'] === 1
                ? (is_array($data['position_ids']) ? $data['position_ids'] : [])
                : array_map(static function ($j) {
                    return (int)($j['position_id'] ?? 0);
                }, $jobSvc->listActiveJobs((int)$id));

            $scopeMode = (int)$data['save_scope'] === 1
                ? trim((string)$data['scope_mode'])
                : 'personal';
            if ((int)$data['save_scope'] !== 1) {
                $scopes = app()->make(\app\services\organization\EmployeeDataScopeServices::class)
                    ->listScopes($employeeId, (int)$this->storeId);
                foreach ($scopes as $sc) {
                    if ((string)($sc['source_type'] ?? '') === 'store'
                        && (int)($sc['source_store_id'] ?? 0) === (int)$this->storeId) {
                        $scopeMode = (string)($sc['scope_mode'] ?? 'personal');
                        break;
                    }
                }
            }

            $input = [
                'employee_id' => $employeeId,
                'staff_id' => (int)$id,
                'org_id' => 0,
                'store_id' => (int)$this->storeId,
                'staff_name' => (string)($emp['name'] ?? $staff['staff_name'] ?? ''),
                'phone' => (string)($emp['phone'] ?? $staff['phone'] ?? ''),
                'avatar' => (string)($emp['avatar'] ?? $staff['avatar'] ?? ''),
                'account' => $acct ? (string)$acct['account'] : (string)($staff['account'] ?? ''),
                'pwd' => '',
                'position_ids' => $positionIds,
                'scope_mode' => $scopeMode,
                'org_ids' => [],
                'store_ids' => [],
                'request_token' => $bodyToken,
            ];
            if ((int)$data['save_entries'] === 1) {
                $entries = is_array($data['entries']) ? $data['entries'] : [];
                foreach ($entries as $e) {
                    $ch = is_array($e) ? trim((string)($e['channel'] ?? '')) : '';
                    if ($ch === 'platform') {
                        return app('json')->fail('门店不能开通或关闭平台后台入口');
                    }
                }
                $input['entries'] = $entries;
            }

            /** @var \app\services\employee\EmployeePersonCompleteWriteServices $complete */
            $complete = app()->make(\app\services\employee\EmployeePersonCompleteWriteServices::class);
            $ret = $complete->saveComplete(
                $input,
                $adminInfo,
                [
                    'header_token' => $headerToken,
                    'body_token' => $bodyToken,
                    'operator_ip' => (string)$this->request->ip(),
                ],
                'store'
            );
            $out = is_array($ret['data'] ?? null) ? $ret['data'] : [];
            $out['staff_id'] = (int)$id;
            $out['store_id'] = (int)$this->storeId;
            $out['data_scope'] = $out['scope'] ?? [];

            $out['help'] = [
                'jobs' => '请选择总部允许本店使用的岗位；功能权限由岗位决定，人员不再单独选择角色模板。',
                'entries' => '只能开通本店门店后台、收银台、手机端；开通前须已有岗位覆盖对应端。',
                'data_scope' => '只能设置个人或本店；选择个人只撤销本店来源的扩展授权，不影响总部或其他门店授权。岗位变化不会扩大数据权限。',
                'summary' => '岗位决定能操作哪些功能，人员数据权限决定能看到哪些数据。',
                'projected_roles' => '系统按岗位自动投影的功能权限载体，仅供查看。',
            ];
            return app('json')->success('保存成功', $out);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * 从 UUID 派生子操作 token（同请求多写避免幂等冲突）
     */
    protected function deriveRequestToken(string $base, string $suffix): string
    {
        $base = strtolower(trim($base));
        if ($base === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $base)) {
            return $base;
        }
        $hash = substr(hash('sha256', $base . '|' . $suffix), 0, 12);
        // 保持 UUID 形态：替换最后一段
        return substr($base, 0, 24) . substr($hash, 0, 12);
    }

    public function transferApplyList()
    {
        [$status, $page, $limit] = $this->request->getMore([
            ['status', ''],
            [['page', 'd'], 1],
            [['limit', 'd'], 20],
        ], true);
        try {
            /** @var \app\services\store\StoreStaffTransferApplyServices $svc */
            $svc = app()->make(\app\services\store\StoreStaffTransferApplyServices::class);
            return app('json')->success($svc->getList([
                'status' => (string)$status,
                'applicant_store_id' => (int)$this->storeId,
            ], (int)$page, (int)$limit));
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    public function transferApplyCancel($id = 0)
    {
        try {
            /** @var \app\services\store\StoreStaffTransferApplyServices $svc */
            $svc = app()->make(\app\services\store\StoreStaffTransferApplyServices::class);
            $ret = $svc->cancelByStore((int)$id, (int)$this->storeId, [
                'id' => (int)$this->storeStaffId,
            ]);
            return app('json')->success('已取消', $ret);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /** I1：单店离职 */
    public function storeLeave($id = 0)
    {
        try {
            $this->assertStaffInCurrentStore((int)$id);
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            /** @var \app\services\employee\EmployeeStaffWriteServices $write */
            $write = app()->make(\app\services\employee\EmployeeStaffWriteServices::class);
            $write->leaveStoreAssignment((int)$id, [
                'operator_id' => (int)$this->storeStaffId,
                'operator_name' => (string)($this->storeStaffInfo['staff_name'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'store',
                'reason' => '门店单店离职',
            ]);
            return app('json')->success('已办理本店离职');
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * I2：门店读取本店员工岗位 / 三端入口 / 数据权限（编辑回显）
     */
    public function staffAuthDetail($id = 0)
    {
        try {
            $staff = $this->assertStaffInCurrentStore((int)$id);
            $employeeId = (int)($staff['employee_id'] ?? 0);
            /** @var \app\services\organization\StaffJobPositionServices $jobSvc */
            $jobSvc = app()->make(\app\services\organization\StaffJobPositionServices::class);
            $jobs = $jobSvc->listActiveJobs((int)$id);
            $positionIds = array_values(array_map(static function ($j) {
                return (int)($j['position_id'] ?? 0);
            }, $jobs));
            $entriesRaw = $jobSvc->listEntries((int)$id);
            $entries = [
                'store_backend' => 0,
                'cashier' => 0,
                'mobile' => 0,
            ];
            foreach ($entriesRaw as $row) {
                $ch = (string)($row['channel'] ?? '');
                if (isset($entries[$ch])) {
                    $entries[$ch] = ((int)($row['status'] ?? 0) === 1 && (int)($row['is_del'] ?? 0) === 0) ? 1 : 0;
                }
            }

            $scopeMode = 'personal';
            if ($employeeId > 0) {
                /** @var \app\services\organization\EmployeeDataScopeServices $scopeSvc */
                $scopeSvc = app()->make(\app\services\organization\EmployeeDataScopeServices::class);
                $scopes = $scopeSvc->listScopes($employeeId, (int)$this->storeId);
                foreach ($scopes as $sc) {
                    if ((string)($sc['source_type'] ?? '') === 'store'
                        && (int)($sc['source_store_id'] ?? 0) === (int)$this->storeId) {
                        $mode = (string)($sc['scope_mode'] ?? 'personal');
                        if (in_array($mode, ['personal', 'store_self'], true)) {
                            $scopeMode = $mode;
                        }
                        break;
                    }
                }
            }

            $roleIds = array_values(array_filter(array_map('intval', explode(',', (string)($staff['roles'] ?? '')))));
            $projectedRoles = [];
            $boundRoleIds = [];
            $selectable = app()->make(\app\services\organization\SystemRolePublishServices::class)
                ->listStoreSelectableRoles((int)$this->storeId);
            $selectableIds = array_column($selectable, 'value');
            if ($roleIds) {
                $roleRows = Db::name('system_role')->whereIn('id', $roleIds)->field('id,role_name')->select()->toArray();
                foreach ($roleRows as $rr) {
                    $rid = (int)$rr['id'];
                    $name = (string)($rr['role_name'] ?? '');
                    $isProjected = strpos($name, '岗位投影-') === 0;
                    $projectedRoles[] = [
                        'id' => $rid,
                        'label' => $name,
                        'is_projected' => $isProjected ? 1 : 0,
                    ];
                    if (!$isProjected && in_array($rid, $selectableIds, true)) {
                        $boundRoleIds[] = $rid;
                    } elseif (!$isProjected) {
                        // 历史已绑定但当前不可新选的模板：仍回显，门店可保留
                        $boundRoleIds[] = $rid;
                    }
                }
            }

            return app('json')->success([
                'staff_id' => (int)$id,
                'employee_id' => $employeeId,
                'status' => (int)($staff['status'] ?? 0),
                'position_ids' => $positionIds,
                'role_ids' => array_values(array_unique($boundRoleIds)),
                'selectable_roles' => $selectable,
                'jobs' => $jobs,
                'entries' => $entries,
                'scope_mode' => $scopeMode,
                'projected_roles' => $projectedRoles,
                'function_preview' => $jobSvc->buildFunctionPreview((int)$id),
                'help' => [
                    'summary' => '岗位决定能操作哪些功能，人员数据权限决定能看到哪些数据。',
                    'jobs' => '请选择总部允许本店使用的岗位；能看哪些功能菜单，由岗位决定，不能直接勾功能。',
                    'roles' => '可选择总部已发布且允许本店使用的角色模板；不能修改模板权限正文，不能选择平台后台权限。',
                    'entries' => '只能开通本店的门店后台、收银台、手机端。开通前须已有岗位覆盖对应端，否则无法保存。',
                    'data_scope' => '只能设「个人」或「本店」。选个人只取消本店给的扩展查看范围，不影响总部或其他门店授权。岗位变化不会扩大数据权限。',
                    'projected_roles' => '以下为岗位自动生成的功能身份，仅供查看，不能在此勾选修改。',
                ],
            ]);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    /**
     * I2：本店停职 / 复职 / 软删除（同源 StaffTenureServices，仅允许本店 staff）
     */
    public function staffTenure($id = 0)
    {
        $data = $this->request->postMore([
            [['action', 's'], ''],
            [['reason', 's'], ''],
            [['request_token', 's'], ''],
        ]);
        try {
            $staff = $this->assertStaffInCurrentStore((int)$id);
            if (!(int)($staff['level'] ?? 0) && trim((string)$data['action']) === 'delete') {
                return app('json')->fail('门店超级管理员账号不能删除');
            }
            $employeeId = (int)($staff['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                return app('json')->fail('该店员未关联员工主档，无法办理停职/复职');
            }
            app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class)->assertCanWrite();
            app()->make(\app\services\organization\OrganizationOpsStatusServices::class)
                ->assertStoreBusinessWritable((int)$this->storeId);

            $adminInfo = [
                'id' => (int)$this->storeStaffId,
                'level' => 1,
                'admin_type' => 3,
                'account' => (string)($this->storeStaffInfo['account'] ?? ''),
                'real_name' => (string)($this->storeStaffInfo['staff_name'] ?? ''),
            ];
            $headerToken = (string)$this->request->header('Request-Token', '');
            $ctx = [
                'header_token' => trim($headerToken),
                'body_token' => trim((string)($data['request_token'] ?? '')),
                'operator_ip' => (string)$this->request->ip(),
            ];
            $ret = app()->make(\app\services\organization\StaffTenureServices::class)->runAction(
                $employeeId,
                [
                    'staff_id' => (int)$id,
                    'action' => (string)$data['action'],
                    'reason' => (string)$data['reason'],
                ],
                $adminInfo,
                $ctx,
                false
            );
            return app('json')->success($ret['msg'] ?? '操作成功', $ret['data'] ?? []);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
    }
}
