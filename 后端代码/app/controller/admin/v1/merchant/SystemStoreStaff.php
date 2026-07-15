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
            return null;
        }
        $where = [
            'keyword' => $params['keyword'],
            'is_del' => 0,
        ];
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
        if ($data['uid'] == 0) {
            return $this->fail('请选择用户');
        }
        if (!$id) {
            if ($data['image'] == '') {
                return $this->fail('请选择用户');
            }
            if ($this->services->count(['uid' => $data['uid'], 'store_id' => $data['store_id'], 'is_del' => 0])) {
                return $this->fail('添加的核销员用户已存在!');
            }
            $data['uid'] = $data['image']['uid'];
            $data['avatar'] = $data['image']['image'];
        } else {
            $data['avatar'] = $data['image'];
        }
        unset($data['image']);
        if ($id) {
            $res = $this->services->update($id, $data);
            if ($res) {
                return $this->success('编辑成功');
            } else {
                return $this->fail('编辑失败');
            }
        } else {
            $data['add_time'] = time();
            $res = $this->services->save($data);
            if ($res) {
                return $this->success('核销员添加成功');
            } else {
                return $this->fail('核销员添加失败，请稍后再试');
            }
        }
    }

    /**
     * 设置单个店员是否开启
     * @param string $is_show
     * @param string $id
     * @return mixed
     */
    public function set_show($is_show = '', $id = '')
    {
        if ($is_show == '' || $id == '') {
            $this->fail('缺少参数');
        }
        $res = $this->services->update($id, ['status' => (int)$is_show]);
        if ($res) {
            return $this->success($is_show == 1 ? '开启成功' : '关闭成功');
        } else {
            return $this->fail($is_show == 1 ? '开启失败' : '关闭失败');
        }
    }

    /**
     * 删除店员
     * @param $id
     */
    public function delete($id)
    {
        if (!$id) return $this->fail('数据不存在');
        if (!$this->services->delete($id))
            return $this->fail('删除失败,请稍候再试!');
        else
            return $this->success('删除成功!');
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
     * 保存店员信息（总后台编辑/新建）
     * @param int $id
     * @return mixed
     */
    public function saveStaff($id = 0)
    {
        $data = $this->request->postMore([
            ['image', ''],
            ['account', ''],
            ['uid', 0],
            ['avatar', ''],
            ['staff_name', ''],
            ['roles', []],
            ['phone', ''],
            ['verify_status', 1],
            ['is_manager', 0],
            ['is_cashier', 0],
            ['status', 1],
            ['salary_status', 1],
            ['conf_pwd', ''],
            ['pwd', ''],
            ['notify', 0],
            ['position', 0],
            ['position_level', 0],
            ['is_customer', 0],
            ['can_choose', 0],
            ['is_reservable', 1],
            ['is_butler', 0],
            ['is_fencheng', 0],
            ['customer_url', ''],
            [['work_member_id', 'd'], 0],
            ['department', ''],
            ['employee_number', ''],
            ['join_date', null],
            ['id_card', ''],
            ['birthday_date', ''],
            ['birthday_type', 1],
            ['age', 0],
            ['join_area', ''],
            ['birthday_area', ''],
            ['now_area', ''],
            ['contract_begin', null],
            ['contract_end', null],
            [['store_id', 'd'], 0],
        ]);
        $id = (int)$id;
        $this->validate($data, \app\validate\store\StoreStaffValidate::class, $id ? 'update' : 'save');
        $account = trim((string)$data['account']);
        if ($account !== '') {
            if (strlen($account) < 4 || strlen($account) > 64) {
                return $this->fail('门店店员账号长度4-64位字符');
            }
            if (!$id && !$data['pwd']) {
                return $this->fail('请输入密码');
            }
        }
        if ($id) {
            $staff = $this->services->get($id);
            if (!$staff) {
                return $this->fail('店员不存在');
            }
            $staffArr = is_object($staff) ? $staff->toArray() : (array)$staff;
            if (!$this->checkStaffStoreAccess((int)$staffArr['store_id'])) {
                return $this->fail('无权操作该店员');
            }
            $data['store_id'] = (int)$staffArr['store_id'];
        } else {
            if (!$data['store_id']) {
                return $this->fail('请选择所属门店');
            }
            if (!$this->checkStaffStoreAccess((int)$data['store_id'])) {
                return $this->fail('无权在该门店添加店员');
            }
            $data['is_fencheng'] = (int)($data['is_fencheng'] ?? 0);
            $data['level'] = 1;
            $data['add_time'] = time();
            if (!empty($data['image']) && is_array($data['image'])) {
                $data['uid'] = (int)($data['image']['uid'] ?? $data['uid']);
                $data['avatar'] = (string)($data['image']['image'] ?? $data['avatar']);
            }
        }
        $data['is_store'] = 1;
        if ($data['pwd']) {
            $data['pwd'] = $this->services->passwordHash($data['pwd']);
        } else {
            unset($data['pwd']);
        }
        try {
            $this->services->assertAccountUnique($account, $id);
            $this->services->assertPhoneUnique((string)$data['phone'], $id);
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        }
        $data['account'] = $account;
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        if ($data['uid']) {
            $userStaff = $this->services->getOne(['uid' => $data['uid'], 'is_del' => 0]);
            if ($userStaff && (int)$userStaff['store_id'] !== (int)$data['store_id'] && (int)$userStaff['id'] !== $id) {
                $store = $storeServices->get($userStaff['store_id']);
                return $this->fail('该用户已在（' . ($store['name'] ?? '') . '）门店存在!');
            }
        }
        $data['customer_phone'] = $data['phone'];
        if ($data['is_customer']) {
            $storeInfo = $storeServices->getStoreInfo((int)$data['store_id']);
            if ($storeInfo['customer_type'] == 1 && !$data['customer_phone']) {
                return $this->fail('请输入客服电话');
            }
            if ($storeInfo['customer_type'] == 2 && !$data['customer_url']) {
                return $this->fail('请选择客服二维码');
            }
        }
        $data['work_member_code'] = '';
        if (isset($data['work_member_id']) && $data['work_member_id']) {
            /** @var WorkMemberServices $workMemberService */
            $workMemberService = app()->make(WorkMemberServices::class);
            $data['work_member_code'] = $workMemberService->value(['id' => $data['work_member_id']], 'qr_code');
            $info = $this->services->get(['work_member_id' => $data['work_member_id'], 'is_del' => 0]);
            if ($info && (int)$info['id'] !== $id) {
                return $this->fail('该员工已绑定店员，不能重复绑定！');
            }
        }
        unset($data['conf_pwd'], $data['image']);
        $this->services->normalizeStaffAvatar($data);
        $this->services->normalizeStaffDates($data);
        $this->services->applyRolesFlags($data);
        if ($id) {
            $res = $this->services->update($id, $data);
            $msg = $res ? '编辑成功' : '编辑失败，请稍后再试';
        } else {
            $res = $this->services->save($data);
            $msg = $res ? '添加成功' : '添加失败，请稍后再试';
        }
        if ($res) {
            return $this->success($msg);
        }
        return $this->fail($msg);
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
