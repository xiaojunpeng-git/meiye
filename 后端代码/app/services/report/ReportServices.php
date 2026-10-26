<?php

namespace app\services\report;
use app\dao\order\StoreOrderDao;
use app\dao\order\StoreOrderWriteoffDao;
use app\dao\yeji\StaffYejiDao;
use app\model\order\CombinationOrder;
use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\position\Position;
use app\model\position\PositionLevel;
use app\model\position\PositionYeji;
use app\model\product\product\StoreProduct;
use app\model\product\product\StoreProductRelation;
use app\model\salary\SalaryAgent;
use app\model\salary\SalaryField;
use app\model\salary\SalaryInfo;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\user\User;
use app\model\yeji\CashSource;
use app\model\yeji\StaffYeji;
use app\Request;
use app\services\order\ValidCashOrderServices;
use app\services\order\store\BranchOrderServices;
use app\services\pay\PayServices;
use think\facade\Db;
use app\services\BaseServices;
/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class ReportServices extends BaseServices
{
    /** @var array{sourceAttr:int[],productIds:int[],hezuofangStaffIds:int[]}|null */
    protected $reportSaleSourceContextCache = null;

    //门店效能分析
    public function xnList($where){
        $range=$where['date'];
        $theDate=$range;
        if(!empty($range)){
            $range=explode("-",$range);
            $range[0]=strtotime($range[0]." 00:00:00");
            $range[1]=strtotime($range[1]." 23:59:59");
        }else{
            $range=[];
            $range[0]=0;
            $range[1]=time();
        }
        $works=[
            '总消耗','总客数','客单价','日均服务客数','床位数','床位日均数','床位使用率','员工数','人均服务数','人效使用率'
        ];
        $shop=SystemStore::where("is_del",0)->where("name","<>","总部")->select();
        $list=[];
        $consume=[];
        $dayJun=[];
        $chuangJun=[];
        $shouyi=[];
        $renxiao=[];
        $consumeCount=[];
        $dao=app()->make(StaffYejiDao::class);
        $header=['门店'];
        foreach ($works as $kk=>$vv){
            $result=[];
            $result['work_name'] = $vv;
            foreach ($shop as $k=>$v) {
                switch ($kk){
                    case 0:
                        //总消耗
                        $result[$v['id']] =$this->consume($range,$v['id'],0);
                        $consume[$v['id']]=$result[$v['id']];
                        break;
                    case 1:
                        //总客数
                        $result[$v['id']] =$this->consume($range,$v['id'],1);
                        $consumeCount[$v['id']]=$result[$v['id']];
                        break;
                    case 2:
                        //客单价:总消耗/总客数
                        if($consumeCount[$v['id']] > 0){
                            $result[$v['id']]=bcdiv($consume[$v['id']],$consumeCount[$v['id']]);
                        }else{
                            $result[$v['id']]=0;
                        }
                        break;
                    case 3:
                        //日均服务客数:总客数/查询天数
                        $timeDifference = abs($range[1]-$range[0]);
                        $daysInt = floor($timeDifference / 86400);
                        if(empty($daysInt)){
                            $daysInt=1;
                        }
                        $result[$v['id']] =bcdiv($consumeCount[$v['id']],$daysInt);
                        $dayJun[$v['id']]=$result[$v['id']];
                        break;
                    case 4:
                        //床位数:门店设置的
                        $result[$v['id']] =$v['space_num'];
                        break;
                    case 5:
                        //床位日均数:日均服务客数/床位数
                        if($v['space_num'] > 0){
                            $result[$v['id']] =bcdiv($dayJun[$v['id']],$v['space_num']);
                        }else{
                            $result[$v['id']] =0;
                        }
                        $chuangJun[$v['id']]=$result[$v['id']];
                        break;
                    case 6:
                        //床位使用率:床位日均数/7
                        $result[$v['id']] = bcmul($chuangJun[$v['id']]/7,100)."%";
                        break;
                    case 7:
                        //员工数:统计时间内的手艺人人数
                        $result[$v['id']] =$this->ygCount($theDate,$v['id'],$dao);
                        $shouyi[$v['id']]=$result[$v['id']];
                        break;
                    case 8:
                        //人均服务数:日均服务客数/员工数
                        if($shouyi[$v['id']] > 0){
                            $result[$v['id']] =bcdiv($dayJun[$v['id']],$shouyi[$v['id']]);
                        }else{
                            $result[$v['id']] = 0;
                        }
                        $renxiao[$v['id']]=$result[$v['id']];
                        break;
                    case 9:
                        //人效使用率:人均服务数/4.5
                        $result[$v['id']] =bcdiv($renxiao[$v['id']],4.5);
                        break;
                    default:
                        $result[$v['id']]=0;
                        break;
                }
            }
            $list[] = $result;
        }
        foreach ($shop as $k=>$v) {
            $header[]=$v['name'];
        }
        if($where['is_excel'] == 1){
            //导出
            $filekey =array_keys($list[0] ?? []);
            $export =$list;
            $filename = '门店效能' . date('YmdHis', time());
            $list=compact('header', 'filekey', 'export', 'filename');
        }
        return $list;
    }

    //注册会员数
    public function registerNum($range,$storeId){
         $userCount=User::whereBetween("add_time",$range)->where("belong_store_id",$storeId)->count();
         return $userCount;
    }
    //门店项目销售分析
    public function fenxiList($where){
        $range=$where['date'];
        $theDate=$range;
        if(!empty($range)){
            $range=explode("-",$range);
            $range[0]=strtotime($range[0]." 00:00:00");
            $range[1]=strtotime($range[1]." 23:59:59");
        }else{
            $range=[];
            $range[0]=0;
            $range[1]=time();
        }
        $works=[
            '新客数','新客总客数','成交率','流失新客数','指定客数','总客数','指定率'
        ];
        $productIds=StoreProductRelation::where("relation_id",78)->where("type",1)->column("product_id");
        $shop=SystemStore::where("is_del",0)->where("name","<>","总部")->select();
        $list=[];
        $dainke=[];
        $huiyuan=[];
        $xinke=[];
        $zongke=[];
        $dao=app()->make(StaffYejiDao::class);
        $sourceAttr=CashSource::whereNotIn("id",[6,7,11,12])->column("id");
        foreach ($works as $kk=>$vv){
            $result=[];
            $result['work_name'] = $vv;
            foreach ($shop as $k=>$v) {
                switch ($kk){
                    case 0:
                        //新客成交数:门店客户来源分析里的:新客+大众新客+抖音新客+其他新客
                        $result[$v['id']] =$this->sourceOrder($sourceAttr,1,$range,1,$v['id'],$productIds);
                        $xinke[$v['id']]=$result[$v['id']];
                        break;
                    case 1:
                        //新客总客数:门店客户来源分析里的:新客+大众新客+抖音新客+散客++大众散客+抖音散客
                        $canke =$this->sourceOrder($sourceAttr,0,$range,1,$v['id'],$productIds);
                        $result[$v['id']] =bcadd($xinke[$v['id']],$canke);
                        $zongke[$v['id']]=$result[$v['id']];
                        break;
                    case 2:
                        //成交率:新客/总客数
                        if(empty($zongke[$v['id']])){
                            $result[$v['id']] = "0%";
                        }else{
                            $result[$v['id']] = bcmul($xinke[$v['id']]/$zongke[$v['id']],100)."%";
                        }
                        break;
                    case 3:
                        //流失新客数:总客数-新客
                        $result[$v['id']] =bcsub($zongke[$v['id']],$xinke[$v['id']]);
                        break;
                    case 4:
                        //指定客数:就是点客数
                        $result[$v['id']] =$this->dianke($theDate,$v['id'],$dao);
                        $dainke[$v['id']]=$result[$v['id']];
                        break;
                    case 5:
                        //会员客数:门店客户来源分析里的会员续卡+售后客户
                        $result[$v['id']]=$this->consume($range, $v['id'], 1);
                        $huiyuan[$v['id']]=$result[$v['id']];
                        break;
                    case 6:
                        //指定率:指定客数/会员客数
                        if(empty($huiyuan[$v['id']])){
                            $result[$v['id']] = "0%";
                        }else{
                            $result[$v['id']] = bcmul($dainke[$v['id']]/$huiyuan[$v['id']],100)."%";
                        }
                        break;
                    default:
                        $result[$v['id']]=0;
                        break;
                }
            }
            $list[] = $result;
        }
        if($where['is_excel'] == 1){
            //导出
            $filekey =array_keys($list[0] ?? []);
            $export =$list;
            $header=['门店'];
            foreach ($shop as $k=>$v) {
                $header[]=$v['name'];
            }
            $filename = '销售分析' . date('YmdHis', time());
            $list=compact('header', 'filekey', 'export', 'filename');
        }
        return $list;
    }
    //门店端客户来源分析
    public function storeReportList($where){
        [$page, $limit] = $this->getPageValue();
        if($page > 1){
            return ['list'=>[],'count'=>0];
        }
        $range=$where['date'];
        if(!empty($range)){
            $range=explode("-",$range);
            $range[0]=strtotime($range[0]);
            $range[1]=strtotime($range[1]." 23:59:59");
        }else{
            $range=[];
            $range[0]=0;
            $range[1]=time();
        }
        $works=[
             '散客','新客','大众散客','大众新客','抖音散客','抖音新客','会员续卡','售后客','合作项目','现金业绩合计','耗卡业绩','成交新客数','服务总新客','新客成交率'
        ];
        $dao=app()->make(StoreOrderDao::class);
        $hand_where = ['paid' => 1, 'pid' =>-2, 'is_system_del' => 0, 'refund_status' => [0, 3],'link_type'=>2];
        $hkWhere=$where;
        $hkWhere['time']=$range;
        unset($hkWhere['date']);
        $productIds=StoreProductRelation::where("relation_id",78)->where("type",1)->column("product_id");
        $sourceAttr=CashSource::whereNotIn("id",[1,2,6,7,11,12])->column("id");
        $list=[];
        $total=[];
        $totalKe=0;
        $xinke=0;
        $reportServices=app()->make(ReportServices::class);
        foreach ($works as $kk=>$vv){
           $one['fx_kehuleibie']=$vv;
            switch ($kk){
                case 0:
                    $one['fx_renshu']=$this->sourceOrder($sourceAttr,0,$range,1,$where['store_id'],$productIds);
                    $one['fx_yeji']=$this->sourceOrder($sourceAttr,0,$range,0,$where['store_id'],$productIds);
                    $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    $totalKe=bcadd($totalKe,$one['fx_renshu']);
                    break;
                case 1:
                    $one['fx_renshu']=$this->sourceOrder($sourceAttr,1,$range,1,$where['store_id'],$productIds);
                    $one['fx_yeji']=$this->sourceOrder($sourceAttr,1,$range,0,$where['store_id'],$productIds);
                    $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    $totalKe=bcadd($totalKe,$one['fx_renshu']);
                    $xinke=bcadd($xinke,$one['fx_renshu']);
                    break;
                case 2:
                    $one['fx_renshu']=$this->sourceOrder(1,0,$range,1,$where['store_id'],$productIds);
                    $one['fx_yeji']=$this->sourceOrder(1,0,$range,0,$where['store_id'],$productIds);
                    $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    $totalKe=bcadd($totalKe,$one['fx_renshu']);
                    break;
                case 3:
                    $one['fx_renshu']=$this->sourceOrder(1,1,$range,1,$where['store_id'],$productIds);
                    $one['fx_yeji']=$this->sourceOrder(1,1,$range,0,$where['store_id'],$productIds);
                    $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    $totalKe=bcadd($totalKe,$one['fx_renshu']);
                    $xinke=bcadd($xinke,$one['fx_renshu']);
                    break;
                case 4:
                    $one['fx_renshu']=$this->sourceOrder(2,0,$range,1,$where['store_id'],$productIds);
                    $one['fx_yeji']=$this->sourceOrder(2,0,$range,0,$where['store_id'],$productIds);
                    $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    $totalKe=bcadd($totalKe,$one['fx_renshu']);
                    break;
                case 5:
                    $one['fx_renshu']=$this->sourceOrder(2,1,$range,1,$where['store_id'],$productIds);
                    $one['fx_yeji']=$this->sourceOrder(2,1,$range,0,$where['store_id'],$productIds);
                    $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    $totalKe=bcadd($totalKe,$one['fx_renshu']);
                    $xinke=bcadd($xinke,$one['fx_renshu']);
                    break;
                case 6:
                    $one['fx_renshu']=$this->sourceOrder(6,0,$range,1,$where['store_id'],$productIds,true);
                    $one['fx_yeji']=$this->sourceOrder(6,0,$range,0,$where['store_id'],$productIds,true);
                    $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    break;
                case 7:
                    $one['fx_renshu']=$this->sourceOrderAfter(11,$range,1,$where['store_id'],$productIds);
                    $one['fx_yeji']=$this->sourceOrderAfter(11,$range,0,$where['store_id'],$productIds);
                    $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    break;
                 case 8:
                    $one['fx_renshu']=$this->sourceOrder(6,0,$range,1,$where['store_id'],$productIds,true,true);
                    $one['fx_yeji']=$this->sourceOrder(6,0,$range,0,$where['store_id'],$productIds,true,true);
                     $total=$this->addTotal($one['fx_yeji'],$where['store_id'],0,$total);
                    break;
                 case 9:
                    $one['fx_renshu']=$this->consume($range, $where['store_id'], 1);
                    $one['fx_yeji']=$total[$where['store_id']]['money'];
                    break;
                 case 10:
                     $one['fx_renshu']=0;
                     $one['fx_yeji']=$this->activeYejiAll($hkWhere); //不扣除合作类项目
                    break;
                case 11:
                    $one['fx_renshu']=$xinke;
                    $one['fx_yeji']=0;
                    break;
                case 12:
                    $one['fx_renshu']=$totalKe;
                    $one['fx_yeji']=0;
                    break;
                case 13:
                    if($totalKe > 0){
                         $one['fx_renshu']=bcmul($xinke/$totalKe,100,2)."%";
                    }else{
                        $one['fx_renshu']=0;
                    }
                    $one['fx_yeji']=0;
                    break;
            }
            $list[]=$one;
        }
        $count=1;
        return compact('list', 'count');
    }
    //消耗业绩--排除合作类项目
    public function activeYeji($where){
        $productIds=StoreProductRelation::where("relation_id",78)->where("type",1)->column("product_id");
        $dao=app()->make(StoreOrderWriteoffDao::class);
        $yeji=$dao->search($where)->where("relation_id",$where['store_id'])->whereNotIn("product_id",$productIds)
            ->sum("writeoff_price");
        return $yeji;
    }

    /**
     * 多门店消耗合计：对 mapActiveYejiByStores 求和（与逐店 activeYeji 语义一致）。
     *
     * @param array $where 须含 time；store_id 可为 int|int[]
     */
    public function sumActiveYejiByStores(array $where): string
    {
        $map = $this->mapActiveYejiByStores($where);
        $total = '0.00';
        foreach ($map as $sum) {
            $total = bcadd($total, (string)($sum ?: 0), 2);
        }
        return $total;
    }

    /**
     * 多门店消耗业绩按店 map：一次 GROUP BY relation_id（与 activeYeji 同口径）。
     *
     * @param array $where 须含 time；store_id 可为 int|int[]
     * @return array<int, string> store_id => writeoff 合计
     */
    public function mapActiveYejiByStores(array $where): array
    {
        $storeIds = $where['store_id'] ?? [];
        if (!is_array($storeIds)) {
            $storeIds = $storeIds !== '' && $storeIds !== null ? [(int)$storeIds] : [];
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $out = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = '0.00';
            }
        }
        if (!$out) {
            return [];
        }
        $productIds = $this->buildReportSaleSourceContext()['productIds'] ?? [];
        $base = $where;
        unset($base['store_id']);
        /** @var StoreOrderWriteoffDao $dao */
        $dao = app()->make(StoreOrderWriteoffDao::class);
        $rows = $dao->search($base)
            ->whereIn('relation_id', array_keys($out))
            ->when(!empty($productIds), function ($q) use ($productIds) {
                $q->whereNotIn('product_id', $productIds);
            })
            ->field('relation_id, SUM(writeoff_price) AS total')
            ->group('relation_id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $sid = (int)($row['relation_id'] ?? 0);
            if ($sid > 0 && isset($out[$sid])) {
                $out[$sid] = bcadd('0', (string)($row['total'] ?? 0), 2);
            }
        }
        return $out;
    }

    /**
     * 销售数据表 report_sale 共用上下文（来源排除 / 合作类商品 / 合作方员工）。
     *
     * @return array{sourceAttr:int[],productIds:int[],hezuofangStaffIds:int[]}
     */
    public function buildReportSaleSourceContext(): array
    {
        if ($this->reportSaleSourceContextCache !== null) {
            return $this->reportSaleSourceContextCache;
        }
        $this->reportSaleSourceContextCache = [
            'sourceAttr' => CashSource::whereNotIn('id', [1, 2, 6, 7, 11, 12])->column('id') ?: [],
            'productIds' => StoreProductRelation::where('relation_id', 78)->where('type', 1)->column('product_id') ?: [],
            'hezuofangStaffIds' => SystemStoreStaff::where('is_hezuofang', 1)->column('id') ?: [],
        ];
        return $this->reportSaleSourceContextCache;
    }

    /**
     * 与 sourceOrder(isCount 路径) 同过滤的多店基础查询（不含 isCount 去重）。
     *
     * @param int[] $storeIds
     * @param int[]|int $sourceAttr
     * @param int $isNew 0=散客 1=新客
     * @param array $range [startTs, endTs]
     * @param int[]|null $hezuofangStaffIds 传入则不再查库
     * @return mixed
     */
    public function buildSourceOrderBaseQuery(array $storeIds, $sourceAttr, int $isNew, array $range, ?array $hezuofangStaffIds = null)
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $query = StoreOrder::alias('a')
            ->where('a.paid', 1)
            ->where(function ($q) {
                $q->whereIn('a.pid', [0, -2])->whereOr('a.pid', '>', 0);
            })
            ->where('a.refund_status', 0)
            ->where('a.is_system_del', 0)
            ->whereIn('a.store_id', $storeIds ?: [0])
            ->whereIn('a.order_type', [0, 1]);
        if (is_array($sourceAttr)) {
            $query->whereIn('a.source', $sourceAttr ?: [0]);
        } else {
            $query->where('a.source', (int)$sourceAttr);
        }
        $hezuofang = $hezuofangStaffIds;
        if ($hezuofang === null) {
            $hezuofang = SystemStoreStaff::where('is_hezuofang', 1)->column('id') ?: [];
        }
        if (!empty($hezuofang)) {
            // 与单店 sourceOrder 同语义：仅排除「同店」合作人员业绩行（sy.store_id = a.store_id）
            $yejiTable = Db::name('staff_yeji')->getTable();
            $staffIdList = implode(',', array_map('intval', $hezuofang));
            $query->whereRaw(
                "NOT EXISTS (SELECT 1 FROM {$yejiTable} sy WHERE sy.order_id = a.id AND sy.store_id = a.store_id AND sy.status = 0 AND sy.staff_id IN ({$staffIdList}) AND sy.type IN (1,2))"
            );
        }
        if ($isNew) {
            $query->where('a.uid', '>', 0)->where('a.cash_pay_price', '>=', 298);
        } else {
            $query->where(function ($d) {
                $d->whereOr(function ($q) {
                    $q->where('a.uid', 0);
                })->whereOr(function ($q) {
                    $q->where('a.cash_pay_price', '<', 298);
                });
            });
        }
        if (!empty($range) && isset($range[0], $range[1])) {
            $query->whereBetween('a.add_time', $range);
        }
        ValidCashOrderServices::applyScope($query, 'a');
        return $query;
    }

    /**
     * 生产固定：SQL 批量分组统计散客/新客人数（策略 B）。
     * 会员：uid>0 且非「朋友」→ 按店对 DISTINCT(自然日+uid) 计数；
     * 游客/朋友：uid=0 或 service_object='朋友' → COUNT(*) 按店。
     *
     * @param int[] $storeIds
     * @param int $isNew
     * @param array $range
     * @return array<int, int> store_id => 人数
     */
    public function countSourceOrderByStores(array $storeIds, int $isNew, array $range): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $out = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = 0;
            }
        }
        if (!$out) {
            return [];
        }
        $ctx = $this->buildReportSaleSourceContext();
        $sourceAttr = $ctx['sourceAttr'] ?? [];
        $hezuofang = $ctx['hezuofangStaffIds'] ?? [];
        $baseIds = array_keys($out);

        $memberRows = $this->buildSourceOrderBaseQuery($baseIds, $sourceAttr, $isNew, $range, $hezuofang)
            ->where('a.uid', '>', 0)
            ->whereRaw("TRIM(IFNULL(a.service_object,'')) <> '朋友'")
            ->field("a.store_id, COUNT(DISTINCT CONCAT(FROM_UNIXTIME(a.add_time, '%Y%m%d'), '_', a.uid)) AS cnt")
            ->group('a.store_id')
            ->select()
            ->toArray();
        foreach ($memberRows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if (isset($out[$sid])) {
                $out[$sid] += (int)($row['cnt'] ?? 0);
            }
        }

        $guestRows = $this->buildSourceOrderBaseQuery($baseIds, $sourceAttr, $isNew, $range, $hezuofang)
            ->where(function ($q) {
                $q->where('a.uid', 0)->whereOrRaw("TRIM(IFNULL(a.service_object,'')) = '朋友'");
            })
            ->field('a.store_id, COUNT(*) AS cnt')
            ->group('a.store_id')
            ->select()
            ->toArray();
        foreach ($guestRows as $row) {
            $sid = (int)($row['store_id'] ?? 0);
            if (isset($out[$sid])) {
                $out[$sid] += (int)($row['cnt'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * 对 countSourceOrderByStores 结果求和。
     *
     * @param int[] $storeIds
     * @param int $isNew
     * @param array $range
     */
    public function sumSourceOrderCountsByStores(array $storeIds, int $isNew, array $range): int
    {
        return (int)array_sum($this->countSourceOrderByStores($storeIds, $isNew, $range));
    }

    /**
     * 【仅小样本对账】PHP 去重，与旧 sourceOrder(isCount=1) 逐店逻辑一致。
     * 禁止生产路径（看板/reportList）调用。
     *
     * @param int[] $storeIds
     * @param int $isNew
     * @param array $range
     * @return array<int, int>
     */
    public function countSourceOrderByStoresViaPhp(array $storeIds, int $isNew, array $range): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $out = [];
        foreach ($storeIds as $sid) {
            if ($sid > 0) {
                $out[$sid] = 0;
            }
        }
        if (!$out) {
            return [];
        }
        $ctx = $this->buildReportSaleSourceContext();
        $rows = $this->buildSourceOrderBaseQuery(array_keys($out), $ctx['sourceAttr'] ?? [], $isNew, $range)
            ->field('a.id,a.store_id,a.uid,a.add_time,a.service_object')
            ->select()
            ->toArray();
        $seen = [];
        foreach ($rows as $nv) {
            $sid = (int)($nv['store_id'] ?? 0);
            if (!isset($out[$sid])) {
                continue;
            }
            $svcObj = trim((string)($nv['service_object'] ?? ''));
            if ((int)($nv['uid'] ?? 0) === 0 || $svcObj === '朋友') {
                $out[$sid]++;
            } else {
                $key = $sid . '_' . date('Ymd', (int)$nv['add_time']) . '_' . (int)$nv['uid'];
                if (!isset($seen[$key])) {
                    $seen[$key] = 1;
                    $out[$sid]++;
                }
            }
        }
        return $out;
    }

    //消耗业绩--含合作类项目（不扣除）
    public function activeYejiAll($where){
        $dao=app()->make(StoreOrderWriteoffDao::class);
        return $dao->search($where)->where("relation_id",$where['store_id'])->sum("writeoff_price");
    }

    /**
     * 与 storeapi/home/header 一致的门店劳动/扣卡业绩（供工资表展示）
     * store_writeoff_order_price = activeYeji + 旧店耗卡
     * store_use_yue = 订单 yue_pay_price 汇总
     */
    public function getStoreHomeHeaderMetrics(int $storeId, array $range): array
    {
        $where = [
            'store_id' => $storeId,
            'time' => [strtotime($range[0]), strtotime($range[1])],
        ];
        /** @var BranchOrderServices $branchOrderServices */
        $branchOrderServices = app()->make(BranchOrderServices::class);
        $statics = $branchOrderServices->homeStatics($where);
        return [
            'store_writeoff_order_price' => $statics['store_writeoff_order_price'] ?? 0,
            'store_use_yue' => $statics['store_use_yue'] ?? 0,
        ];
    }
    //门店客户来源分析
    public function reportList($where){
        $range=$where['date'];
        if(!empty($range)){
            $range=explode("-",$range);
            $range[0]=strtotime($range[0]." 00:00:00");
            $range[1]=strtotime($range[1]." 23:59:59");
        }else{
            $range=[];
            $range[0]=0;
            $range[1]=time();
        }
        $works=[
            '散客','散客业绩','新客','新客业绩','大众散客','大众散客业绩','大众新客','大众新客业绩','抖音散客','抖音散客业绩',
            '抖音新客','抖音新客业绩','会员续卡','会员续卡业绩','售后客数','售后业绩','合作项目','合作项目业绩',
            '总客数','门店总业绩'
        ];
        $count=count($works);
        [$page, $limit] = $this->getPageValue();
        $ctx = $this->buildReportSaleSourceContext();
        $productIds = $ctx['productIds'];
        $sourceAttr = $ctx['sourceAttr'];
        $shop=SystemStore::where("is_del",0)->where("name","<>","总部")->select();
        $shopIds = [];
        foreach ($shop as $sv) {
            $shopIds[] = (int)$sv['id'];
        }
        // 散客/新客人数：一次批量 SQL，禁止 foreach 门店 × sourceOrder
        $casualCountByStore = $this->countSourceOrderByStores($shopIds, 0, $range);
        $newCountByStore = $this->countSourceOrderByStores($shopIds, 1, $range);
        $list=[];
        $total=[];
        foreach ($works as $kk=>$vv){
            $result=[];
            $result['work_name'] = $vv;
            foreach ($shop as $k=>$v) {
                switch ($kk){
                    case 0:
                        $result[$v['id']] = (int)($casualCountByStore[(int)$v['id']] ?? 0);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 1:
                        $result[$v['id']] =$this->sourceOrder($sourceAttr,0,$range,0,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 2:
                        $result[$v['id']] = (int)($newCountByStore[(int)$v['id']] ?? 0);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 3:
                        $result[$v['id']] =$this->sourceOrder($sourceAttr,1,$range,0,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 4:
                        $result[$v['id']] =$this->sourceOrder(1,0,$range,1,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 5:
                        $result[$v['id']] =$this->sourceOrder(1,0,$range,0,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 6:
                        $result[$v['id']] =$this->sourceOrder(1,1,$range,1,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 7:
                        $result[$v['id']] =$this->sourceOrder(1,1,$range,0,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 8:
                        $result[$v['id']] =$this->sourceOrder(2,0,$range,1,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 9:
                        $result[$v['id']] =$this->sourceOrder(2,0,$range,0,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 10:
                        $result[$v['id']] =$this->sourceOrder(2,1,$range,1,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 11:
                        $result[$v['id']] =$this->sourceOrder(2,1,$range,0,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 12:
                        //会员续卡
                        $result[$v['id']] =$this->sourceOrder(6,0,$range,1,$v['id'],$productIds,true);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 13:
                        //会员续卡业绩
                        $result[$v['id']] =$this->sourceOrder(6,0,$range,0,$v['id'],$productIds,true);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 14:
                        //售后客数--类型为老客扣卡
//                        $result[$v['id']] =$this->payAfter($range,1,$v['id'],$productIds);
                        $result[$v['id']] = $this->sourceOrderAfter(11,$range,1,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 15:
                        //售后业绩
                        $result[$v['id']] = $this->sourceOrderAfter(11,$range,0,$v['id'],$productIds);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 16:
                        //合作项目
                        $result[$v['id']] =$this->sourceOrder(6,0,$range,1,$v['id'],$productIds,true,true);
                        $total=$this->addTotal($result[$v['id']],$v['id'],1,$total);
                        break;
                    case 17:
                        //合作项目业绩
                        $result[$v['id']] =$this->sourceOrder(6,0,$range,0,$v['id'],$productIds,true,true);
                        $total=$this->addTotal($result[$v['id']],$v['id'],0,$total);
                        break;
                    case 18:
                        //总客数
//                        $result[$v['id']]=$total[$v['id']]['count'];
                        $result[$v['id']]=$this->consume($range, $v['id'], 1);
                        break;
                    case 19:
                        //总业绩
                        $result[$v['id']]=$total[$v['id']]['money'];
                        break;
                    default:
                        $result[$v['id']]=0;
                        break;
                }
            }
            $list[] = $result;
        }
        $list=$this->arrayPaginate($list,$page,$limit);
        $header=['门店'];
        foreach ($shop as $k=>$v) {
            $header[]=$v['name'];
        }
        if($where['is_excel'] == 1){
            //导出
            $filekey =array_keys($list[0] ?? []);
            $export =$list;
            if($page > 2){
                $export=[];
            }
            $filename = '门店销售数据' . date('YmdHis', time());
            $list=compact('header', 'filekey', 'export', 'filename');
            return $list;
        }
        return compact('list', 'count');
    }

    /**
     * PHP数组分页工具函数
     * @param array $array 原数组（需分页的数组）
     * @param int $page 当前页码（默认1，从1开始）
     * @param int $pageSize 每页条数（默认10）
     * @return array 包含分页结果的数组：
     *               - list: 当前页数据
     *               - total: 原数组总条数
     *               - totalPage: 总页数
     *               - currentPage: 当前页（已修正边界）
     *               - pageSize: 每页条数
     */
    public function arrayPaginate(array $array, int $page = 1, int $pageSize = 10): array {
        // 1. 基础校验：空数组直接返回空结果
        $total = count($array);
        if ($total === 0) {
            return [
                'list' => [],
                'total' => 0,
                'totalPage' => 0,
                'currentPage' => $page,
                'pageSize' => $pageSize
            ];
        }

        // 2. 修正非法参数：页码/每页条数不能小于1（仅修正下限，不修正上限）
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        // 3. 计算总页数和起始偏移量
        $totalPage = ceil($total / $pageSize);
        $offset = ($page - 1) * $pageSize;

        // 4. 核心逻辑：页码超限时返回空数组，否则截取当前页数据
        $list = [];
        if ($page <= $totalPage) { // 页码未超限，正常截取
            $list = array_slice($array, $offset, $pageSize);
        }
        // 5. 返回完整的分页结果（原代码只return $list是笔误，需返回完整参数）
        return $list;
    }
    public function addTotal($number,$storeId,$isCount=0,$total){
        if(isset($total[$storeId])){
            if($isCount == 1){
                $total[$storeId]['count']=$total[$storeId]['count']+$number;
            }else{
                $total[$storeId]['money']=$total[$storeId]['money']+$number;
            }
        }else{
            if($isCount == 1){
                $total[$storeId]=['count'=>$number,'money'=>0];
            }else{
                $total[$storeId]=['count'=>0,'money'=>$number];
            }
        }
        return $total;
    }

    //type 1普通订单并且余额支付 2次卡核销
    public function kuadian($range,$storeId,$type,$kuaStore){
        $countOrder=StoreOrder::where("paid",1)
            ->where(function ($query){
                $query->whereIn("pid",[0,-2])->whereOr("pid",">",0);
            })
            ->where('refund_status',0)
            ->where('is_system_del', 0);
        if(!empty($storeId)){
            $countOrder=$countOrder->where("store_id",$storeId)->where("kua_store",">",0);
        }
        if(!empty($kuaStore)){
            $countOrder=$countOrder->where("kua_store",$kuaStore);
        }
        if(!empty($range)){
            $countOrder=$countOrder->whereBetween("add_time",$range);
        }
        if($type == 1){
            //余额支付
            $countOrder->where("order_type",0)->where("yue_pay_price",">",0);
            $countOrder = $countOrder->sum("yue_pay_price");
        }
        if($type == 2){
            $countOrder->where("order_type",2);
            $countOrder = $countOrder->sum("pay_price");
        }
        return $countOrder;
    }

    //售后相关
    public function sourceOrderAfter($source,$range,$isCount=1,$storeId,$productIds)
    {
        $countOrder = StoreOrder::alias("a")
            ->field("a.*")
            ->where("a.paid", 1)
            ->where(function ($query) {
                $query->whereIn("a.pid", [0, -2])->whereOr("a.pid", ">", 0);
            })
            ->where('a.refund_status', 0)
            ->where('a.is_system_del', 0)
            ->where("a.store_id", $storeId);
        // 排除分配给合作人员（is_fencheng=1）的订单，与 sourceOrder 一致
        $countOrder = $this->excludeCooperationStaffOrders($countOrder, $storeId);
        $countOrder = $countOrder->where("a.uid",">",0);
         if(!empty($range)){
              $countOrder=$countOrder->whereBetween("a.add_time",$range);
          }
          ValidCashOrderServices::applyScope($countOrder, 'a');
          if($isCount == 1){
              $countOrder=$countOrder->where(function ($query) use ($source){
                  $query->where(function ($q) use ($source){
                      $q->whereIn("a.order_type",[0,1])
                          ->where("a.source",$source);
                      ValidCashOrderServices::applyHasValidCash($q, 'a');
                  })->whereOr(function ($d){
                      $d->where("a.order_type",2);
                  });
                  })->select();
                  $count=0;
                  $countAttr=[];
                  foreach ($countOrder as $nk=>$nv){
                      $svcObj = trim((string) ($nv['service_object'] ?? ''));
                      if ($nv['uid'] == 0 || $svcObj === '朋友') {
                          $count++;
                      }else {
                          $key = date("Ymd", $nv['add_time']) . "_" . $nv['uid'];
                          if (!isset($countAttr[$key])) {
                              $countAttr[$key] = 1;
                              $count++;
                          }
                      }
                  }
                  $countOrder=$count;
          }else{
              $countOrder=$countOrder->whereIn("a.order_type",[0,1])
                  ->where("a.source",$source);
              ValidCashOrderServices::applyHasValidCash($countOrder, 'a');
                $countOrder = $countOrder->sum(Db::raw(ValidCashOrderServices::buildAmountExpr('a', 'a.cash_pay_price')));
          }
          return $countOrder;
    }

    //不包含售后
    public function sourceOrder($source,$isNew,$range,$isCount=1,$storeId,$productIds,$isXuka=false,$isXmu=false){
        $countOrder=StoreOrder::alias("a")
            ->field("a.*")
            ->where("a.paid",1)
            ->where(function ($query){
                $query->whereIn("a.pid",[0,-2])->whereOr("a.pid",">",0);
            })
            ->where('a.refund_status',0)
            ->where('a.is_system_del', 0)
            ->where("a.store_id",$storeId);
        $countOrder=$countOrder->whereIn("a.order_type",[0,1]);
        if(!$isXmu && $source != 11){
            if(is_array($source)){
                $countOrder=$countOrder->whereIn("a.source",$source);
            }else{
                $countOrder=$countOrder->where("a.source",$source);
            }
            // 排除分配给合作人员（is_fencheng=1）的订单
            $countOrder = $this->excludeCooperationStaffOrders($countOrder, $storeId);
        }else{
            //合作项目：仅统计分配给合作人员的订单
            $fencheng=SystemStoreStaff::where("is_hezuofang",1)->column("id");
            if(empty($fencheng)){
                $countOrder=$countOrder->where("a.id",0);
            }else{
                $countOrder=$countOrder->whereIn("a.id",function ($q) use ($fencheng,$storeId){
                    $q->name('staff_yeji')
                        ->where('status',0)
                        ->where('store_id',$storeId)
                        ->whereIn('staff_id',$fencheng)
                        ->whereIn('type',[1,2])
                        ->field('order_id')
                        ->select();
                });
            }
        }
        if(!$isXuka) {
            if ($isNew) {
                $countOrder = $countOrder->where("a.uid",">",0)->where("a.cash_pay_price", ">=", 298);
            } else {
                $countOrder = $countOrder->where(function ($d){
                    $d->whereOr(function ($query) {
                        $query->where("a.uid",0);
                    })->whereOr(function ($query) {
                        $query->where("a.cash_pay_price", "<", 298);
                    });
                });
            }
        }
        if(!empty($range)){
            $countOrder=$countOrder->whereBetween("a.add_time",$range);
        }
        ValidCashOrderServices::applyScope($countOrder, 'a');
        if($isXuka){
            //会员续卡排除余额支付
            if($isXmu){
                if($isCount != 1){
                    ValidCashOrderServices::applyHasValidCash($countOrder, 'a');
                }
            }else {
                ValidCashOrderServices::applyHasValidCash($countOrder, 'a');
            }
        }
        if($isCount  == 1) {
            $countOrder=$countOrder->select();
            $count=0;
            $countAttr=[];
            foreach ($countOrder as $nk=>$nv){
                $svcObj = trim((string) ($nv['service_object'] ?? ''));
                if ($nv['uid'] == 0 || $svcObj === '朋友') {
                    $count++;
                }else {
                    $key = date("Ymd", $nv['add_time']) . "_" . $nv['uid'];
                    if (!isset($countAttr[$key])) {
                        $countAttr[$key] = 1;
                        $count++;
                    }
                }
            }
            $countOrder=$count;
        }else {
            $countOrder = $countOrder->sum(Db::raw(ValidCashOrderServices::buildAmountExpr('a', 'a.cash_pay_price')));
        }
        return $countOrder;
    }

    /**
     * 排除合作人员（is_fencheng=1）参与分配的订单
     * @param mixed $query
     * @param int $storeId
     * @return mixed
     */
    protected function excludeCooperationStaffOrders($query, $storeId)
    {
        $fencheng = SystemStoreStaff::where('is_hezuofang', 1)->column('id');
        if (empty($fencheng)) {
            return $query;
        }
        return $query->whereNotIn('a.id', function ($q) use ($fencheng, $storeId) {
            $q->name('staff_yeji')
                ->where('status', 0)
                ->where('store_id', $storeId)
                ->whereIn('staff_id', $fencheng)
                ->whereIn('type', [1, 2])
                ->field('order_id')
                ->select();
        });
    }

    public function sourceStaffOrder($source,$isNew,$range,$isCount=1,$storeId,$productIds,$isXuka=false,$isXmu=false,$staffId){
        $staffId = (int) $staffId;
        $countOrder = $this->buildSourceStaffOrderQuery($source, $isNew, $range, $storeId, $productIds, $isXuka, $isXmu, $staffId);

        if($isCount  == 1) {
            // 与 StaffYejiDao::serviceNumFractional 一致：同一自然日、同一会员计 1 个销售客，按参与员工数 N 平分；
            // 两位小数，分不尽的尾差归 staff_id 升序后的最后一位。
            $allQuery = $this->buildSourceStaffOrderQuery($source, $isNew, $range, $storeId, $productIds, $isXuka, $isXmu, null);
            $rows = $allQuery
                ->field("a.add_time,a.uid,a.id as order_id,a.service_object,s.staff_id,s.id as yeji_id")
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
                $day = date("Ymd", (int) ($nv['add_time'] ?? 0));
                $uid = (int) ($nv['uid'] ?? 0);
                $svcObj = trim((string) ($nv['service_object'] ?? ''));
                if ($uid > 0 && $svcObj !== '朋友') {
                    $gkey = 'm_' . $day . '_' . $uid;
                } else {
                    // 游客/朋友：每条业绩一行一次客，不按订单合并（与 StaffYejiDao 一致）
                    $yejiId = (int) ($nv['yeji_id'] ?? 0);
                    $gkey = 'g_' . $day . '_' . $yejiId;
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
                $idx = array_search($staffId, $staffIds, true);
                if ($idx === false) {
                    continue;
                }
                $totalHundredths += $this->staffServiceShareHundredths($n, (int) $idx);
            }

            return bcdiv((string) $totalHundredths, '100', 2);
        }

        $countOrder = $countOrder->sum("s.yeji");
        return bcadd($countOrder, 0);
    }

    /**
     * sourceStaffOrder / 业绩维度下的 staff_yeji 查询（type 1、2）。
     *
     * @param int|null $filterStaffId 为 null 时不按员工过滤（用于客数均摊聚合）
     */
    protected function buildSourceStaffOrderQuery($source, $isNew, $range, $storeId, $productIds, $isXuka, $isXmu, ?int $filterStaffId)
    {
        $q = StaffYeji::alias("s")
            ->join("store_order a", "s.order_id=a.id", "left")
            ->join('store_order_cart_info ci', 'a.id = ci.oid AND ci.product_id IN (' . implode(',', $productIds) . ')', "left")
            ->where("a.paid", 1)
            ->whereIn("s.type", [1, 2])
            ->whereIn("a.pid", [0, -2])
            ->where('a.refund_status', 0)
            ->where('a.is_system_del', 0)
            ->where("a.store_id", $storeId)
            ->whereIn("a.order_type", [0, 1]);
        if ($filterStaffId !== null && $filterStaffId > 0) {
            $q->where("s.staff_id", $filterStaffId);
        }
        if (!$isXmu && $source != 11) {
            if (is_array($source)) {
                $q = $q->whereIn("a.source", $source)->whereNull('ci.id');
            } else {
                $q = $q->where("a.source", $source)->whereNull('ci.id');
            }
        } else {
            $q = $q->whereNotNull('ci.id');
        }
        if (!$isXuka) {
            if ($isNew) {
                $q = $q->where("a.uid", ">", 0)->where("a.cash_pay_price", ">=", 298);
            } else {
                $q = $q->where(function ($d) {
                    $d->whereOr(function ($query) {
                        $query->where("a.uid", 0);
                    })->whereOr(function ($query) {
                        $query->where("a.cash_pay_price", "<", 298);
                    });
                });
            }
        }
        if (!empty($range)) {
            $q = $q->whereBetween("a.add_time", $range);
        }
        ValidCashOrderServices::applyScope($q, 'a');
        if ($isXuka && !$isXmu) {
            ValidCashOrderServices::applyHasValidCash($q, 'a');
        }
        return $q;
    }

    /**
     * 将 1 个客按百分之一拆分：前 N-1 人均 floor(100/N)，最后一人拿剩余（与 StaffYejiDao 一致）。
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

    //点客数量
    public function dianke($range,$storeId,$dao,$isDian=1,$staffId=0){
        if(!empty($storeId)) {
            $where['store_id'] = $storeId;
        }
        if($isDian == 1) {
            $where['is_dian'] = 1;
        }
        if(!empty($staffId)){
            $where['staff_id']=$staffId;
        }
        $where['sum_type']=2;
        $where['created_time']=$range;
        $result=$dao->diankeCount($where);
        return $result;
    }

    //员工数量
    public function ygCount($range,$storeId,$dao){
        $where['store_id']=$storeId;
        $where['sum_type']=2;
        $where['created_time']=$range;
        $result=$dao->ygCount($where);
        return $result;
    }

    //总消耗
    public function consume($range,$storeId,$isCount=0){
        $countOrder=StoreOrder::where("order_type",2)
            ->where('refund_status',0)
            ->where("paid",1)
            ->where('is_system_del', 0)
            ->where("store_id",$storeId);
        if(!empty($range)){
            $countOrder=$countOrder->whereBetween("add_time",$range);
        }
        if($isCount == 1){
            $countOrder=$countOrder->select();
            $count=0;
            $countAttr=[];
            foreach ($countOrder as $nk=>$nv){
                $svcObj = trim((string) ($nv['service_object'] ?? ''));
                if ($nv['uid'] == 0 || $svcObj === '朋友') {
                     $count++;
                }else {
                    $key = date("Ymd", $nv['add_time']) . "_" . $nv['uid'];
                    if (!isset($countAttr[$key])) {
                        $countAttr[$key] = 1;
                        $count++;
                    }
                }
            }
            $countOrder=$count;
        }else{
            $countOrder=$countOrder->sum("pay_price");
            $countOrder=bcadd($countOrder,0);
        }
        return $countOrder;
    }

    //获取业绩
    public function getYeji($staffId,$storeId,$range,$where){
        $rangeType=$where['range_type'] ?? 0;
        $yejiType=$where['yeji_type'] ?? 0; //1现金 2手工 3余额支付
        $cateIds=$where['cate_ids'] ?? '';
        $time_type=$where['time_type'] ?? 0;
        $has_recharge=$where['has_recharge'] ?? 1;
        $sumType=1;
        $result=0;
        if($rangeType == 1){
            if(in_array($yejiType,[1,3,4])) {
                if (!isset($where['cate_ids']) || empty($where['cate_ids'])) {
                    //门店业绩
                    $result = $this->getStoreYeji($storeId,$range,$yejiType,$has_recharge,$time_type,$staffId);
                } else {
                    //门店对应产品分类的业绩
                    $result = $this->getStoerProductYeji($storeId, $range, $where['cate_ids'],$yejiType,$has_recharge,$time_type,$staffId);
                }
            }
            if($yejiType == 2){
                 //门店手工业绩--取的核销订单
//                 $result=$this->workYeji($storeId,0,$range,$cateIds,2,$yejiType,$time_type,$has_recharge,$staffId);
                   $result=$this->getStoreWorkYeji($storeId,$cateIds,$range,$time_type,$staffId);
            }
        }
        if($rangeType == 2){
            //个人业绩--个人产品、销售、劳动业绩
            if($yejiType == 2){
                $sumType=2; //手工业绩
            }
            $result=$this->workYeji($storeId,$staffId,$range,$cateIds,$sumType,$yejiType,$time_type,$has_recharge,0);
        }
        return $result;
    }

    //获得业绩 sumType 1销售业绩 2手工业绩
    public function workYeji($storeId,$staffId,$range,$cateIds,$sumType,$yejiType,$time_type=0,$has_recharge=1,$timeTypeStaffId=0){
        $where=[];
        if(!empty($storeId)){
            $where['store_id']=$storeId;
        }
        if(!empty($staffId)){
            $where['staff_id']=$staffId;
        }
        $where['cate_ids']=$cateIds;
        $where['created_time']=$range[0]."-".$range[1];
        if($sumType == 2){
            //手工
            $where['type']=3;
        }else{
            //销售-非充值
           $where['type']=2;
        }
        if($staffId > 0 && $timeTypeStaffId == 0){
            $timeTypeStaffId=$staffId;
        }
        $where['agent_time']=$this->getAgentTime($time_type,$timeTypeStaffId,$range);
        if($sumType == 2){
            $dao=app()->make(StaffYejiDao::class);
            $yeji=$dao->search($where)->sum("yeji");
        }else{
            if($yejiType == 1){
                //现金业绩
                $field="yeji";
            }else{
                //扣卡业绩
                $field="deduct_card_yeji";
            }
            $dao=app()->make(StaffYejiDao::class);
            $yeji=$dao->search($where)->sum($field);
        }
        if($has_recharge == 1){
            $where['type']=1;
            unset($where['cate_ids']);
            $dao=app()->make(StaffYejiDao::class);
            $yejiTwo=$dao->search($where)->sum("yeji");
            $yeji=bcadd($yeji,$yejiTwo,2);
        }
        return $yeji;
    }
    public function getAgentTime($time_type,$timeTypeStaffId,$range){
         $result=[];
        if($time_type == 2 && $timeTypeStaffId > 0){
            //指定代班时间
            $date=date('Y-m',strtotime($range[0]));
            $times=SalaryAgent::where("staff_id",$timeTypeStaffId)->whereLike("date","%".$date."%")->column("date");
            $timeAttr=[];
            foreach ($times as $kk=>$vv){
                $timeOne=explode(",",$vv);
                foreach ($timeOne as $vvv){
                    $timeAttr[]=trim($vvv);
                }
            }
            $result=array_filter($timeAttr);
            $result[]='0000-00-00';
        }
        return $result;
    }
    //获得门店耗卡业绩
    public function getStoreWorkYeji($storeId,$cateIds,$range,$time_type=1,$timeTypeStaffId=0){
        $order_where = [];
        $order_where['write_off_store_id']=$storeId;
        $order_where['date_range_time']=$range;
        $order_where['cate_ids']=$cateIds;
        $order_where['product_type']=[5,6];
        $order_where['agent_time']=$this->getAgentTime($time_type,$timeTypeStaffId,$range);
        $dao = app()->make(StoreOrderWriteoffDao::class);
        $orderYeji = $dao->search($order_where)->sum( 'writeoff_price');
        return $orderYeji;
    }
    //获得当天业绩不包含充值
    public function theDayYeji($order,$price){
        $yeji=0;
        if($order['uid'] == 0){
            return $price;
        }
        $date=date("Y-m-d",$order['add_time']);
        $range=[strtotime($date),strtotime($date." 23:59:59")];
        $rechargeCount=StoreOrder::where("order_type",1)
            ->where("uid",$order['uid'])
            ->whereBetween("add_time",$range)
            ->where("paid",1)
            ->where("is_system_del",0)
            ->where("refund_status",0)
            ->count();
        if($rechargeCount == 0){
            $yeji=$price;
        }
        return $yeji;
    }
    //获得门店业绩
    public function getStoreYeji($storeId,$range,$yejiType,$has_recharge=1,$time_type=1,$timeTypeStaffId=0){
        if($yejiType == 1){
            //现金业绩
            $field="cash_pay_price";
        }else{
            //耗卡业绩
            $field="yue_pay_price";
        }
        $order_where = ['paid' => 1,'not_old'=>1,'pid' =>-3, 'is_system_del' => 0, 'refund_status' =>0];
        $order_where['store_id']=$storeId;
        $order_where['date_range_time']=$range;
        $order_where['agent_time']=$this->getAgentTime($time_type,$timeTypeStaffId,$range);
        if($has_recharge == 1) {
            $order_where['link_type'] = [0, 1];
        }else{
            $order_where['link_type'] = [0];
        }
        $notYeji=0;
        if($yejiType == 1){
            //扣掉分成款员工
            $fencheng=SystemStoreStaff::where("is_fencheng",1)->column("id");
            $fenchengWhere=[];
            $fenchengWhere['store_id']=$storeId;
            $fenchengWhere['staff_id']=$fencheng;
            $fenchengWhere['created_time']=$range[0]."-".$range[1];
            if($has_recharge == 1) {
                $fenchengWhere['type'] = [1, 2];
            }else{
                $fenchengWhere['type'] = [2];
            }
            $fenchengWhere['agent_time']=$order_where['agent_time'];
            $dao=app()->make(StaffYejiDao::class);
            $notYeji=$dao->search($fenchengWhere)->sum("yeji");
        }
        $dao=app()->make(StoreOrderDao::class);
        if($yejiType == 4){
            //扣储值，不包含当天充值
            $list=$dao->search($order_where)->select();
            $orderYeji=0;
            foreach ($list as $k=>$v){
               $theDayYeji=$this->theDayYeji($v,$v[$field]);
               $orderYeji = bcadd($orderYeji, $theDayYeji, 4);
            }
        }else{
           if ($yejiType == 1) {
               $orderYeji = ValidCashOrderServices::sumStoreCashIncome($order_where);
           } else {
               $orderYeji = $dao->sum($order_where, $field, true);
           }
         }
        $orderYeji=bcsub($orderYeji,$notYeji,2);
         return $orderYeji;
    }
    //获得门店对应产品的业绩
    public function getStoerProductYeji($storeId,$range,$cateIds,$yejiType,$has_recharge,$time_type=1,$timeTypeStaffId=0){
        if($yejiType == 1){
            //现金业绩
            $field="cash_pay_price";
            $filedSub="cash_pay_amount";
        }else{
            //耗卡业绩
            $field="yue_pay_price";
            $filedSub="yue_pay_amount";
        }
        if(empty($cateIds)){
            return 0;
        }
        if($has_recharge == 1) {
            $orderTypes = [0, 1];
        }else{
            $orderTypes = [0];
        }
        $agent_time=$this->getAgentTime($time_type,$timeTypeStaffId,$range);
        $notYeji=0;
        if($yejiType == 1){
            //扣掉分成款员工
            $fencheng=SystemStoreStaff::where("is_fencheng",1)->column("id");
            $fenchengWhere=[];
            $fenchengWhere['store_id']=$storeId;
            $fenchengWhere['staff_id']=$fencheng;
            $fenchengWhere['created_time']=$range[0]."-".$range[1];
            if($has_recharge == 1) {
                $fenchengWhere['type'] = [1, 2];
            }else{
                $fenchengWhere['type'] = [2];
            }
            $fenchengWhere['cate_ids']=$cateIds;
            $fenchengWhere['agent_time']=$agent_time;
            $dao=app()->make(StaffYejiDao::class);
            $notYeji=$dao->search($fenchengWhere)->sum("yeji");
        }
        $orderYeji=StoreOrderCartInfo::alias("c")
             ->join('store_order b',"b.id=c.oid",'left')
             ->whereIn("c.product_id",function ($q) use ($cateIds) {
                 $q->name('store_product_relation')->where("type",1)
                     ->where(function ($q) use ($cateIds){
                         $q->whereIn("relation_id",$cateIds)->whereOr(function ($d) use ($cateIds){
                             $d->whereIn("relation_pid",$cateIds);
                         });
                     })->field(['product_id'])->select();
             })
            ->where(function ($query){
                $query->whereIn("b.pid",[0,-2])->whereOr("b.pid",">",0);
            })
            ->where("b.paid",1)
             ->where("b.is_system_del",0)
             ->whereIn("b.refund_status",0)
             ->whereIn("b.order_type",$orderTypes)
             ->when($yejiType == 1, function ($query) {
                 ValidCashOrderServices::applyScope($query, 'b');
             }, function ($query) {
                 $query->where("b.cash_choose","<>",9);
             })
             ->whereBetween("b.add_time",[strtotime($range[0]),strtotime($range[1])])
             ->where('b.store_id',$storeId)
            ->when(!empty($agent_time), function ($query) use ($agent_time) {
                $query->where(function ($query) use ($agent_time) {
                    $validDates = array_filter($agent_time, function($date) {
                        // 仅保留YYYY-MM-DD格式的有效日期
                        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date);
                    });
                    if(!empty($validDates)) {
                        $dateStr = "'" . implode("','",$validDates) . "'";
                        $query->whereRaw("FROM_UNIXTIME(b.add_time, '%Y-%m-%d') IN ({$dateStr})");
                    }
                });
            });
        if($yejiType == 4){
            //扣储值，不包含当天充值
            $list=$orderYeji->field("c.$filedSub,b.add_time,b.uid")->select();
            $orderYeji=0;
            foreach ($list as $k=>$v){
                $theDayYeji=$this->theDayYeji($v,$v[$filedSub]);
                $orderYeji = bcadd($orderYeji, $theDayYeji, 4);
            }
        }else{
            if ($yejiType == 1) {
                $amountExpr = ValidCashOrderServices::buildAmountExpr('b', 'c.' . $filedSub);
                $orderYeji = $orderYeji->sum(Db::raw($amountExpr));
            } else {
                $orderYeji = $orderYeji->sum("c.$filedSub");
            }
        }
        $orderYeji=bcsub($orderYeji,$notYeji,2);
        return $orderYeji;
    }

    //获取公式中的变量
    public function getKeys($formula){
        preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $formula, $matches);
        $formulaVariables = array_unique($matches[0]);
        return $formulaVariables;
    }
    /**
     * 检测替换变量后的表达式是否存在除零（字面量 0 或括号内为 0）
     */
    private function hasDivisionByZero(string $expression): bool
    {
        // /0、/0.0、/0.00 等，排除 /07、/0.5
        if (preg_match('/\/\s*(?:0+(?:\.0*)?|\.0+)(?!\d)/', $expression)) {
            return true;
        }
        // /(0)、/(0.0) 等
        return (bool)preg_match('/\/\s*\(\s*(?:0+(?:\.0*)?|\.0+)\s*\)/', $expression);
    }

    /**
     * 通用公式计算方法：支持括号运算，返回整数结果，异常返回0
     * @param array $data 键值对数组（键为任意变量名，值为数字）
     * @param string $formula 运算公式（支持括号、加减乘除）
     * @return int 计算结果（整数，异常时返回0）
     */
    public function calculateByFormula(array $data, string $formula): int
    {
        $formula = trim($formula);
        if ($formula === '') {
            return 0;
        }

        // 1. 提取公式中的所有变量名
        preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $formula, $matches);
        $formulaVariables = array_unique($matches[0]);

        // 2. 为每个变量赋值（缺失/非数字默认0）
        $variableValues = [];
        foreach ($formulaVariables as $var) {
            $variableValues[$var] = isset($data[$var]) && is_numeric($data[$var])
                ? (float)$data[$var]
                : 0.0;
        }

        // 3. 过滤危险字符（保留括号、运算符、变量名等）
        $allowedChars = '0123456789+-*/().' . implode('', $formulaVariables);
        $filteredFormula = preg_replace("/[^" . preg_quote($allowedChars, '/') . "]/", '', $formula);
        if ($filteredFormula === '') {
            return 0;
        }

        // 4. 替换变量名为实际数值（长变量名优先，避免 xiao 误替换 xiao_yeji）
        uksort($variableValues, static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        $formulaWithValues = str_replace(
            array_keys($variableValues),
            array_map(static function ($value) {
                return is_numeric($value) ? (string)(float)$value : '0';
            }, array_values($variableValues)),
            $filteredFormula
        );

        // 5. 除零或非法结果时返回 0
        if ($this->hasDivisionByZero($formulaWithValues)) {
            return 0;
        }

        try {
            $result = @eval('return (' . $formulaWithValues . ');');
            if ($result === false && error_get_last()) {
                return 0;
            }
            if (!is_numeric($result) || !is_finite((float)$result)) {
                return 0;
            }
            return (int)$result;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * 根据年月（如2026-03）获取当月所有日期号数的数组
     * @param string $yearMonth 年月字符串，格式必须为 Y-m（如2026-03）
     * @return array 当月号数数组（如[1,2,3,...,31]），参数错误返回空数组
     */
    public function getDaysOfMonth(string $yearMonth): array {
        // 1. 校验参数格式（正则匹配 YYYY-MM）
        $pattern = '/^\d{4}-\d{2}$/';
        if (!preg_match($pattern, $yearMonth)) {
            trigger_error("年月参数格式错误，需传入如2026-03的格式", E_USER_WARNING);
            return [];
        }

        // 2. 拆分年、月并转为整数
        list($year, $month) = explode('-', $yearMonth);
        $year = (int)$year;
        $month = (int)$month;

        // 3. 校验月份合法性（1-12）
        if ($month < 1 || $month > 12) {
            trigger_error("月份超出合法范围（1-12）", E_USER_WARNING);
            return [];
        }

        // 4. 核心：用DateTime计算当月最后一天，获取总天数（无扩展依赖）
        // 构造“当月最后一天”的日期（如2026-03-31）
        $lastDay = new \DateTime("{$year}-{$month}-01");
        $lastDay->modify('last day of this month');
        $totalDays = (int)$lastDay->format('d'); // 提取最后一天的号数（即总天数）

        // 5. 生成1~总天数的号数数组
        return range(1, $totalDays);
    }

    //员工薪资
    public function salaryOne($staffId,$date){
        $staffInfo=SalaryInfo::where("staff_id",$staffId)->where("date",$date)->find();
        if(empty($staffInfo)){
            return [];
        }
        $salary_info=json_decode($staffInfo['salary_info'],true);
        $result=[];
        $salaryField=SalaryField::where("is_show",1)->order("sort","asc")->whereFindInSet("table_ids",1)->select();
        $keys=['date'];
        foreach ($salaryField as $nk=>$nv){
            if(!in_array($nv['key'],$keys)) {
                $result[$nv['key']] = ['name' => $nv['name'], 'value' => $salary_info[$nv['key']] ?? 0];
            }
        }
        $nv=SystemStoreStaff::where("id",$staffId)->find();
        $result['zhiwu']['value']=Position::where("id",$nv['position'])->value("name");
        $result['zhiji']['value']=PositionLevel::where("id",$nv['position_level'])->value("name");
        $result['xingming']['value']=$nv['staff_name'];
        $result['store_name']['value']=SystemStore::where("id",$nv['store_id'])->value("name");
        $salary_info['detail']=$result;
        return $salary_info;
    }
}
