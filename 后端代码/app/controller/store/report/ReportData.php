<?php

namespace app\controller\store\report;

use app\controller\store\AuthController;
use app\dao\store\SystemStoreDao;
use app\dao\yeji\StaffYejiDao;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderWriteoff;
use app\model\product\product\StoreProductRelation;
use app\model\store\SystemStore;
use app\Request;
use app\services\report\ReportServices;
use think\facade\App;
use think\facade\Db;

/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class ReportData extends AuthController
{
    /**
     * StoreOrder constructor.
     * @param App $app
     */
    public function __construct(App $app, ReportServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }
    //销售数据
    public function reportSale(){
        $shop=SystemStore::where("is_del",0)->where("is_show",1)->select();
        $result=[];
        foreach ($shop as $k=>$v){
            $result[]=[
                'name'=>$v['name'],
                'key'=>$v['id']
            ];
        }
        return $this->success("ok",$result);
    }

    //门店效能分析
    public function xnList(Request $request){
        $where = $request->getMore([
            ['date', ''],
        ]);
        $result=$this->services->xnList($where);
        return $this->success($result);
    }

    //门店项目销售分析
    public function fenxiList(Request $request){
        $where = $request->getMore([
            ['date', ''],
        ]);
        $list=$this->services->fenxiList($where);
        return $this->success($list);
    }
    //门店客户来源分析
    public function reportList(Request $request){
        $where = $request->getMore([
            ['date', ''],
        ]);
        $list=$this->services->reportList($where);
        return $this->success($list);
    }
}
