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
namespace app\controller\store\finance;


use app\services\store\finance\StoreFinanceFlowServices;
use app\services\store\SystemStoreStaffServices;
use think\facade\App;
use app\controller\store\AuthController;


/**
 * 门店流水
 * Class StoreFinanceFlow
 * @package app\controller\store\finance
 */
class StoreFinanceFlow extends AuthController
{
    /**
     * StoreFinanceFlow constructor.
     * @param App $app
     * @param StoreFinanceFlowServices $services
     */
    public function __construct(App $app, StoreFinanceFlowServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }


    /**
     * 显示资源列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['staff_id', 0],
            ['data', '', '', 'time'],
			['pay_type', '']
        ]);
        $where['keyword'] = $this->request->param('keywork', '');
        $where['store_id'] = $this->storeId;
        $where['is_del'] = 0;
        $where['trade_type'] = 1;
        $where['no_type'] = [1,15];
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
        if (!$this->services->update($id, ['mark' => $mark])) {
            return app('json')->fail('备注失败');
        }
        return app('json')->success('备注成功');
    }

    /**
     * 获取店员select
     * @param SystemStoreStaffServices $services
     * @return mixed
     */
    public function getStaffSelect(SystemStoreStaffServices $services)
    {
        $where['store_id'] = $this->storeId;
        $where['is_del'] = 0;
        $where['status'] = 1;
        return app('json')->success($services->getSelectList($where));
    }

    /**
     * 账单记录
     * @return mixed
     */
    public function fundRecord()
    {
        $where = $this->request->getMore([
            ['timeType', 'day'],
            ['data', '', '', 'time'],
            ['is_take', ''],
        ]);
        $where['store_id'] = $this->storeId;
        $where['trade_type'] = 1;
        $where['no_type'] = [1,15];
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
            ['staff_id', 0]
        ]);
        $where['keyword'] = $this->request->param('keyword', '');
        $where['is_del'] = 0;
        $where['store_id'] = $this->storeId;
        $where['no_type'] = [1,15];
        $where['trade_type'] = 1;
        return app('json')->success($this->services->getList($where));
    }
}
