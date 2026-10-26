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
namespace app\controller\admin\v1\store;


use app\services\agent\SystemRegionAgentServices;
use think\facade\App;
use app\controller\admin\AuthController;
use app\services\store\finance\StoreFinanceFlowServices;


/**
 * 门店流水
 * Class StoreFinanceFlow
 * @package app\controller\admin\v1\store
 */
class StoreFinanceFlow extends AuthController
{
    /**
     * StoreFinanceFlow constructor.
     * @param App $app
     * @param StoreExtractServices $services
     */
    public function __construct(App $app, StoreFinanceFlowServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }


	/**
	 * 显示资源列表
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],
            ['store_id', '']
        ]);
        $where['keyword'] = $this->request->param('keyword', '');
        $where['is_del'] = 0;
        $where['trade_type'] = 1;
        $where['no_in_type'] = [2, 14];
		if (!$where['store_id']) {//无筛选门店
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
        return app('json')->success($this->services->getList($where));
    }

    /**
     * 增加备注
     * @param $id
     * @return mixed
     */
    public function mark($id)
    {
        [$mark] = $this->request->getMore([
            ['mark', '']
        ], true);
        if (!$id || !$mark) {
            return app('json')->fail('缺少参数');
        }
        $info = $this->services->get((int)$id);
        if (!$info) {
            return app('json')->fail('账单流水不存在');
        }
        if (!$this->services->update($id, ['remark' => $mark])) {
            return app('json')->fail('备注失败');
        }
        return app('json')->success('备注成功');
    }


	/**
	 * 账单记录
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function fundRecord(SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
            ['timeType', 'day'],
            ['data', '', '', 'time'],
            ['store_id', '']
        ]);
        $where['trade_type'] = 1;
        $where['no_type'] = [1,15];
		if (!$where['store_id']) {//无筛选门店
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {//区域代理商下门店ID
					$where['store_id'] = $storeIds;
				} else {
					$where['store_id'] = -2;
				}
			}
		}
        return app('json')->success($this->services->getFundRecord($where));
    }

    /**
     * 账单详情
     * @param $ids
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function fundRecordInfo()
    {
        $where = $this->request->getMore([
            ['timeType', 'day'],
            ['day', ''],
            ['store_id', '']
        ]);
        $where['keyword'] = $this->request->param('keyword', '');
        $where['is_del'] = 0;
        $where['trade_type'] = 1;
        $where['no_type'] = [1,15];
        return app('json')->success($this->services->getList($where));
    }
}
