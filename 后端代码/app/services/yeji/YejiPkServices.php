<?php
namespace app\services\yeji;

use app\dao\order\StoreOrderDao;
use app\dao\store\SystemStoreDao;
use app\dao\yeji\YejiPkDao;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\yeji\StaffYeji;
use app\model\yeji\YejiPk;
use app\services\BaseServices;
use app\services\pay\PayServices;
use think\exception\InvalidArgumentException;
use think\exception\ValidateException;
use think\facade\Db;
use think\helper\Str;


class YejiPkServices extends BaseServices
{
    public function __construct(YejiPkDao $dao)
    {
        $this->dao = $dao;
    }
    //pk数据
    public function pkData($where){
        $date = \DateTime::createFromFormat('Y-m',$where['date']);
        $date->modify('first day of');
        // 向前推6个月
        $date->modify('-6 months');
        $six=$date->format('Y-m');
        $monthRange=$this->getRangeTime($where['date']);
        $fencheng=SystemStoreStaff::where("is_fencheng",1)->column("id");
        $dao=app()->make(SystemStoreDao::class);
        [$page, $limit] = $this->getPageValue();
        $where_data=['is_del'=>0];
        $shop=$dao->getList($where_data,["*"],$page,$limit);
        $count = $dao->count($where_data);
        $result=[];
        $export=[];
        foreach ($shop as $k=>$v){
            $where['store_id']=$v['id'];
            $pkOne=$this->dao->getOneData($where);
            $one=[
                'store_id'=>$v['id'],
                'date'=>$where['date'],
                'name'=>$v['name'],
                'goal'=>$pkOne['goal'] ?? 0,//本月目标
                'bottom'=>$this->bottomPk($six,$v['id'],$fencheng,$where['date']),  //底标
                'month'=>$this->monthYeji($monthRange,$v['id']),  //本月业绩
                'month_fencheng'=>$this->monthYejiFencheng($monthRange,$v['id'],$fencheng),  //本月分成款
                'goal_per'=>0
            ];
            $one['complete']=bcsub($one['month'],$one['month_fencheng']);
            $one['add_yeji']=bcsub($one['complete'],$one['bottom']);
            if($one['goal'] > 0) {
                $one['goal_per'] = bcdiv($one['complete'], $one['goal']);
            }
            $one['goal_per']=bcmul($one['goal_per'],100)."%";
            $result[]=$one;
            $exportOne=$one;
            unset($exportOne['store_id'],$exportOne['date']);
            $export[]=$exportOne;
        }
        if($where['is_excel'] == 1){
            //导出
            $filekey =array_keys($export[0] ?? []);
            $header=['门店','本月目标','本月底标','本月业绩','业绩分成款','完成率','本月实际完成','本月增长业绩'];
            $filename = '门店销售数据' . date('YmdHis', time());
            $list=compact('header', 'filekey', 'export', 'filename');
            return $list;
        }
        return compact('result', 'count');
    }
    public function getRangeTime($month){
        $date = \DateTime::createFromFormat('Y-m', $month);
        if (!$date) {
            throw new ValidateException("月份格式错误，请使用 YYYY-MM 或 YYYYMM 格式");
        }
        $firstDay = clone $date;
        $firstDay->modify('first day of this month'); // 定位到当月第一天
        $startDate = $firstDay->format('Y/m/d')." 00:00:00"; // 格式化为
        $lastDay = clone $date;
        $lastDay->modify('last day of this month'); // 定位到当月最后一天
        $endDate = $lastDay->format('Y/m/d')." 23:59:59";
        return [$startDate,$endDate];
    }
    //本月底标 前6个月现金业绩的平均值(扣掉合作方、阿平老师、秋华老师)
    public function bottomPk($six,$storeId,$fencheng,$end){
        $notYeji=StaffYeji::where("type","<",3)
            ->whereIn("staff_id",$fencheng)
            ->where("created_time",">=",$six)
            ->where("created_time","<",$end)
            ->where("store_id",$storeId)
            ->where('status',0)
            ->sum("yeji");
        $payTypes=[
            PayServices::WEIXIN_PAY,
            PayServices::ALIAPY_PAY,
            PayServices::CASH_PAY,
            PayServices::OFFLINE_PAY
        ];
        $order_where = ['paid' => 1,'pay_type'=>$payTypes,'not_old'=>1,'pid' =>-3, 'is_system_del' => 0, 'refund_status' =>0,'link_type'=>[0,1]];
        $order_where['store_id']=$storeId;
        $order_where['date_range_time']=[$six,$end];
        $dao=app()->make(StoreOrderDao::class);
        $orderYeji= $dao->sum($order_where, 'pay_price', true);
        //组合支付金额
        $zuhePay=CombinationOrder::alias("a")
            ->join('store_order b',"b.id=a.order_id",'left')
            ->where(function ($query){
                $query->whereIn("b.pid",[0,-2])->whereOr("b.pid",">",0);
            })
            ->where("a.cash_choose","<>",9)
            ->where("b.pay_type",PayServices::COMBINATION_PAY )
            ->where("b.is_system_del",0)
            ->whereIn("b.refund_status",0)
            ->whereIn("b.order_type",[0,1])
            ->where("a.active_pay","<>",3)
            ->where('b.store_id',$storeId)
            ->whereBetween("b.add_time",[strtotime($six),strtotime($end)])
            ->sum("a.price");
        $orderYeji=bcadd($orderYeji,$zuhePay,2);
        $oldYeji=YejiPk::where("date",">=",strtotime($six))->where("date","<",strtotime($end))->sum("old_yeji");
        $yeji=bcsub($orderYeji,$notYeji,2);
        $result=bcdiv(bcadd($yeji,$oldYeji,2),6);
        return $result;
    }

