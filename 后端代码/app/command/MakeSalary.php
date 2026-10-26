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
namespace app\command;


use app\model\product\product\StoreProduct;
use app\dao\order\StoreOrderDao;
use app\dao\yeji\StaffYejiDao;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\position\PositionYeji;
use app\model\product\product\StoreProductRelation;
use app\model\salary\SalaryField;
use app\model\salary\SalaryInfo;
use app\model\store\SystemStoreStaff;
use app\model\yeji\CashSource;
use app\model\yeji\StaffYeji;
use app\services\order\StoreOrderCreateServices;
use app\services\pay\PayServices;
use app\services\report\ReportServices;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Log;


class MakeSalary extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('makeSalary')
            ->addArgument('day', Argument::OPTIONAL, '天数', '1')
            ->setDescription('更新工资');
    }

    protected function execute(Input $input, Output $output)
    {
//        $this->delSaleYeji();
//        $output->info('执行成功:删除业绩');
//        die();
        $day = $input->getArgument('day');
        if(empty($day)){
            $day=1;
        }
        $this->doMake($day);
        $output->info('执行成功:更新工资');
    }

    public function delSaleYeji(){
         //删除余额支付对应的销售业绩
         $order=StoreOrder::where("pay_type","yue")
             ->where("order_type",0)
             ->where("paid",1)
             ->select();
         foreach ($order as $nk=>$nv){
             StaffYeji::where("type",2)->where("link_id",$nv['id'])->where("order_id",$nv['id'])->delete();
         }
    }
    public function doKuadian(){
          $order=StoreOrder::where("paid",1)->where("refund_status",0)->whereIn("order_type",[0,2])->select();
          $service=app()->make(StoreOrderCreateServices::class);
          foreach ($order as $k=>$nv){
              if($nv['order_type'] == 2){
                 //核销订单
                  $kua_store=$service->isKuadianhx($nv['store_id'],$nv['link_order']);
              }else{
                  $kua_store=$service->isKuadian($nv['uid'],$nv['store_id'],$nv['add_time']);
              }
              StoreOrder::where("id",$nv['id'])->update(['kua_store'=>$kua_store]);
          }
    }
    public function doMake($day=1){
        $yesterdayTime = strtotime('-'.$day." day");
        $date=strtotime(date("Y-m",$yesterdayTime));
        $staff=SystemStoreStaff::order("position","desc")->where("is_del",0)->select();
        $service=app()->make(ReportServices::class);
        $yesterday = new \DateTime();
        $yesterday->modify('-'.$day." day");
        $firstDay = clone $yesterday;
        $firstDay->modify('first day of this month'); // 定位到当月第一天
        $begin = $firstDay->format('Y/m/d')." 00:00:00"; // 格式化为
        $lastDay = clone $yesterday;
        $lastDay->modify('last day of this month'); // 定位到当月最后一天
        $end = $lastDay->format('Y/m/d')." 23:59:59";
        $dao=app()->make(StaffYejiDao::class);
        $productIds=StoreProductRelation::where("relation_id",78)->where("type",1)->column("product_id");
        $sourceAttr=CashSource::whereNotIn("id",[6,7,11,12])->column("id");
        foreach ($staff as $nk => $nv) {
            try {
                $salary = SalaryInfo::where("staff_id", $nv['id'])->where("date", $date)->find();
                if (empty($salary)) {
                    $salary = new SalaryInfo();
                    $salary->date = $date;
                    $salary->staff_id = $nv['id'];
                    $salary->store_id = $nv['store_id'];
                    $salary_info = [];
                } else {
                    $salary_info = json_decode($salary['salary_info'], true);
                }
                //读取业绩配置---获得门店提成、销售提成、产品提成
                $yeji = PositionYeji::where("position_id", $nv['position'])->where("status", 1)->select();
                $xiao_yeji = 0;
                $xiao_yeji_dian = 0;
                $xiao_yeji_get = 0;
                $product_yeji = 0;
                $product_dian = 0;
                $product_get = 0;
                $work_yeji = 0;
                $work_dian = 0;
                $work_get = 0;
                $storeCash = 0; //门店现金销售提成
                $storeYue = 0;//门店余额销售提成
                $storeWork = 0;//门店手工销售提成
                $storeCashYeji = 0; //门店现金销售提成
                $storeYueYeji = 0;//门店余额销售提成
                $storeWorkYeji = 0;//门店手工销售提成
                $mendianshijikoukayeji=0;
                $mendianshijikoukaticheng=0;
                foreach ($yeji as $k => $v) {
                    if ($v['position_level_id'] > 0 && $v['position_level_id'] != $nv['position_level']) {
                        continue;
                    }
                    $where = [];
                    $isProduct = false;
                    if (!empty($v['cate_ids'])) {
                        //判断品项
                        $where['cate_ids'] = $v['cate_ids'];
                        $count = StoreProduct::where("product_type", 0)
                            ->whereIn("id", function ($q) use ($v) {
                                $q->name('store_product_relation')->where("type", 1)
                                    ->where(function ($q) use ($v) {
                                        $q->whereIn("relation_id", $v['cate_ids'])->whereOr(function ($d) use ($v) {
                                            $d->whereIn("relation_pid", $v['cate_ids']);
                                        });
                                    })->field(['product_id'])->select();
                            })->count();
                        if ($count > 0) {
                            $isProduct = true;
                        }
                    }
                    $where['has_recharge'] = $v['has_recharge'];
                    //判断区间
                    $where['yeji_type'] = $v['type'];
                    $where['range_type'] = $v['range_type'];
                    $where['time_type'] = $v['time_type'];
                    $orderYeji = $service->getYeji($nv['id'], $nv['store_id'], [$begin, $end], $where);
                    $commission = 0;
                    if (!empty($v['range'])) {
                        if($where['yeji_type'] == 2){
                               //手工的就取 销售业绩 用来判断范围提点
                            $where['yeji_type']=1;
                            $where['has_recharge']=1;
                            $xiaoshouYeji=$service->getYeji($nv['id'], $nv['store_id'], [$begin, $end], $where);
                        }else{
                            $xiaoshouYeji=$orderYeji;
                        }
                        $range = explode("-", $v['range']);
                        if ($range[0] <= $xiaoshouYeji && $range[1] >= $xiaoshouYeji) {
                            $commission = $v['commission'];
                        } else {
                            continue;
                        }
                    }
                    $commission = bcdiv($commission, 100, 4);
                    $get = bcmul($orderYeji, $commission, 4);
//                    if ($orderYeji > 0 ||  $commission > 0) {
                        if ($v['range_type'] == 1) {
                            //门店提成
                            switch ($v['type']) {
                                case 1:
                                    //现金
                                    $storeCash = bcadd($storeCash, $get, 4);
                                    $storeCashYeji = bcadd($storeCashYeji, $orderYeji, 4);
                                    break;
                                case 2:
                                    //手工
                                    $storeWork = bcadd($storeWork, $get, 4);
                                    $storeWorkYeji = bcadd($storeWorkYeji, $orderYeji, 4);
                                    break;
                                case 3:
                                    //扣储值
                                    $storeYue = bcadd($storeYue, $get, 4);
                                    $storeYueYeji = bcadd($storeYueYeji, $orderYeji, 4);
                                    break;
                                case 4:
                                    //扣储值-不包含当天充值
                                    $mendianshijikoukaticheng = bcadd($mendianshijikoukaticheng, $get, 4);
                                    $mendianshijikoukayeji = bcadd($mendianshijikoukayeji, $orderYeji, 4);
                                    break;
                            }
                        } else {
                            //个人提成
                            switch ($v['type']) {
                                case 1:
                                case 3:
                                    //个人现金业绩
                                    if ($isProduct) {
                                        //产品业绩
                                        $product_yeji = bcadd($product_yeji, $orderYeji, 4);
                                        $product_dian = $commission;
                                        $product_get = bcadd($product_get, $get, 4);
                                    } else {
                                        $xiao_yeji = bcadd($xiao_yeji, $orderYeji, 4);
                                        $xiao_yeji_dian = $commission;
                                        $xiao_yeji_get = bcadd($xiao_yeji_get, $get, 4);
                                    }
                                    break;
                                case 2:
                                    //手工
                                    $work_yeji = bcadd($work_yeji, $orderYeji, 4);
                                    $work_dian = $commission;
                                    $work_get = bcadd($work_get, $get, 4);
                                    break;
                            }
                        }
//                    }
                }
                $salary_info['xiao_yeji'] = intval($xiao_yeji);
                $salary_info['xiao_commission'] = $xiao_yeji_dian;
                $salary_info['xiao_money'] = intval($xiao_yeji_get);
                $salary_info['product_yeji'] = intval($product_yeji);
                $salary_info['product_commission'] = $product_dian;
                $salary_info['product_money'] = intval($product_get);
                $salary_info['work_yeji'] = intval($work_yeji);
                $salary_info['work_commission'] = $work_dian;
                $salary_info['work_get'] = intval($work_get);
                $salary_info['zhidingke'] = $service->dianke($begin . "-" . $end, 0, $dao, 1, $nv['id']);
                $salary_info['keci'] = $service->dianke($begin . "-" . $end, 0, $dao, 0, $nv['id']);
                // 劳动项目数：link_id+goods_id 为一项，N 人平分（尾差归 staff_id 升序末位），与员工业绩统计一致
                $xiangmushu = $dao->projectNumFractional([
                    'created_time' => $begin . '-' . $end,
                    'store_id' => $nv['store_id'],
                ], (int) $nv['id']);
                $salary_info['xiangmushu'] = $xiangmushu;
                $salary_info['mendiankoukaticheng'] = intval($storeYue);
                $salary_info['mendianlaodongticheng'] = intval($storeWork);
                $salary_info['mendianxianjinticheng'] = intval($storeCash);
                $salary_info['mendianxianjinyeji'] = intval($storeCashYeji);
                $salary_info['mendiankoukayeji'] = intval($storeYueYeji);
                $isStoreManager = (int)($nv['position'] ?? 0) === 1;
                if ($isStoreManager) {
                    $storeHomeMetrics = $service->getStoreHomeHeaderMetrics((int) $nv['store_id'], [$begin, $end]);
                    $salary_info['mendianlaodongyeji'] = intval($storeHomeMetrics['store_writeoff_order_price']);
//                    $salary_info['mendianshijikoukayeji'] = intval($storeHomeMetrics['store_use_yue']);
                    $salary_info['mendianshijikoukayeji']=intval($mendianshijikoukayeji);
                } else {
                    $salary_info['mendianlaodongyeji'] = 0;
                    $salary_info['mendianshijikoukayeji'] = 0;
                }
                $salary_info['mendianshijikoukaticheng'] = intval($mendianshijikoukaticheng);
                $salary_info['xinkeshu'] = $service->sourceStaffOrder($sourceAttr, 1, [strtotime($begin), strtotime($end)], 1, $nv['store_id'], $productIds,false,false,$nv['id']);
                //计算公式了
                $list = SalaryField::where("type", 2)->select();
                foreach ($list as $k => $v) {
                    $salary_info[$v['key']] = $service->calculateByFormula($salary_info, $v['info']);
                }
                if(empty($nv['position'])){
                    $nv['position']=11;
                }
                $salary->position_id = $nv['position'];
                $salary->xiangmushu = $xiangmushu;
                $salary->salary_info = json_encode($salary_info);
                $salary->cmd_time = date("Y-m-d H:i:s");
                $salary->save();
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    'makeSalary 员工工资计算失败 staff_id=%s store_id=%s: %s',
                    $nv['id'] ?? '',
                    $nv['store_id'] ?? '',
                    $e->getMessage()
                ));
            }
        }
    }
}
