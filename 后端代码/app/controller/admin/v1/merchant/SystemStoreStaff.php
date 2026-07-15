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

use app\services\agent\SystemRegionAgentServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\store\finance\StaffFlowingWaterServices;
use app\services\system\SystemRoleServices;
use app\services\user\UserServices;
use app\services\work\WorkMemberServices;
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
	 * 获取店员列表
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            [['store_id', 'd'], 0],
        ]);
		if (!$where['store_id'] && $this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
			if ($storeIds) {
				$where['store_id'] = $storeIds;
			} else {
				return $this->success(['list' => [], 'count' => 0]);
			}
		}
        return $this->success($this->services->getStoreStaffList($where, ['store', 'user']));
    }

	/**
	 * 获取店员列表
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getStoreStaffList(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['store_id', 0],
            ['keyword', ''],
        ]);
        $where['status'] = 1;
        $where['is_del'] = 0;
		if (!$where['store_id'] && $this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
			if ($storeIds) {
				$where['store_id'] = $storeIds;
			} else {
				return $this->success(['list' => [], 'count' => 0]);
			}
		}
        return $this->success($this->services->getStoreStaffListData($where, ['store']));
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
        return $this->success($this->services->getSelectList($where));
    }

    /**
     * 获取店员专属客户
     * @param UserServices $userServices
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
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
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function store_list(SystemStoreServices $services, SystemRegionAgentServices $regionAgentServices)
    {
		$where = ['status' => 1];
		if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
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
            /** @var SystemRegionAgentServices $regionAgentServices */
            $regionAgentServices = app()->make(SystemRegionAgentServices::class);
            $storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
            if (!$storeIds) {
                return false;
            }
            $storeIds = is_array($storeIds) ? $storeIds : [$storeIds];
            return in_array($storeId, $storeIds);
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
     * 保存店员信息（总后台编辑）
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
        $this->validate($data, \app\validate\store\StoreStaffValidate::class, $id ? 'update' : 'save');
        $id = (int)$id;
        if ($id) {
            $staff = $this->services->get($id);
            if (!$staff) {
                return $this->fail('店员不存在');
            }
            if (!$this->checkStaffStoreAccess((int)$staff['store_id'])) {
                return $this->fail('无权操作该店员');
            }
            if (!$data['store_id']) {
                return $this->fail('请选择所属门店');
            }
            if (!$this->checkStaffStoreAccess((int)$data['store_id'])) {
                return $this->fail('无权将该店员调整至所选门店');
            }
        } else {
            return $this->fail('缺少店员id');
        }
        $data['is_store'] = 1;
        if ($data['pwd']) {
            $data['pwd'] = $this->services->passwordHash($data['pwd']);
        } else {
            unset($data['pwd']);
        }
        $accountStaff = $this->services->getOne(['account' => $data['account'], 'is_del' => 0]);
        if ($accountStaff && $id != $accountStaff->id) {
            return $this->fail('该员工账号已经存在');
        }
        $phoneStaff = $this->services->getOne(['store_id' => $data['store_id'], 'phone' => $data['phone'], 'is_del' => 0]);
        if ($phoneStaff && $id != $phoneStaff['id']) {
            return $this->fail('该手机号已经存在');
        }
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
        $data['order_status'] = 0;
        $data['is_cashier'] = 0;
        if (count($data['roles']) > 0) {
            /** @var SystemRoleServices $systemRoleService */
            $systemRoleService = app()->make(SystemRoleServices::class);
            $roles = $systemRoleService->getColumn(['id' => $data['roles']], '*');
            if ($roles) {
                foreach ($roles as $role) {
                    if ($role['mall_rules']) {
                        $data['order_status'] = 1;
                        break;
                    }
                    if ($role['cashier_rules']) {
                        $data['is_cashier'] = 1;
                    }
                }
            }
        }
        $res = $this->services->update($id, $data);
        if ($res) {
            return $this->success('编辑成功');
        }
        return $this->fail('编辑失败，请稍后再试');
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
