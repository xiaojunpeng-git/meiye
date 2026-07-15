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
        $data = $this->request->postMore([
            ['image', ''],
            ['account', ''],
            ['uid', 0],//绑定商城用户uid
            ['avatar', ''],//图像
            ['staff_name', ''],//店员名称
            ['roles', []],
            ['phone', ''],
            ['verify_status', 1],
//            ['order_status', 1],//移动端管理入口
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
            [['work_member_id', 'd'], 0],//关联企业微信员工ID
            ['department', ''],
            ['employee_number', ''],
            ['join_date', null],
            ['id_card', ''],
            ['birthday_date', ''],
            ['birthday_type',1],
            ['age',0],
            ['join_area',''],
            ['birthday_area',''],
            ['now_area',''],
            ['contract_begin',null],
            ['contract_end',null],
        ]);
        unset($data['is_fencheng']);
		$id = (int)$id;
		$this->validate($data, \app\validate\store\StoreStaffValidate::class, $id ? 'update' : 'save');
		if ($id) {//编辑
			$staff = $this->services->get($id);
			if (!$staff) {
				return app('json')->fail('店员不存在');
			}
		}
        $data['store_id'] = $this->storeId;
        $data['is_store'] = 1;
		$account = trim((string)$data['account']);
		if ($account !== '') {
			if (strlen($account) < 4 || strlen($account) > 64) {
				return app('json')->fail('门店店员账号长度4-64位字符');
			}
			if (!$id && !$data['pwd']) {
				return app('json')->fail('请输入员工密码');
			}
		}
		if ($data['pwd']) {//有修改密码
			$data['pwd'] = $this->services->passwordHash($data['pwd']);
		} else {
			unset($data['pwd']);
		}
		try {
			$this->services->assertAccountUnique($account, $id);
			$this->services->assertPhoneUnique((string)$data['phone'], $id);
		} catch (AdminException $e) {
			return app('json')->fail($e->getMessage());
		}
		$data['account'] = $account;
		/** @var SystemStoreServices $storeServices */
		$storeServices = app()->make(SystemStoreServices::class);
		if ($data['uid']) {
			$userStaff = $this->services->getOne(['uid' => $data['uid'], 'is_del' => 0]);
			if ($userStaff) {
				$store = $storeServices->get($userStaff['store_id']);
                if($store['id'] != $this->storeId) {
                    return $this->fail('该用户已在（' . ($store['name'] ?? '') . '）门店存在!');
                }
			}
		}
        //客户电话
        $data['customer_phone'] = $data['phone'];
        //是客服验证
        if ($data['is_customer']) {
            $storeInfo = $storeServices->getStoreInfo((int)$this->storeId);
            if ($storeInfo['customer_type'] == 1 && !$data['customer_phone']) {
                return app('json')->fail('请输入客服电话');
            }
            if ($storeInfo['customer_type'] == 2 && !$data['customer_url']) {
                return app('json')->fail('请选择客服二维码');
            }
        }
		$data['work_member_code'] = '';
		if (isset($data['work_member_id']) && $data['work_member_id']) {
			/** @var WorkMemberServices $workMemberService */
			$workMemberService = app()->make(WorkMemberServices::class);
			$data['work_member_code'] = $workMemberService->value(['id' => $data['work_member_id']], 'qr_code');
			$info = $this->services->get(['work_member_id' => $data['work_member_id'], 'is_del' => 0]);
			if ($info && (int)$info['id'] !== $id) {
				return app('json')->fail('该员工已绑定店员，不能重复绑定！');
			}
		}
        unset($data['conf_pwd'], $data['image']);
		$this->services->normalizeStaffAvatar($data);
		$this->services->normalizeStaffDates($data);
		$this->services->applyRolesFlags($data);

		if ($id) {//编辑
			$res = $this->services->update($id, $data);
		} else {
			$data['level'] = $this->storeStaffInfo['level'] + 1;
			$data['is_fencheng'] = 0;
			$data['add_time'] = time();
			$res = $this->services->save($data);
		}
        if ($res) {
            return app('json')->success($id ? '编辑成功' : '添加成功');
        } else {
            return app('json')->fail($id ? '编辑失败，请稍后再试' : '添加失败，请稍后再试');
        }
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
        if ($is_show == '' || $id == '') {
            $this->fail('缺少参数');
        }
        $res = $this->services->update($id, ['status' => (int)$is_show]);
        if ($res) {
            return app('json')->success($is_show == 1 ? '开启成功' : '关闭成功');
        } else {
            return app('json')->fail($is_show == 1 ? '开启失败' : '关闭失败');
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
        if (!$id) return app('json')->fail('数据不存在');
        $staff = $this->services->getStaffInfo((int)$id);
        if (!$staff['level']) {
            return app('json')->fail('门店超级管理员账号不能删除');
        }
        if (!$this->services->update($id, ['is_del' => 1]))
            return app('json')->fail('删除失败,请稍候再试!');
        else
            return app('json')->success('删除成功!');
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
        [$uid, $staff_id] = $this->request->postMore([
            ['uid', 0],
            ['staff_id', ''],
        ], true);
        if (!$uid || !$staff_id) {
            return app('json')->fail('缺少参数');
        }
        if (!$userServices->count(['uid' => $uid])) {
            return app('json')->fail('用户不存在');
        }
        $staffInfo = $this->services->getStaffInfo((int)$staff_id);
        if (!$staffInfo) {
            return app('json')->fail('店员不存在');
        }
        $res = $this->services->transaction(function () use ($storeUserServices, $staffInfo, $uid, $staff_id) {
            //清空该门店uid绑定其他店员
            $re = $this->services->update(['store_id' => $staffInfo['store_id'], 'uid' => $uid, 'is_del' => 0], ['uid' => 0]);
            $re = $re && $this->services->update($staff_id, ['uid' => $uid]);
            //写入门店用户
            return $re && $storeUserServices->setStoreUser((int)$uid, (int)$this->storeId);
        });
        if (!$res) {
            return app('json')->fail('设置失败');
        }
        return app('json')->success('修改成功');
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
}
