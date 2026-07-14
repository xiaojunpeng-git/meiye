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
namespace app\controller\admin\v1\order;

use app\controller\admin\AuthController;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\store\BranchOrderServices;
use app\services\order\StoreOrderServices;
use app\services\store\DeliveryServiceServices;
use app\services\user\UserServices;
use app\services\user\UserWechatuserServices;
use mohe\exceptions\AdminException;
use think\facade\App;

/**
 * 配送员
 * Class StoreService
 * @package app\controller\admin\v1\store
 */
class DeliveryService extends AuthController
{
    /**
     * DeliveryService constructor.
     * @param App $app
     * @param DeliveryServiceServices $services
     */
    public function __construct(App $app, DeliveryServiceServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index(SystemRegionAgentServices $regionAgentServices)
    {
		$where = ['type' => 0, 'is_del' => 0];
		if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
			$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
			if ($storeIds) {
				$where['type'] = 1;
				$where['store_id'] = $storeIds;
			} else {
				return $this->success(['list' => [], 'count' => 0]);
			}
		}
        return $this->success($this->services->getServiceList($where));
    }

    /**
     * 显示创建资源表单页.
     *
     * @return \think\Response
     */
    public function create(UserWechatuserServices $services)
    {
        $where = $this->request->getMore([
            ['nickname', ''],
            ['data', '', '', 'time'],
            ['type', '', '', 'user_type'],
        ]);
        [$list, $count] = $services->getWhereUserList($where, 'u.nickname,u.uid,u.avatar as headimgurl,w.subscribe,w.province,w.country,w.city,w.sex');
        return $this->success(compact('list', 'count'));
    }

    /**
     * 添加客服表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function add()
    {
        return $this->success($this->services->create());
    }

    /*
     * 保存新建的资源
     */
    public function save()
    {
        $data = $this->request->postMore([
            ['image', ''],
            ['uid', 0],
            ['avatar', ''],
            ['phone', ''],
            ['nickname', ''],
            ['status', 1],
        ]);
        if ($data['image'] == '') return $this->fail('请选择用户');
        $data['uid'] = $data['image']['uid'];
        $data['avatar'] = $data['image']['image'];
        unset($data['image']);
		$this->services->saveData(0, $data);
		return $this->success('配送员添加成功');
    }

    /**
     * 显示编辑资源表单页.
     *
     * @param int $id
     * @return \think\Response
     */
    public function edit($id)
    {
        return $this->success($this->services->edit((int)$id));
    }

    /**
     * 保存新建的资源
     *
     * @param \think\Request $request
     * @return \think\Response
     */
    public function update($id)
    {
        $data = $this->request->postMore([
            ['avatar', ''],
            ['nickname', ''],
            ['phone', ''],
            ['status', 1],
        ]);
        if (!$id) {
            return $this->fail("缺少参数！");
        }
        $this->services->saveData((int)$id, $data);
        return $this->success('修改成功!');
    }

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (!$this->services->delete($id))
            return $this->fail('删除失败,请稍候再试!');
        else
            return $this->success('删除成功!');
    }

    /**
     * 修改状态
     * @param $id
     * @param $status
     * @return mixed
     */
    public function set_status($id, $status)
    {
        if ($status == '' || $id == 0) return $this->fail('参数错误');
        $this->services->update($id, ['status' => $status]);
        return $this->success($status == 0 ? '隐藏成功' : '显示成功');
    }

    /**
     *获取所有配送员列表
     */
    public function get_delivery_list()
    {
        [$type, $relation_id, $keyword] = $this->request->getMore([
            ['type', 0],
            ['relation_id', 0],
            ['keyword', ''],
        ], true);
        $where['keyword'] = $keyword;
        return $this->success($this->services->getDeliveryList($type,$relation_id,$where));
    }

    /**
     * 获取配送员select
     * @return mixed
     */
    public function getDeliverySelect()
    {
        $where['type'] = 0;
        $where['relation_id'] = 0;
        $where['is_del'] = 0;
        $where['status'] = 1;
        return $this->success($this->services->getSelectList($where));
    }
    /**
     * 取配送员订单统计头部图表数据
     * @param StoreOrderServices $services
     * @return mixed
     */
    public function statisticsHeader(StoreOrderServices $services, BranchOrderServices $orderServices)
    {
        [$delivery_uid, $time] = $this->request->getMore([
            ['delivery_uid', 0],
            ['real_name', ''],
            ['status', ''],// 0:待付款1:待配送 2:配送中 3:待评价 4:已完成 -2:已退款
            ['data', '', '', 'time']
        ], true);
        $time = $orderServices->timeHandle($time, true);
        return $this->success($services->getStatisticsHeader(0, $delivery_uid, $time));
    }

    /**
     * 获取配送员订单统计列表
     * @param StoreOrderServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function statistics(StoreOrderServices $services, BranchOrderServices $orderServices)
    {
        $where = $this->request->getMore([
            ['delivery_uid', 0],
            ['real_name', ''],
            ['status', ''],// 0:待付款1:待配送 2:配送中 3:待评价 4:已完成 -2:已退款
            ['data', '', '', 'time'],
        ]);
        $where['store_id'] = 0;
        $where['time'] = $orderServices->timeHandle($where['time']);
        return $this->success($services->getDeliveryStatistics($where));
    }
}
