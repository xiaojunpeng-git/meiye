<?php

namespace app\controller\admin\v1\report;
use app\controller\admin\AuthController;
use app\dao\store\SystemStoreDao;
use app\dao\yeji\StaffYejiDao;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderWriteoff;
use app\model\product\product\StoreProductRelation;
use app\model\salary\SalaryField;
use app\model\store\SystemStore;
use app\model\yeji\CashType;
use app\Request;
use app\services\order\store\BranchOrderServices;
use app\services\report\ReportServices;
use app\services\salary\SalaryTableServices;
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
            ['is_excel','']
        ]);
        $result=$this->services->xnList($where);
        return $this->success($result);
    }

    //门店项目销售分析
    public function fenxiList(Request $request){
        $where = $request->getMore([
            ['date', ''],
            ['is_excel','']
        ]);
        $list=$this->services->fenxiList($where);
        return $this->success($list);
    }
    //门店客户来源分析
    public function reportList(Request $request){
        $where = $request->getMore([
            ['date', ''],
            ['is_excel','']
        ]);
        $list=$this->services->reportList($where);
        return $this->success($list);
    }

    //门店对账单
    /**
     * 订单列表
     * @param Request $request
     * @return mixed
     */
    public function orderData(Request $request, BranchOrderServices $orderServices,SalaryTableServices $service)
    {
        $where = $request->getMore([
            ['data', '', '', 'time'],
            ['store_id',0],
            ['is_excel','']
        ]);
        $where['time'] = $orderServices->timeHandle($where['time']);
        $storeWhere=$where;
        $storeWhere['time_range']=$where['time'];
        unset($storeWhere['time']);
        //旧卡录入跟余额的不要
        $result=[];
        $data=CashType::where("id","<>",9)->select();
        $dao=app()->make(SystemStoreDao::class);
        $store=$dao->getStore(['salary_status'=>1]);
        $data[]=['id'=>0,'name'=>'抖音刷单','key'=>'抖音'];
        $data[]=['id'=>0,'name'=>'大众刷单','key'=>'大众'];
        foreach ($data as $nk=>$nv){
            $one['name'] = $nv['name'];
            $one['heji'] = 0;
            if($nv['id'] >0) {
                $where['cash_choose'] = $nv['id'];
                foreach ($store as $k => $v) {
                    $where['store_id'] = $v['id'];
                    $one[$v['id']] = $orderServices->reportOrder($where);
                    $one['heji'] = bcadd($one['heji'], $one[$v['id']]);
                }
            }else{
                foreach ($store as $k => $v) {
                    $storeWhere['store_id'] = $v['id'];
                    $one[$v['id']] = $service->storeSelf($storeWhere,$nv['key']);
                    $one['heji'] = bcadd($one['heji'], $one[$v['id']]);
                }
            }
            $result[] = $one;
        }
        if($where['is_excel'] == 1){
            //导出
            $header=['支付方式','合计'];
            $filekey = ['name','heji'];
            $export=$result;
            foreach ($store as $nk=>$nv){
                $header[]=$nv['name'];
                $filekey[]=$nv['id'];
            }
            $filename = '门店对账单导出_' . date('YmdHis', time());
            $result=compact('header', 'filekey', 'export', 'filename');
        }
        return app('json')->success($result);
    }
    //对账单的列
    public function receiveColumn(){
        $columns=[
            ['title'=>'支付方式','key'=>'name','minWidth'=>100,'fixed'=>'left'],
            ['title'=>'合计','key'=>'heji','minWidth'=>100,'fixed'=>'left']
        ];
        $dao=app()->make(SystemStoreDao::class);
        $data=$dao->getStore(['salary_status'=>1]);
        foreach ($data as $nk=>$nv){
            $columnOne=[
                'title'=>$nv['name'],
                'minWidth'=>100,
                'slot'=>$nv['id']
            ];
            $columns[]=$columnOne;
        }
        return $this->success("成功！",$columns);
    }
}
