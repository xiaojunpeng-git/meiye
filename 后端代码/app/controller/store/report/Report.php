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
namespace app\controller\store\report;

use app\controller\store\AuthController;
use app\model\order\StoreOrder;
use app\model\yeji\CashType;
use app\Request;
use app\services\order\store\BranchOrderServices;
use app\services\report\ReportServices;


/**
 * 订单管理
 * Class StoreOrder
 * @package app\controller\admin\v1\order
 */
class Report extends AuthController
{

    /**
     * 订单列表
     * @param Request $request
     * @return mixed
     */
    public function orderData(Request $request, BranchOrderServices $orderServices,ReportServices $service)
    {
        $date = $request->get('date');
        $is_excel=$request->get("is_excel");
        $dayAttr=$service->getDaysOfMonth($date);
        $where['store_id'] = (int)$this->storeId;
        //旧卡录入跟余额的不要
        $result=[];
        $data=CashType::where("id","<>",9)->select();
        foreach ($data as $nk=>$nv){
             $where['cash_choose']=$nv['id'];
             $one['name']=$nv['name'];
             $one['heji']=0;
             foreach ($dayAttr as $k=>$v) {
                 $day=$v<10?"0".$v:$v;
                 $where['time']=$this->dateToDayTimestamp($date."-".$day);
                 $one[$v] = $orderServices->reportOrder($where);
                 $one['heji']=bcadd($one['heji'],$one[$v]);
             }
             $result[]=$one;
        }
        if($is_excel == 1){
            //导出
            $header=['支付方式','合计'];
            $filekey = ['name','heji'];
            $export=$result;
            foreach ($dayAttr as $nk=>$nv){
                $header[]=$nv."号";
                $filekey[]=$nv;
            }
            foreach ($data as $nk=>$nv){
                $header[]=$nv['name'];
                $filekey[]=$nv['key'];
            }
            $filename = '门店收款统计导出_' . date('YmdHis', time());
            $result=compact('header', 'filekey', 'export', 'filename');
        }
        return app('json')->success($result);
    }
    /**
     * 将年月日字符串（2026-03-07）转为当天开始/结束时间戳
     * @param string $dateStr 年月日字符串，格式：YYYY-MM-DD
     * @return array ['start' => 开始时间戳, 'end' => 结束时间戳]，参数错误返回空数组
     */
    public  function dateToDayTimestamp(string $dateStr): array {
        // 1. 校验日期格式（YYYY-MM-DD）
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($pattern, $dateStr)) {
            return [];
        }

        // 2. 生成当天00:00:00的时间戳（开始）
        $startTime = strtotime($dateStr . ' 00:00:00');
        // 3. 生成当天23:59:59的时间戳（结束）
        $endTime = strtotime($dateStr . ' 23:59:59');

        // 校验时间戳有效性（避免非法日期如2026-02-30）
        if (!$startTime || !$endTime) {
            return [];
        }

        return [$startTime,$endTime];
    }
    //对账单的列
    public function receiveColumn(Request $request,ReportServices $service){
        $date = $request->get('date');
        $attr=str_replace("-","/",$date);
        $columns=[
            ['title'=>'支付方式','key'=>'name','minWidth'=>100,'fixed'=>'left'],
            ['title'=>'合计','key'=>'heji','minWidth'=>100,'fixed'=>'left']
        ];
        $data=$service->getDaysOfMonth($date);
        foreach ($data as $nk=>$nv){
            $day=$nv<10?"0".$nv:$nv;
            $columnOne=[
                'title'=>$nv."号",
                'minWidth'=>100,
                'slot'=>$nv,
                'date_range'=>$attr."/".$day."-".$attr."/".$day
            ];
            $columns[]=$columnOne;
        }
        return $this->success("成功！",$columns);
    }
}