    //本月业绩
    public function monthYeji($monthRange,$storeId){
        $payTypes=[
            PayServices::WEIXIN_PAY,
            PayServices::ALIAPY_PAY,
            PayServices::CASH_PAY,
            PayServices::OFFLINE_PAY
        ];
        $order_where = ['paid' => 1,'pay_type'=>$payTypes,'not_old'=>1,'pid' =>-3, 'is_system_del' => 0, 'refund_status' =>0,'link_type'=>[0,1]];
        $order_where['store_id']=$storeId;
        $order_where['date_range_time']=$monthRange;
        $dao=app()->make(StoreOrderDao::class);
        $orderYeji= $dao->sum($order_where, 'pay_price', true);
        //组合支付金额
        $zuhePay=CombinationOrder::alias("a")
            ->join('store_order b',"b.id=a.order_id",'left')
            ->where(function ($query){
                $query->whereIn("b.pid",[0,-2])->whereOr("b.pid",">",0);
            })
            ->where("a.cash_choose","<>",9)
            ->where("b.pay_type",PayServices::COMBINATION_PAY )
            ->where("b.is_system_del",0)
            ->whereIn("b.refund_status",0)
            ->whereIn("b.order_type",[0,1])
            ->where("a.active_pay","<>",3)
            ->where('b.store_id',$storeId)
            ->whereBetween("b.add_time",[strtotime($monthRange[0]),strtotime($monthRange[1])])
            ->sum("a.price");
        $yeji=bcadd($orderYeji,$zuhePay);
        return $yeji;
    }
    //本月分成款
    public function monthYejiFencheng($monthRange,$storeId,$fencheng){
        $yeji=StaffYeji::where("type","<",3)
            ->whereIn("staff_id",$fencheng)
            ->whereBetween("created_time",$monthRange)
            ->where("store_id",$storeId)
            ->where('status',0)
            ->sum("yeji");
        $yeji=bcadd($yeji,0);
        return $yeji;
    }


    public function saveGoal($where,$goal){
        $where['date']=strtotime($where['date']);
        $one = $this->dao->getOne($where);
        if(!empty($one)){
            $save['goal']=$goal;
            $this->dao->update($one['id'],$save);
        }else{
             $where['goal']=$goal;
             $this->dao->save($where);
        }
        return true;
    }

    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        foreach ($list as &$item) {

        }
        return compact('count', 'list');
    }

}
