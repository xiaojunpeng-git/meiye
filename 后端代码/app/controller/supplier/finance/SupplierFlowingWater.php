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
namespace app\controller\supplier\finance;


use app\services\supplier\finance\SupplierFlowingWaterServices;
use think\facade\App;
use app\controller\supplier\AuthController;


/**
 * 供应商流水
 * Class SupplierFlowingWater
 * @package app\controller\supplier\finance
 */
class SupplierFlowingWater extends AuthController
{
    /**
     * SupplierFlowingWater constructor.
     * @param App $app
     * @param SupplierFlowingWaterServices $services
     */
    public function __construct(App $app, SupplierFlowingWaterServices $services)
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
            ['type', ''],
            ['data', '', '', 'time'],
        ]);
        $where['keyword'] = $this->request->param('keyword', '');
        $where['supplier_id'] = $this->supplierId;
        $where['is_del'] = 0;
        return app('json')->success($this->services->getList($where));
    }

    /**
     * 增加备注
     * @param $id
     * @return mixed
     */
    public function mark($id)
    {
        [$mark] = $this->request->postMore([
            ['mark', '']
        ], true);
        if (!$id || !$mark) {
            return app('json')->fail('缺少参数');
        }
        $info = $this->services->get((int)$id);
        if (!$info) {
            return app('json')->fail('流水不存在');
        }
        if (!$this->services->update($id, ['mark' => $mark])) {
            return app('json')->fail('备注失败');
        }
        return app('json')->success('备注成功');
    }

    /**获取交易类型
     * @return \think\Response
     */
    public function getType()
    {
        return app('json')->success($this->services->type);
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
        $where['supplier_id'] = $this->supplierId;
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
            ['ids', '']
        ]);
        $where['keyword'] = $this->request->param('keyword', '');
        $where['id'] = $where['ids'] ? explode(',', $where['ids']) : [];
        unset($where['ids']);
        $where['is_del'] = 0;
        $where['supplier_id'] = $this->supplierId;
        return app('json')->success($this->services->getList($where));
    }
}
