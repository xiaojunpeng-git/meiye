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
namespace app\controller\api\agent;

use app\model\yeji\CashType;
use app\Request;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\store\BranchOrderServices;
use app\services\yeji\SatffYejiServices;
use think\Response;

/**
 * 订单控制器
 * Class StoreOrder
 * @package app\controller\api\order
 */
class Report
{
    /**
     * @var SystemRegionAgentServices
     */
    protected $services;

    /**
     * @var int
     */
    protected $uid;

    /**
     * 门店店员信息
     * @var array
     */
    protected $agentInfo;

    /**
     * 区域代理商id
     * @var int|mixed
     */
    protected $agentId;

    /**
     * StoreOrder constructor.
     * @param SystemRegionAgentServices $services
     */
    public function __construct(SystemRegionAgentServices $services, Request $request)
    {
        $this->services = $services;
        $this->uid = (int)$request->uid();
        $this->getAgentInfo();
    }
    /**
     * 获取当前登录
     * @return void
     */
    protected function getAgentInfo()
    {
        try {
            $agentInfo = $this->services->getRegionAgentByUid($this->uid);
        } catch (\Throwable $e) {
            $agentInfo = [];
        }
        $this->agentInfo = $agentInfo;
        $this->agentId = (int)($agentInfo['id'] ?? 0);
    }
    /**
     * 订单列表
     * @param Request $request
     * @return mixed
     */
    public function orderData(Request $request, BranchOrderServices $orderServices)
    {
        $where = $request->getMore([
            ['data', '', '', 'time'],
            ['store_id',0]
        ]);
        if (!$where['store_id']) {//区域代理商下所有门店
            $storeIds = $this->services->getRegionAgentStoreId((int)$this->agentId);
            $where['store_id'] = $storeIds;
        }
        $where['time'] = $orderServices->timeHandle($where['time']);
        //旧卡录入跟余额的不要
        $result=[];
        $data=CashType::where("id","<>",9)->select();
        foreach ($data as $nk=>$nv){
            $where['cash_choose']=$nv['id'];
            $one['name']=$nv['name'];
            $one['total_money']=$orderServices->reportOrder($where);
            $result[]=$one;
        }
        return app('json')->success($result);
    }


}
