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
namespace app\dao\yeji;

use app\dao\BaseDao;
use app\model\position\PositionYeji;
use app\model\store\SystemStoreStaff;
use app\model\yeji\CashType;
use app\model\yeji\StaffYeji;
use app\services\order\ValidCashOrderServices;
use app\services\pay\PayServices;
use app\services\report\ReportServices;
use think\facade\Db;

/**
 * 文章dao
 * Class ArticleDao
 * @package app\dao\article
 */
class StaffYejiDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return StaffYeji::class;
    }

    public function search(array $where = [])
    {
        return parent::search($where)
            ->where("status",0)
            ->when(isset($where['created_time']) && $where['created_time'], function ($query) use ($where){
            $times=explode("-",$where['created_time']);
            $times[1]=strtotime($times[1]);
            $times[1]=date('Y/m/d',$times[1])." 23:59:59";
            $query->whereTime('created_time', 'between',$times);
         })->when(isset($where['cate_ids']) && $where['cate_ids'], function ($query) use ($where){
                //查询某些类别下商品的业绩
                if(!empty($where['cate_ids'])){
                    $query->whereIn("goods_id",function ($q) use ($where) {
                        $q->name('store_product_relation')->where("type",1)
                            ->where(function ($q) use ($where){
                                $q->whereIn("relation_id",$where['cate_ids'])->whereOr(function ($d) use ($where){
                                    $d->whereIn("relation_pid",$where['cate_ids']);
                                });
                            })->field(['product_id'])->select();
                    });
                }
            })->when(isset($where['is_dian']) && $where['is_dian'] != '',function ($query) use ($where){
                $query->where("is_dian",$where['is_dian']);
            })->when(isset($where['type']) && $where['type'], function ($query) use ($where){
                if(is_array($where['type'])){
                    $query->whereIn("type",$where['type']);
                }else{
                    $query->where("type",$where['type']);
                }
        })->when(isset($where['staff_id']) && $where['staff_id'], function ($query) use ($where){
            if(is_array($where['staff_id'])){
                $query->whereIn("staff_id",$where['staff_id']);
            }else{
                $query->where("staff_id",$where['staff_id']);
            }
        })->when(isset($where['store_id']) && $where['store_id'], function ($query) use ($where) {
            if(is_array($where['store_id'])){
                $query->whereIn("store_id",$where['store_id']);
            }else{
                $query->where("store_id",$where['store_id']);
            }
           })->when(isset($where['sum_type']) && $where['sum_type'], function ($query) use ($where){
            if($where['sum_type'] == 1){
                //销售业绩---销售业绩一定是现金业绩---这里固定包含充值 如果有客户不包含充值还得调整
                $query->whereIn("type",[1,2])->whereIn("order_id", function ($q) use ($where) {
                    $q->name('store_order')
                        ->whereIn("order_type",[0,1])
                        ->where(function ($query1) {
                            $query1->where('pid', '>=', 0)->whereOr('pid',-2);
                        })
                        ->when(isset($where['created_time']) && $where['created_time'], function ($query) use ($where){
                            $times=explode("-",$where['created_time']);
                            $times[1]=strtotime($times[1]);
                            $times[1]=date('Y/m/d',$times[1])." 23:59:59";
                            $query->whereTime('add_time', 'between',$times);
                        })
                        ->where("paid",1)
                        ->where("refund_status",0)
                        ->where("is_del",0)
                        ->where("is_system_del",0)
                        ->when(empty($where['include_zero_cash']), function ($q) {
                            $q->where(function ($cashQuery) {
                                $cashQuery->where('cash_pay_price', '>', 0)
                                    ->whereOr(function ($combQuery) {
                                        $combQuery->where('pay_type', PayServices::COMBINATION_PAY)
                                            ->whereIn('id', function ($sub) {
                                                $sub->name('combination_order')
                                                    ->where('cash_choose', '<>', CashType::OLD_CARD_ENTRY)
                                                    ->where('cash_choose', '<>', CashType::DEBT_ENTRY)
                                                    ->where(function ($debtQ) {
                                                        $debtQ->whereNull('pay_sub_type')->whereOr('pay_sub_type', '<>', 'debt');
                                                    })
                                                    ->where('active_pay', '<>', 3)
                                                    ->field('order_id');
                                            });
                                    });
                            });
                            $this->applyValidCashOrderScope($q);
                        })
                        ->field(['id'])
                        ->select();
                });
            }else{
                $query->where("type",3);
            }
        })->when(isset($where['link_id']) && $where['link_id'], function ($query) use ($where){
            $query->where("link_id",$where['link_id']);
        })->when(isset($where['order_id']) && $where['order_id'], function ($query) use ($where){
            $query->where("order_id",$where['order_id']);
        })->when(isset($where['keyword']) && $where['keyword'], function ($query) use ($where) {
            $query->where(function ($query) use ($where) {
                $query->whereLike('staff_id|staff_name','like','%'.$where['keyword']."%");
            });
        })->when(isset($where['agent_time']) && $where['agent_time'], function ($query) use ($where) {
            $query->where(function ($query) use ($where) {
                $validDates = array_filter($where['agent_time'], function($date) {
                    // 仅保留YYYY-MM-DD格式的有效日期
                    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date);
                });
               if(!empty($validDates)) {
                  $dateStr = "'" . implode("','",$validDates) . "'";
                  $query->whereRaw("DATE(created_time) IN ({$dateStr})");
              }
            });
        });
    }

    /**
     * 现金业绩订单范围：排除旧卡录入（cash_choose=9）；组合支付需含非旧卡录入的现金明细
     * @param \think\db\Query $query
     * @return void
     */
    protected function applyValidCashOrderScope($query): void
    {
        ValidCashOrderServices::applyScope($query);
    }

    public function searchJoin(array $where = [],$alias="a")
    {
        return parent::search($where)
            ->where($alias.".status",0)
            ->when(isset($where['created_time']) && $where['created_time'], function ($query) use ($where,$alias){
                $times=explode("-",$where['created_time']);
                $times[1]=strtotime($times[1]);
                $times[1]=date('Y/m/d',$times[1])." 23:59:59";
                $query->whereTime($alias.'.created_time', 'between',$times);
            }) ->when(isset($where['is_dian']) && $where['is_dian'] != '',function ($query) use ($where,$alias){
                $query->where($alias.".is_dian",$where['is_dian']);
            })->when(isset($where['type']) && $where['type'], function ($query) use ($where,$alias){
                if(is_array($where['type'])){
                    $query->whereIn($alias.".type",$where['type']);
                }else{
                    $query->where($alias.".type",$where['type']);
                }
            })->when(isset($where['staff_id']) && $where['staff_id'], function ($query) use ($where,$alias){
                $query->where($alias.".staff_id",$where['staff_id']);
            })->when(isset($where['store_id']) && $where['store_id'], function ($query) use ($where,$alias) {
                if(is_array($where['store_id'])){
                    $query->whereIn($alias.".store_id",$where['store_id']);
                }else{
                    $query->where($alias.".store_id",$where['store_id']);
                }
            })->when(isset($where['cate_ids']) && $where['cate_ids'], function ($query) use ($where,$alias){
                //查询某些类别下商品的业绩
                  if(!empty($where['cate_ids'])){
                       $query->whereIn($alias.".goods_id",function ($q) use ($where) {
                           $q->name('store_product_relation')->where("type",1)
                               ->where(function ($q) use ($where){
                                   $q->whereIn("relation_id",$where['cate_ids'])->whereOr(function ($d) use ($where){
                                       $d->whereIn("relation_pid",$where['cate_ids']);
                                   });
                               })->field(['product_id'])->select();
                       });
                  }
            })
            ->when(isset($where['sum_type']) && $where['sum_type'], function ($query) use ($where,$alias){
                if($where['sum_type'] == 1){
                    //销售业绩
                    $query->whereIn($alias.".type",[1,2]);
                }else{
                    $query->where($alias.".type",3);
                }
            })->when(isset($where['link_id']) && $where['link_id'], function ($query) use ($where,$alias){
                $query->where($alias.".link_id",$where['link_id']);
            })->when(isset($where['order_id']) && $where['order_id'], function ($query) use ($where,$alias){
                $query->where($alias.".order_id",$where['order_id']);
            })->when(isset($where['keyword']) && $where['keyword'], function ($query) use ($where,$alias) {
                $query->where(function ($query) use ($where,$alias) {
                    $query->whereLike($alias.'.staff_id|'.$alias.'staff_name','like','%'.$where['keyword']."%");
                });
            })->when(isset($where['agent_time']) && $where['agent_time'], function ($query) use ($where,$alias) {
                $query->where(function ($query) use ($where,$alias) {
                    $validDates = array_filter($where['agent_time'], function($date) {
                        // 仅保留YYYY-MM-DD格式的有效日期
                        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date);
                    });
                    if(!empty($validDates)) {
                        $dateStr = "'" . implode("','",$validDates) . "'";
                        $query->whereRaw("DATE(".$alias.".created_time) IN ({$dateStr})");
                    }
                });
            });
    }
    public function totalCommission($where){
         $where['sum_type']=2;
         $data=$this->search($where)->select();
         $perAttr=[];
         $total=0;
         foreach ($data as $k=>$item){
             $yeji=$item['yeji'];
             $perAttr=$this->findMonthYeji($item['staff_id'],$item['created_time'],$perAttr);
             $key=$this->getKey($item['staff_id'],$item['created_time']);
             $monthYeji=$perAttr[$key] ?? 0;
             $per=$this->getPer((int)$item['staff_id'], (int)$item['goods_id'], $monthYeji, (string)($item['created_time'] ?? ''));
             $total =bcadd($total,bcmul($yeji,$per/100,2),2);
         }
         return $total;
    }
    public function getKey($staffId,$date){
        $dateTime = new \DateTime($date);
        $year = $dateTime->format('Y');       // 年份：2026
        $month = $dateTime->format('m');
        $key=$year."_".$month."_".$staffId;
        return $key;
    }
    //查看当月业绩
    public function findMonthYeji($staffId,$date,$findAttr=[]){
        $dateTime = new \DateTime($date);
        $year = $dateTime->format('Y');       // 年份：2026
        $month = $dateTime->format('m');
        $key=$year."_".$month."_".$staffId;
        if(isset($findAttr[$key])){
            return $findAttr;
        }
        // 3. 判断所属月份（核心）
        $startTime = (clone $dateTime)->modify('first day of this month 00:00:00')->format('Y/m/d H:i:s');
        // 5. 计算当前日期所在月份的 结束时间
        $endTime = (clone $dateTime)->modify('last day of this month 23:59:59')->format('Y/m/d H:i:s');
        $where['created_time']=$startTime."-".$endTime;
        $where['staff_id']=$staffId;
        $where['sum_type']=1;
        $yeji=$this->search($where)->sum("yeji");  //当月现金业绩
        $findAttr[$key]=$yeji;
        return $findAttr;
    }
    /**
     * 劳动提成比例（%）：与 MakeSalary::doMake 个人手工(type=2)一致，从 position_yeji 读取
     *
     * @param int    $staffId      员工 ID
     * @param int    $goodsId      商品 ID
     * @param mixed  $monthYeji    当月现金销售业绩（无品项筛选时的落档依据）
     * @param string $createdTime  业绩创建时间，用于按月份取区间及品项筛选销售
     */
    public function getPer(int $staffId, int $goodsId, $monthYeji, string $createdTime = ''): float
    {
        if ($staffId <= 0 || $goodsId <= 0) {
            return 0;
        }
        $staff = SystemStoreStaff::where('id', $staffId)->field('position,position_level,store_id')->find();
        if (empty($staff)) {
            return 0;
        }
        $positionId = (int)($staff['position'] ?? 0);
        if ($positionId <= 0) {
            $positionId = 11;
        }
        $positionLevel = (int)($staff['position_level'] ?? 0);
        $storeId = (int)($staff['store_id'] ?? 0);

        $configs = PositionYeji::where('position_id', $positionId)
            ->where('status', 1)
            ->where('type', 2)
            ->where('range_type', 2)
            ->select();
        if ($configs->isEmpty()) {
            return 0;
        }

        $totalPer = '0';
        foreach ($configs as $config) {
            $row = $config->toArray();
            if ((int)($row['position_level_id'] ?? 0) > 0 && (int)$row['position_level_id'] !== $positionLevel) {
                continue;
            }
            $cateIds = $this->normalizeCateIds($row['cate_ids'] ?? '');
            if (!$this->goodsMatchesCateIds($goodsId, $cateIds)) {
                continue;
            }
            if (empty($row['range'])) {
                continue;
            }
            // 手工提成落档：用销售业绩判断区间（与 MakeSalary 一致）
            $xiaoshouYeji = $this->resolveSalesYejiForRange($staffId, $storeId, $createdTime, $monthYeji, $cateIds);
            $range = explode('-', (string)$row['range']);
            if (!isset($range[0], $range[1]) || $range[0] > $xiaoshouYeji || $range[1] < $xiaoshouYeji) {
                continue;
            }
            $totalPer = bcadd($totalPer, (string)($row['commission'] ?? 0), 2);
        }

        return (float)$totalPer;
    }

    /**
     * @param mixed $cateIds
     * @return int[]
     */
    protected function normalizeCateIds($cateIds): array
    {
        if (is_array($cateIds)) {
            return array_values(array_filter(array_map('intval', $cateIds)));
        }
        if ($cateIds === '' || $cateIds === null) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', (string)$cateIds))));
    }

    protected function goodsMatchesCateIds(int $goodsId, array $cateIds): bool
    {
        if ($cateIds === []) {
            return true;
        }
        return Db::name('store_product_relation')
                ->where('type', 1)
                ->where('product_id', $goodsId)
                ->where(function ($query) use ($cateIds) {
                    $query->whereIn('relation_id', $cateIds)->whereOr(function ($sub) use ($cateIds) {
                        $sub->whereIn('relation_pid', $cateIds);
                    });
                })
                ->count() > 0;
    }

    /**
     * 手工提成区间判断用的销售业绩（type=1, has_recharge=1）
     */
    protected function resolveSalesYejiForRange(int $staffId, int $storeId, string $createdTime, $monthYeji, array $cateIds)
    {
        if ($cateIds === [] || $createdTime === '') {
            return $monthYeji;
        }
        $dateTime = new \DateTime($createdTime);
        $startTime = (clone $dateTime)->modify('first day of this month 00:00:00')->format('Y/m/d H:i:s');
        $endTime = (clone $dateTime)->modify('last day of this month 23:59:59')->format('Y/m/d H:i:s');
        /** @var ReportServices $service */
        $service = app()->make(ReportServices::class);

        return $service->getYeji($staffId, $storeId, [$startTime, $endTime], [
            'yeji_type' => 1,
            'range_type' => 2,
            'has_recharge' => 1,
            'cate_ids' => $cateIds,
            'time_type' => 0,
        ]);
    }

    public function getList(array $where, int $page = 0, int $limit = 0, string $order = '', array $with = [])
    {
        return $this->search($where)->order(($order ? $order . ' ,' : '') . 'id desc')
            ->when(count($with), function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select()->toArray();
    }

    //业绩排名
    public function ranking(array $where, int $page = 0, int $limit = 0){
        $result=$this->search($where)
            ->field("staff_id,staff_name,SUM(yeji) as yeji,store_id,is_dian,type")
            ->group('staff_id')
            ->order("yeji desc")
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select()->toArray();
        return $result;
    }

    /**
     * 劳动「项目数」排行：与 projectNumFractional 同一套分组与平分规则，按员工汇总项目数后降序。
     */
    public function projectRanking(array $where, int $page = 0, int $limit = 0)
    {
        $baseWhere = $where;
        $baseWhere['sum_type'] = 2;

        $rows = $this->search($baseWhere)
            ->field('staff_id,staff_name,link_id,goods_id,store_id')
            ->select();
        if (method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        $groups = [];
        foreach ($rows as $nv) {
            $nv = is_array($nv) ? $nv : (method_exists($nv, 'toArray') ? $nv->toArray() : []);
            $lid = (int) ($nv['link_id'] ?? 0);
            if ($lid <= 0) {
                continue;
            }
            $gid = (int) ($nv['goods_id'] ?? 0);
            $gkey = $lid . '_' . $gid;
            if (!isset($groups[$gkey])) {
                $groups[$gkey] = [];
            }
            $sid = (int) ($nv['staff_id'] ?? 0);
            if ($sid > 0) {
                $groups[$gkey][$sid] = true;
            }
        }

        $staffHundredths = [];
        $staffMeta = [];
        foreach ($rows as $nv) {
            $nv = is_array($nv) ? $nv : (method_exists($nv, 'toArray') ? $nv->toArray() : []);
            $sid = (int) ($nv['staff_id'] ?? 0);
            if ($sid > 0 && !isset($staffMeta[$sid])) {
                $staffMeta[$sid] = [
                    'staff_name' => (string) ($nv['staff_name'] ?? ''),
                    'store_id' => (int) ($nv['store_id'] ?? 0),
                ];
            }
        }

        foreach ($groups as $staffMap) {
            $staffIds = array_keys($staffMap);
            sort($staffIds, SORT_NUMERIC);
            $n = count($staffIds);
            if ($n === 0) {
                continue;
            }
            foreach ($staffIds as $idx => $sid) {
                $h = $this->staffServiceShareHundredths($n, (int) $idx);
                $staffHundredths[$sid] = ($staffHundredths[$sid] ?? 0) + $h;
            }
        }

        $list = [];
        foreach ($staffHundredths as $sid => $hundredths) {
            $meta = $staffMeta[$sid] ?? ['staff_name' => '', 'store_id' => 0];
            $list[] = [
                'staff_id' => $sid,
                'staff_name' => $meta['staff_name'],
                'store_id' => $meta['store_id'],
                'yeji' => bcdiv((string) $hundredths, '100', 2),
                'is_dian' => 0,
                'type' => 3,
            ];
        }

        usort($list, function ($a, $b) {
            return bccomp($b['yeji'], $a['yeji'], 2);
        });

        if ($page > 0 && $limit > 0) {
            $list = array_slice($list, ($page - 1) * $limit, $limit);
        }

        return $list;
    }

    /**
     * 点客排行：与员工业绩「指定客」(serviceNum + is_dian=1) 同一套客次去重与多人平分规则。
     */
    public function dianke(array $where, int $page = 0, int $limit = 0)
    {
        $baseWhere = $where;
        unset($baseWhere['staff_id']);
        $baseWhere['sum_type'] = 2;
        $baseWhere['is_dian'] = 1;

        $rows = $this->searchJoin($baseWhere, 'a')
            ->alias('a')
            ->join('store_order b', 'b.id=a.order_id', 'left')
            ->join('store_order_writeoff w', 'w.id=a.link_id AND a.type=3', 'left')
            ->field('a.type,a.id as yeji_id,a.staff_id,a.staff_name,a.created_time,a.store_id,b.uid,b.id as order_id,b.service_object as service_object_order,w.service_object as service_object_writeoff')
            ->select();
        if (method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        $groups = [];
        $staffMeta = [];
        foreach ($rows as $nv) {
            $nv = is_array($nv) ? $nv : (method_exists($nv, 'toArray') ? $nv->toArray() : []);
            $sid = (int) ($nv['staff_id'] ?? 0);
            if ($sid > 0 && !isset($staffMeta[$sid])) {
                $staffMeta[$sid] = [
                    'staff_name' => (string) ($nv['staff_name'] ?? ''),
                    'store_id' => (int) ($nv['store_id'] ?? 0),
                ];
            }
            $day = date('Ymd', strtotime($nv['created_time'] ?? ''));
            $uid = (int) ($nv['uid'] ?? 0);
            if ($uid > 0 && !$this->serviceNumIsFriendGuestRule($nv)) {
                $gkey = 'm_' . $day . '_' . $uid;
            } else {
                $gkey = $this->serviceNumBuildGuestGroupKey($nv);
            }
            if (!isset($groups[$gkey])) {
                $groups[$gkey] = [];
            }
            if ($sid > 0) {
                $groups[$gkey][$sid] = true;
            }
        }

        $staffHundredths = [];
        foreach ($groups as $staffMap) {
            $staffIds = array_keys($staffMap);
            sort($staffIds, SORT_NUMERIC);
            $n = count($staffIds);
            if ($n === 0) {
                continue;
            }
            foreach ($staffIds as $idx => $sid) {
                $h = $this->staffServiceShareHundredths($n, (int) $idx);
                $staffHundredths[$sid] = ($staffHundredths[$sid] ?? 0) + $h;
            }
        }

        $list = [];
        foreach ($staffHundredths as $sid => $hundredths) {
            $meta = $staffMeta[$sid] ?? ['staff_name' => '', 'store_id' => 0];
            $list[] = [
                'staff_id' => $sid,
                'staff_name' => $meta['staff_name'],
                'store_id' => $meta['store_id'],
                'yeji' => bcdiv((string) $hundredths, '100', 2),
                'is_dian' => 1,
                'type' => 3,
            ];
        }

        usort($list, function ($a, $b) {
            return bccomp($b['yeji'], $a['yeji'], 2);
        });

        if ($page > 0 && $limit > 0) {
            $list = array_slice($list, ($page - 1) * $limit, $limit);
        }

        return $list;
    }

    //获得点客数量
    public function diankeCount(array $where){
         $result=$this->serviceNum($where);
         return $result;
    }
    //手艺人数
    public function ygCount(array $where){
        $result=$this->search($where)
            ->where("is_dian",1)
            ->group("staff_id")
            ->count();
        return $result;
    }

    /**
     * 服务对象：订单与核销记录均可能有。
     * 劳动业绩(type=3)以核销记录为准——核销时选的「朋友」写在 writeoff 上，订单侧常为默认「本人」，若优先订单会把朋友误判成本人，
     * 导致仍走会员分组(m_日期_uid)而无法按游客/朋友单独计 1 客。
     */
    protected function serviceNumResolveServiceObject(array $nv): string
    {
        $type = (int) ($nv['type'] ?? 0);
        $wo = trim((string) ($nv['service_object_writeoff'] ?? ''));
        $ord = trim((string) ($nv['service_object_order'] ?? ''));
        if ($type === 3) {
            if ($wo !== '') {
                return $wo;
            }
            return $ord;
        }
        if ($ord !== '') {
            return $ord;
        }
        return $wo;
    }

    /** 「朋友」与游客同一套客数规则（不按会员 uid 做同日去重） */
    protected function serviceNumIsFriendGuestRule(array $nv): bool
    {
        return $this->serviceNumResolveServiceObject($nv) === '朋友';
    }

    /**
     * 游客/朋友：同一自然日内有几条业绩（核销）记录算几次客，不按订单合并。
     * 分组键仅用于均摊时隔离每条 staff_yeji 记录（同日多条即多组）。
     */
    protected function serviceNumBuildGuestGroupKey(array $nv): string
    {
        $day = date('Ymd', strtotime($nv['created_time'] ?? ''));
        $yejiId = (int) ($nv['yeji_id'] ?? 0);
        return 'g_' . $day . '_' . $yejiId;
    }

    //服务客次
    public function serviceNum(array $where){
        $staffId = $where['staff_id'] ?? null;
        $useFraction = $staffId !== null && $staffId !== '' && !is_array($staffId);
        if ($useFraction) {
            return $this->serviceNumFractional($where, (int) $staffId);
        }

        $result=$this->searchJoin($where,"a")
            ->alias("a")
            ->join("store_order b","b.id=a.order_id","left")
            ->join("store_order_writeoff w","w.id=a.link_id AND a.type=3","left")
            ->field("a.type,a.created_time,a.id as yeji_id,b.uid,b.id as order_id,b.service_object as service_object_order,w.service_object as service_object_writeoff")
            ->select();
        $count=0;
        $countAttr=[];
        foreach ($result as $nk=>$nv){
            $nv = is_array($nv) ? $nv : (method_exists($nv, 'toArray') ? $nv->toArray() : []);
            if ((int) ($nv['uid'] ?? 0) === 0 || $this->serviceNumIsFriendGuestRule($nv)) {
                $count++;
            } else {
                $key = date("Ymd", strtotime($nv['created_time'])) . "_" . $nv['uid'];
                if (!isset($countAttr[$key])) {
                    $countAttr[$key] = 1;
                    $count++;
                }
            }
        }
       return $count;
    }

    /**
     * 按员工拆分客次：同一自然日、同一客户计 1 个客，参与该「销售/耗卡」场景的去重员工数 N 平分，
     * 保留两位小数，分不尽的尾差归 staff_id 排序后的最后一位（百分之一为单位分配）。
     * 销售(type1/2)与耗卡(type3)由 where 中 sum_type + searchJoin 已有条件区分，互不混算。
     *
     * @param array $where 须含 staff_id（标量）
     * @param int   $targetStaffId
     * @return string 两位小数字符串，与 bcmul 等后续计算兼容
     */
    protected function serviceNumFractional(array $where, int $targetStaffId): string
    {
        if ($targetStaffId <= 0) {
            return '0.00';
        }
        $baseWhere = $where;
        unset($baseWhere['staff_id']);

        $rows = $this->searchJoin($baseWhere, "a")
            ->alias("a")
            ->join("store_order b", "b.id=a.order_id", "left")
            ->join("store_order_writeoff w", "w.id=a.link_id AND a.type=3", "left")
            ->field("a.type,a.id as yeji_id,a.staff_id,a.created_time,b.uid,b.id as order_id,b.service_object as service_object_order,w.service_object as service_object_writeoff")
            ->select();
        if (method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        // groupKey => [staff_id => true]
        $groups = [];
        foreach ($rows as $nv) {
            $nv = is_array($nv) ? $nv : (method_exists($nv, 'toArray') ? $nv->toArray() : []);
            $day = date("Ymd", strtotime($nv['created_time']));
            $uid = (int) ($nv['uid'] ?? 0);
            if ($uid > 0 && !$this->serviceNumIsFriendGuestRule($nv)) {
                $gkey = 'm_' . $day . '_' . $uid;
            } else {
                // 游客、朋友：每条业绩记录单独一组（同日多次核销即多次）
                $gkey = $this->serviceNumBuildGuestGroupKey($nv);
            }
            if (!isset($groups[$gkey])) {
                $groups[$gkey] = [];
            }
            $sid = (int) ($nv['staff_id'] ?? 0);
            if ($sid > 0) {
                $groups[$gkey][$sid] = true;
            }
        }

        $totalHundredths = 0;
        foreach ($groups as $staffMap) {
            $staffIds = array_keys($staffMap);
            sort($staffIds, SORT_NUMERIC);
            $n = count($staffIds);
            if ($n === 0) {
                continue;
            }
            $idx = array_search($targetStaffId, $staffIds, true);
            if ($idx === false) {
                continue;
            }
            $totalHundredths += $this->staffServiceShareHundredths($n, (int) $idx);
        }

        return bcdiv((string) $totalHundredths, '100', 2);
    }

    /**
     * 将 1 个客按百分之一拆分：前 N-1 人均 floor(100/N)/100，最后一人拿剩余，避免浮点误差。
     *
     * @param int $n 去重后的员工数
     * @param int $sortedIndex 当前员工在排序后列表中的下标 0..n-1
     * @return int 份额（百分之一，如 33 表示 0.33）
     */
    protected function staffServiceShareHundredths(int $n, int $sortedIndex): int
    {
        if ($n < 1 || $sortedIndex < 0 || $sortedIndex >= $n) {
            return 0;
        }
        $base = intdiv(100, $n);
        $rem = 100 - $base * $n;
        return $sortedIndex === $n - 1 ? $base + $rem : $base;
    }

    /**
     * 劳动业绩「项目数」：同一核销业绩分组(link_id + goods_id)计 1 个项目，
     * 参与分配的员工数 N 按百分之一平分，尾差归 staff_id 升序后的最后一位（与 serviceNum 客次规则一致）。
     *
     * @param array $where 须含 created_time、sum_type=2 等，与提成明细一致；可含 staff_id（本方法会忽略后重算目标员工）
     * @param int   $targetStaffId
     * @return string 两位小数
     */
    public function projectNumFractional(array $where, int $targetStaffId): string
    {
        if ($targetStaffId <= 0) {
            return '0.00';
        }
        $baseWhere = $where;
        unset($baseWhere['staff_id']);
        $baseWhere['sum_type'] = 2;

        $rows = $this->search($baseWhere)
            ->field('staff_id,link_id,goods_id')
            ->select();
        if (method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        $groups = [];
        foreach ($rows as $nv) {
            $nv = is_array($nv) ? $nv : (method_exists($nv, 'toArray') ? $nv->toArray() : []);
            $lid = (int) ($nv['link_id'] ?? 0);
            if ($lid <= 0) {
                continue;
            }
            $gid = (int) ($nv['goods_id'] ?? 0);
            $gkey = $lid . '_' . $gid;
            if (!isset($groups[$gkey])) {
                $groups[$gkey] = [];
            }
            $sid = (int) ($nv['staff_id'] ?? 0);
            if ($sid > 0) {
                $groups[$gkey][$sid] = true;
            }
        }

        $totalHundredths = 0;
        foreach ($groups as $staffMap) {
            $staffIds = array_keys($staffMap);
            sort($staffIds, SORT_NUMERIC);
            $n = count($staffIds);
            if ($n === 0) {
                continue;
            }
            $idx = array_search($targetStaffId, $staffIds, true);
            if ($idx === false) {
                continue;
            }
            $totalHundredths += $this->staffServiceShareHundredths($n, (int) $idx);
        }

        return bcdiv((string) $totalHundredths, '100', 2);
    }

    /**
     * 单条劳动业绩记录在该核销项目中的「项目数」份额（与 projectNumFractional 同一套拆分）。
     */
    public function laborProjectShareFormatted(int $linkId, int $goodsId, int $staffId): string
    {
        if ($linkId <= 0 || $staffId <= 0) {
            return '0.00';
        }
        $rows = $this->getModel()
            ->where('link_id', $linkId)
            ->where('goods_id', $goodsId)
            ->where('type', 3)
            ->where('status', 0)
            ->column('staff_id');
        if (!is_array($rows)) {
            $rows = [];
        }
        $staffIds = array_values(array_unique(array_map('intval', $rows)));
        sort($staffIds, SORT_NUMERIC);
        $n = count($staffIds);
        if ($n === 0) {
            return '0.00';
        }
        $idx = array_search($staffId, $staffIds, true);
        if ($idx === false) {
            return '0.00';
        }
        $h = $this->staffServiceShareHundredths($n, (int) $idx);

        return bcdiv((string) $h, '100', 2);
    }

    //业绩统计
    public function staffInfo(array $where){
        //不过滤产品业绩
        $info['moneyYeji']=$this->search($where)->sum("yeji");
        $where['sum_type']=2;
        $info['optionYeji']=$this->search($where)->sum("yeji");
        return $info;
    }
}
