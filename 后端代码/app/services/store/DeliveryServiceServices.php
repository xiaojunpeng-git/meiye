<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\store;


use app\dao\store\DeliveryServiceDao;
use app\services\BaseServices;
use app\services\order\store\BranchOrderServices;
use app\services\message\service\StoreServiceLogServices;
use app\services\user\UserServices;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;


/**
 * 配送
 * Class DeliveryServiceServices
 * @package app\services\store
 * @mixin DeliveryServiceDao
 */
class DeliveryServiceServices extends BaseServices
{

    /**
     * @param DeliveryServiceDao $dao
     */
    public function __construct(DeliveryServiceDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取配送员详情
     * @param int $id
     * @param string $field
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDeliveryInfo(int $id, string $field = '*')
    {
        $info = $this->dao->getOne(['id' => $id, 'is_del' => 0], $field);
        if (!$info) {
            throw new ValidateException('配送员不存在');
        }
        return $info;
    }

    /**
     * 根据uid检查是否是配送员
     * @param int $uid
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkIsDeliveryByUid(int $uid)
    {
        return (bool)$this->dao->count(['uid' => $uid, 'status' => 1, 'is_del' => 0]);
    }

    /**
     * 根据uid获取配送员信息
     * @param int $uid
     * @param int $type
     * @param int $relation_id
     * @param array $field
     * @return array|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDeliveryInfoByUid(int $uid, int $type = -1, int $relation_id = -1, array $field = ['*'])
    {
        $where = ['uid' => $uid, 'is_del' => 0, 'status' => 1];
        if ($type != -1) {
            $where['type'] = $type;
        }
        if ($relation_id != -1) {
            $where['relation_id'] = $relation_id;
        }
        $info = $this->dao->get($where, $field);
        if (!$info) {
            throw new ValidateException('配送员不存在');
        }
        return $info;
    }

    /**
     * 获取配送员所在门店id
     * @param int $uid
     * @param int $type
     * @param string $field
     * @param string $key
     * @return array
     */
    public function getDeliveryStoreIds(int $uid, int $type = 0, string $field = '*', string $key = '')
    {
        return $this->dao->getColumn(['uid' => $uid, 'type' => $type, 'is_del' => 0, 'status' => 1], $field, $key);
    }

    /**
     * 获取单个配送员统计信息
     * @param int $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function deliveryDetail(int $id)
    {
        $deliveryInfo = $this->getDeliveryInfo($id);
        if (!$deliveryInfo) {
            throw new AdminException('配送员不存在');
        }
        $where = ['store_id' => $deliveryInfo['relation_id'], 'delivery_uid' => $deliveryInfo['uid']];
        /** @var BranchOrderServices $orderServices */
        $orderServices = app()->make(BranchOrderServices::class);
        $order_where = ['paid' => 1, 'refund_status' => [0, 3], 'is_del' => 0, 'is_system_del' => 0];

        $field = ['id', 'type', 'order_id', 'real_name', 'total_num', 'total_price', 'pay_price', 'FROM_UNIXTIME(pay_time,"%Y-%m-%d") as pay_time', 'paid', 'pay_type', 'activity_id', 'pink_id'];
        $list = $orderServices->getStoreOrderList($where + $order_where, $field, [], true);
        $data = ['id' => $id];
        $data['info'] = $deliveryInfo;
        $data['list'] = $list;
        $data['data'] = [
            [
                'title' => '待配送订单数',
                'value' => $orderServices->count($where + $order_where + ['status' => 2]),
                'key' => '单',
            ],
            [
                'title' => '已配送订单数',
                'value' => $orderServices->count($where + $order_where + ['status' => 3]),
                'key' => '单',
            ],
            [
                'title' => '待配送订单金额',
                'value' => $orderServices->sum($where + $order_where + ['status' => 2], 'pay_price', true),
                'key' => '元',
            ],
            [
                'title' => '已配送订单',
                'value' => $orderServices->sum($where + $order_where + ['status' => 3], 'pay_price', true),
                'key' => '元',
            ]
        ];
        return $data;
    }

    /**
     * 获取门店配送员订单统计
     * @param int $uid
     * @param int $store_id
     * @param string $time
     * @return array
     */
    public function getStoreData(int $uid, int $store_id, string $time = 'today')
    {
        $this->getDeliveryInfoByUid($uid, $store_id ? 1 : -1, (int)$store_id ? $store_id : -1);
        $data = [];
        $delivery_where = ['delivery_time' => $time];
        $where = ['delivery_uid' => $uid, 'paid' => 1, 'is_del' => 0, 'is_system_del' => 0, 'refund_status' => [0, 3]];
        if ($store_id) {
            $where['store_id'] = $store_id;
        }
        /** @var BranchOrderServices $order */
        $order = app()->make(BranchOrderServices::class);
        $where['status'] = 2;
        $data['unsend'] = $order->count($where);
        $where['status'] = 9;
        $data['send'] = $order->count($where + $delivery_where);
        $data['send_price'] = $order->sum($where + $delivery_where, 'pay_price', true);
        return $data;
    }

    /**
     * 获取配送员列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getServiceList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getServiceList($where, $page, $limit);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 获取配送员列表
     * @param int $type
     * @param int $store_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDeliveryList(int $type = 0, int $relation_id = 0, array $where = [])
    {
        [$page, $limit] = $this->getPageValue();
        $where1 = ['status' => 1, 'is_del' => 0, 'type' => $type, 'relation_id' => $relation_id];
        $where = array_merge($where, $where1);
        $list = $this->dao->getServiceList($where, $page, $limit);
        /** @var BranchOrderServices $order */
        $order = app()->make(BranchOrderServices::class);
        $orderWhere = ['paid' => 1, 'is_del' => 0, 'is_system_del' => 0, 'refund_status' => [0, 3]];
        if ($type == 1 && $relation_id) {
            $orderWhere['store_id'] = $relation_id;
        }
        foreach ($list as &$item) {
            if (!$item['nickname']) $item['nickname'] = $item['wx_name'];
            $item['unsend'] = $order->count($orderWhere + ['status' => 2, 'delivery_uid' => $item['uid']]);
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

	/**
	 * 创建配送员表单
	 * @param array $formData
	 * @param int $type
	 * @return array
	 */
    public function createServiceForm(array $formData = [], int $type = 0)
    {
		if ($type) {//门店
			$path = config('admin.store_prefix');
		} else {
			$path = config('admin.admin_prefix');
		}
        if ($formData) {
            $field[] = Form::frameImage('avatar', '配送员头像：', $this->url($path . '/widget.images/index', ['fodder' => 'avatar'], true), $formData['avatar'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]);
        } else {
            $field[] = Form::frameImage('image', '商城用户：', $this->url($path . '/system.user/list', ['fodder' => 'image'], true))->icon('ios-add')->width('960px')->height('550px')->modal(['footer-hide' => true])->Props(['srcKey' => 'image']);
            $field[] = Form::hidden('uid', 0);
            $field[] = Form::hidden('avatar', '');
        }
        $field[] = Form::input('nickname', '配送员名称：', $formData['nickname'] ?? '')->required('请填写配送员名称')->col(24);
        $field[] = Form::input('phone', '手机号码：', $formData['phone'] ?? '')->required('请填写电话')->col(24)->maxlength(11);
        $field[] = Form::radio('status', '配送员状态：', $formData['status'] ?? 1)->options([['value' => 1, 'label' => '显示'], ['value' => 0, 'label' => '隐藏']]);
        return $field;
    }

    /**
     * 创建配送员获取表单
     * @return mixed
     */
    public function create(int $type = 0)
    {
        return create_form('添加配送员', $this->createServiceForm([], $type), $this->url('/order/delivery/save'), 'POST');
    }

    /**
     * 编辑获取表单
     * @param int $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function edit(int $id, int $type = 0)
    {
        $serviceInfo = $this->dao->get($id);
        if (!$serviceInfo) {
            throw new AdminException('数据不存在!');
        }
        return create_form('编辑配送员', $this->createServiceForm($serviceInfo->toArray(), $type), $this->url('/order/delivery/update/' . $id), 'PUT');
    }

    /**
     * 添加门店配送员
     * @return mixed
     */
    public function createStoreDeliveryForm(int $type = 1)
    {
        return create_form('添加门店配送员', $this->createServiceForm([], $type), $this->url('/staff/delivery'));
    }

    /**
     * 编辑门店配送员
     * @param int $id
     * @param array $formData
     * @return mixed
     */
    public function updateStoreDeliveryForm(int $id, array $formData, int $type = 1)
    {
        return create_form('编辑门店配送员', $this->createServiceForm($formData, $type), $this->url('/staff/delivery/' . $id), 'put');
    }

    /**
     * 保存配送员信息
     * @param int $id
     * @param array $data
     * @param int $type
     * @param int $relation_id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function saveData(int $id, array $data, int $type = 0, int $relation_id = 0)
    {
        if ($data["nickname"] == '') {
            throw new ValidateException("配送员名称不能为空！");
        }
        if (!$data['phone']) {
            throw new ValidateException("手机号不能为空！");
        }
        if (!check_phone($data['phone'])) {
            throw new ValidateException('请输入正确的手机号!');
        }
        $uidInfo = [];
        if (isset($data['uid']) && $data['uid']) {
            $uidInfo = $this->dao->getOne(['uid' => $data['uid'], 'type' => $type, 'relation_id' => $relation_id, 'is_del' => 0], 'id');
        }
        if ($uidInfo && (!$id || $uidInfo['id'] != $id)) {
            throw new ValidateException('配送员已存在!');
        }
        $phoneInfo = $this->dao->getOne(['phone' => $data['phone'], 'type' => $type, 'relation_id' => $relation_id, 'is_del' => 0], 'id');
        if ($phoneInfo && (!$id || $phoneInfo['id'] != $id)) {
            throw new ValidateException('同一个手机号的配送员只能添加一个!');
        }
        if ($id) {//修改
            $delivery = $this->dao->get($id);
            if (!$delivery) {
                throw new ValidateException('数据不存在');
            }
            $res = $this->dao->update($id, $data);
        } else {//添加
            $data['type'] = $type;
            $data['relation_id'] = $relation_id;
            $data['add_time'] = time();
            $res = $this->dao->save($data);
        }
        if (!$res) {
            throw new ValidateException('保存失败');
        }
        return $res->id;
    }


    /**
     * 获取某人的聊天记录用户列表
     * @param int $uid
     * @return array|array[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getChatUser(int $uid)
    {
        /** @var StoreServiceLogServices $serviceLog */
        $serviceLog = app()->make(StoreServiceLogServices::class);
        /** @var UserServices $serviceUser */
        $serviceUser = app()->make(UserServices::class);
        $uids = $serviceLog->getChatUserIds($uid);
        if (!$uids) {
            return [];
        }
        return $serviceUser->getUserList(['uid' => $uids], 'nickname,uid,avatar as headimgurl');
    }

    /**
     * 获取配送员select（Uid）
     * @param array $where
     * @return mixed
     */
    public function getSelectList($where = [])
    {
        $list = $this->dao->getSelectList($where);
        $menus = [];
        foreach ($list as $menu) {
            $menus[] = ['value' => $menu['uid'], 'label' => $menu['nickname']];
        }
        return $menus;
    }
}
