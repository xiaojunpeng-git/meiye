<?php

namespace app\services\salary;

use app\dao\product\product\StoreProductDao;
use app\dao\salary\SalaryStoreDao;
use app\dao\store\SystemStoreDao;
use app\dao\store\SystemStoreStaffDao;
use app\dao\yeji\StaffYejiDao;
use app\model\position\Position;
use app\model\position\PositionLevel;
use app\model\position\PositionYeji;
use app\model\product\category\StoreProductCategory;
use app\model\product\product\StoreProduct;
use app\model\product\product\StoreProductRelation;
use app\model\salary\SalaryField;
use app\model\salary\SalarySearchField;
use app\model\store\SystemStore;
use app\model\store\SystemStoreStaff;
use app\model\user\User;
use app\model\yeji\CashSource;
use app\services\BaseServices;
use app\services\report\ReportProductServices;
use app\services\report\ReportServices;
use app\services\yeji\YejiPkServices;
use think\exception\ValidateException;
use think\facade\Db;


class SalaryTableServices extends BaseServices
{
    public function __construct(SystemStoreStaffDao $dao)
    {
        $this->dao = $dao;
    }

    //产品数据报表
    public function productCount(array $where,$allData)
    {
        $mainId = 8154; //定制卡id
        $notIds = StoreProduct::where("pid", $mainId)->column("id");
        $notIds[] = $mainId;
        $storeId = $where['store_id'];
        $where['not_ids']=$notIds;
        unset($where['store_id']);
        $where['pid'] = 0;
        $dao = app()->make(StoreProductDao::class);
        $list = $dao->getAllList($where);
        $count = count($list);
        $table_ids = $where['table_ids'];
        $date = explode("-", $where['date']);
        $begin = $date[0];
        $end = $date[1];
        $reportService = app()->make(ReportProductServices::class);
        $yejiDao = app()->make(StaffYejiDao::class);
        $data = SalaryField::where("is_show", 1)
            ->where("is_count", 1)
            ->whereFindInSet("table_ids", $where['table_ids'])
            ->select();
        $beginKeys = array_column($data->toArray(), "key");
        if (empty($beginKeys)) {
            return [];
        }
        $total = [];
        $keys = $beginKeys;
        foreach ($list as $nk => $item) {
            $salaryInfo = $this->getProductReport($item, $begin, $end, $keys,$reportService,$yejiDao,$table_ids,$storeId);
            foreach ($data as $k => $v) {
                if (in_array($v['key'], $beginKeys)) {
                    if (!isset($total[$v['key']])) {
                        $total[$v['key']]['count'] = $salaryInfo[$v['key']] ?? '';
                        $total[$v['key']]['name'] = $v['name'];
                    } else {
                        $total[$v['key']]['count'] = bcadd($salaryInfo[$v['key']], $total[$v['key']]['count']);
                    }
                }
            }
        }
        return array_values($total);
    }
    //产品数据报表
    public function productList(array $where,$allData)
    {
        $mainId = 8154; //定制卡id
        $notIds = StoreProduct::where("pid", $mainId)->column("id");
        $notIds[] = $mainId;
        $storeId = $where['store_id'];
        $where['not_ids']=$notIds;
        unset($where['store_id']);
        $where['pid'] = 0;
        $dao = app()->make(StoreProductDao::class);
        $list = $dao->getAllList($where);
        $count = count($list);
        $table_ids = $where['table_ids'];
        $date = explode("-", $where['date']);
        $begin = $date[0];
        $end = $date[1];
        $reportService = app()->make(ReportProductServices::class);
        $keys = SalaryField::whereFindInSet("table_ids", $table_ids)->column("key");
        $yejiDao = app()->make(StaffYejiDao::class);
        $sortField='';
        $sortType='';
        foreach ($allData as $k=>$v){
            if(in_array($k,$keys) && !empty($v)){
                $sortField=$k;
                $sortType=$v;
            }
        }
        foreach ($list as $nk => $item) {
            $info = $this->getProductReport($item, $begin, $end, $keys,$reportService,$yejiDao,$table_ids,$storeId);
            $list[$nk] = array_merge($info, $item);
        }
        [$page, $limit] = $this->getPageValue();
        if(!empty($sortType) && !empty($sortField)) {
            $list = $this->sortArrayByKey($list, $sortField, $sortType);
        }
        $list=$this->arrayPaginate($list,$page,$limit);
        return compact('count', 'list');
    }
    /**
     * 按指定key和方向对二维数组排序
     * @param array $array 待排序的二维数组（核心：需排序的数组）
     * @param string $sortKey 排序依据的key（如"collect"）
     * @param string $sortDirection 排序方向：asc（升序）/desc（降序），默认asc
     * @return array 排序后的数组
     */
    function sortArrayByKey(array $array, string $sortKey, string $sortDirection = 'asc'): array
    {
        // 1. 参数合法性校验
        // 校验排序方向
        $sortDirection = strtolower($sortDirection);
        if (!in_array($sortDirection, ['asc', 'desc'])) {
            return $array;
        }
        // 校验数组为空的情况
        if (empty($array)) {
            return $array;
        }
        // 校验排序key是否存在（取第一个元素验证，避免无意义排序）
        $firstItem = reset($array);
        if (!is_array($firstItem) || !array_key_exists($sortKey, $firstItem)) {
            return $array;
        }

        // 2. 核心排序逻辑（使用usort实现自定义排序）
        usort($array, function ($a, $b) use ($sortKey, $sortDirection) {
            $valueA = $a[$sortKey];
            $valueB = $b[$sortKey];

            // 区分数字/字符串类型排序（保证排序准确性）
            if (is_numeric($valueA) && is_numeric($valueB)) {
                // 数字类型：数值比较
                $compareResult = $valueA <=> $valueB;
            } else {
                // 字符串类型：自然排序（支持中文/字母）
                $compareResult = strnatcmp((string)$valueA, (string)$valueB);
            }

            // 根据方向调整排序结果
            return $sortDirection === 'desc' ? -$compareResult : $compareResult;
        });

        return $array;
    }

    /**
     * 数组分页方法：页码超限时list返回空数组
     * @param array $array 待分页的数组
     * @param int $page 当前页码（默认1）
     * @param int $pageSize 每页条数（默认10）
     * @return array 分页结果（包含list、total、totalPage、currentPage、pageSize）
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
    public function getProductReport($item, $begin, $end, $keys,$reportService,$yejiDao,$table_ids,$storeId){
        $salary_info = [];
        $productIds=StoreProduct::where("pid",$item['id'])->column("id");
        $productIds[]=$item['id'];
        $productTypes=[0=>'产品',5=>'卡项',6=>'项目'];
        if(in_array("pinxiangleixing", $keys)) {
            $salary_info['pinxiangleixing'] = $productTypes[$item['product_type']] ?? '';
        }
        if(in_array("mingcheng", $keys)) {
            $salary_info['mingcheng'] = $item['store_name'];
        }
        if(in_array("pinxiangfenlei", $keys)) {
            $salary_info['pinxiangfenlei'] = StoreProductRelation::where("product_id",$item['id'])->column("relation_id");
            $salary_info['pinxiangfenlei'] = StoreProductCategory::whereIn("id",$salary_info['pinxiangfenlei'])->column("cate_name");
            if(empty($salary_info['pinxiangfenlei'])){
                $salary_info['pinxiangfenlei']='';
            }else {
                $salary_info['pinxiangfenlei'] = implode(",", $salary_info['pinxiangfenlei']);
            }
        }
        if(in_array("xiaoshouyeji", $keys)) {
            //销售业绩
            $salary_info['xiaoshouyeji'] = $reportService->rangeYeji([$begin,$end],$storeId,$productIds);
            $salary_info['xiaoshouyeji']=intval($salary_info['xiaoshouyeji']);
        }
        if(in_array("xiaoshoushuliang", $keys)) {
            //销售数量
            $salary_info['xiaoshoushuliang'] = $reportService->rangeCartInfo([$begin,$end],$storeId,$productIds,'c.cart_num');
        }
        if(in_array("xiaoshourenshu", $keys)) {
            //销售人数
            $salary_info['xiaoshourenshu'] = $reportService->rangeUserCount([$begin,$end],$storeId,$productIds);
        }
        if(in_array("xiaohaoyeji", $keys)) {
            //消耗业绩
            $salary_info['xiaohaoyeji'] = $reportService->rangeXiaohao([$begin,$end],$storeId,$productIds,'writeoff_price');
            $salary_info['xiaohaoyeji']=intval($salary_info['xiaohaoyeji']);
        }
        if(in_array("xiaohaoshuliang", $keys)) {
            //消耗数量
            $salary_info['xiaohaoshuliang'] = $reportService->rangeXiaohao([$begin,$end],$storeId,$productIds,'writeoff_num');
        }
        if(in_array("weihexiaoshuliang", $keys)) {
            //未核销数量
            $salary_info['weihexiaoshuliang'] = $reportService->rangeCartInfo([$begin,$end],$storeId,$productIds,'c.write_surplus_times');
        }
        if(in_array("weihexiaoyeji", $keys)) {
            //未核销数量
            $salary_info['weihexiaoyeji'] = $reportService->rangeCartInfoMoney([$begin,$end],$storeId,$productIds);
            $salary_info['weihexiaoyeji']=intval($salary_info['weihexiaoyeji']);
        }
        //计算公式了
        $list = SalaryField::where("type", 2)->whereFindInSet("table_ids", $table_ids)->select();
        foreach ($list as $k => $v) {
            $salary_info[$v['key']] = $reportService->calculateByFormula($salary_info, $v['info']);
        }
        return $salary_info;
    }
    //获取门店的自定义合计
    public function storeSelf($where,$key){
        $dao = app()->make(SalaryStoreDao::class);
        $data = $dao->moneyCount($where);
        $result=0;
        foreach ($data as $k=>$nv){
            $info=json_decode($nv['salary_info'],true);
            if($info['shuadanpingtai'] == $key){
                 $result=bcadd($result,$info['shuadanjine'],4);
            }
        }
        return intval($result);
    }
    //自定义表单列表
    public function selfList($where,$isCount=0){
        [$page, $limit] = $this->getPageValue();
        $where['salary_status'] = 1;
        $dao = app()->make(SalaryStoreDao::class);
        if($isCount == 0){
            $list = $dao->getList($where,$page, $limit,"date desc");
            $count = $dao->count($where);
        }else{
            $list = $dao->moneyCount($where);
            $list=$list->toArray();
        }
        $table_ids = $where['table_ids'];
        $data=SalaryField::order("sort","asc")->whereFindInSet("table_ids",$table_ids)->select();
        foreach ($list as $nk=>$item) {
            $salaryInfo=json_decode($item['salary_info'],true);
            $staffInfo=SystemStoreStaff::where("id",$item['staff_id'])->find();
            foreach ($data as $k=>$v){
                $list[$nk][$v['key']]=$salaryInfo[$v['key']] ?? '';
                if(strstr($v['name'],"提点")){
                    $list[$nk][$v['key']]=bcmul($list[$nk][$v['key']],100)."%";
                }
                switch ($v['key']){
                    case 'store_name':
                        $list[$nk][$v['key']]=SystemStore::where("id",$item['store_id'])->value("name");
                        break;
                    case 'xingming':
                        $list[$nk][$v['key']]=$staffInfo['staff_name'];
                        break;
                    case 'nicheng':
                        $list[$nk][$v['key']]=User::where("uid",$staffInfo['uid'])->value("nickname");
                        break;
                    case 'zhiwu':
                        $list[$nk][$v['key']]=Position::where("id",$staffInfo['position'])->value("name");
                        break;
                    case 'zhiji':
                        $list[$nk][$v['key']]=PositionLevel::where("id",$staffInfo['position_level'])->value("name");
                        break;
                }
            }
            $list[$nk]['date']=date("Y-m-d",$item['date']);
        }
        if($isCount == 0){
            return compact('count', 'list');
        }else{
            $keys = SalaryField::where("is_show", 1)
                ->where("is_count", 1)
                ->whereFindInSet("table_ids", $where['table_ids'])
                ->column("key");
             $total=[];
            foreach ($list as $nk => $item) {
                foreach ($data as $k => $v) {
                    if (in_array($v['key'], $keys)) {
                        if (!isset($total[$v['key']])) {
                            $total[$v['key']]['count'] = $item[$v['key']] ?? '';
                            $total[$v['key']]['name'] = $v['name'];
                        } else {
                            $total[$v['key']]['count'] = bcadd($item[$v['key']], $total[$v['key']]['count']);
                        }
                    }
                }
            }
            return $total;
        }
    }
    //门店数据报表
    public function storeList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $where['salary_status'] = 1;
        $dao = app()->make(SystemStoreDao::class);
        $list = $dao->getList($where, ["*"], $page, $limit);
        $count = $dao->count($where);
        $table_ids = $where['table_ids'];
        $date = explode("-", $where['date']);
        $begin = $date[0];
        $end = $date[1];
        $keys = SalaryField::whereFindInSet("table_ids", $table_ids)->column("key");
        $fencheng = SystemStoreStaff::where("is_fencheng", 1)->column("id");
        $service = app()->make(YejiPkServices::class);
        $reportService = app()->make(ReportServices::class);
        $sourceAttr = CashSource::whereNotIn("id", [6, 7,11,12])->column("id");
        $productIds = StoreProductRelation::where("relation_id", 78)->where("type", 1)->column("product_id");
        $yejiDao = app()->make(StaffYejiDao::class);
        foreach ($list as $nk => $item) {
            $info = $this->getStoreReport($item, $begin, $end, $keys, $fencheng, $service, $reportService, $sourceAttr, $productIds, $yejiDao, $table_ids);
            $list[$nk] = array_merge($info, $item);
        }
        return compact('count', 'list');
    }
    public function getStoreReport($item, $begin, $end, $keys, $fencheng, $service, $reportService, $sourceAttr, $productIds, $yejiDao, $table_ids)
    {
        $salary_info = [];
        if (in_array("xianjinyeji", $keys)) {
            $salary_info['xianjinyeji'] = $service->monthYeji([$begin, $end], $item['id']);
        }
        if (in_array("store_name", $keys)) {
            $salary_info['store_name'] = $item['name'];
        }
        if (in_array("fenchengkuan", $keys)) {
            $salary_info['fenchengkuan'] = $service->monthYejiFencheng([$begin, $end], $item['id'], $fencheng);
        }
        if (in_array("xiaohaoyeji", $keys)) {
            $salary_info['xiaohaoyeji'] = $reportService->consume([strtotime($begin), strtotime($end)], $item['id'], 0);
        }
        if (in_array("fuwukeci", $keys)) {
            $salary_info['fuwukeci'] = $reportService->consume([strtotime($begin), strtotime($end)], $item['id'], 1);
        }
        if (in_array("xinzenghuiyuanshu", $keys)) {
            $salary_info['xinzenghuiyuanshu'] = $reportService->registerNum([strtotime($begin), strtotime($end)], $item['id'], 1);
        }
        if (in_array("zhidingke", $keys)) {
            $salary_info['zhidingke'] = $reportService->dianke($begin . "-" . $end, $item['id'], $yejiDao);
        }
        if (in_array("xinkeshu", $keys)) {
            $salary_info['xinkeshu'] = $reportService->sourceOrder($sourceAttr, 1, [strtotime($begin), strtotime($end)], 1, $item['id'], $productIds);
        }
        if (in_array("huiyuanxvkayeji", $keys)) {
            $salary_info['huiyuanxvkayeji'] = $reportService->sourceOrder(6,0, [strtotime($begin), strtotime($end)],0, $item['id'], $productIds,true);
        }
        if (in_array("xinkezongkeshu", $keys)) {
            $salary_info['xinkezongkeshu'] = $reportService->sourceOrder($sourceAttr,0, [strtotime($begin), strtotime($end)], 1, $item['id'], $productIds);
            $xinkeshu=$salary_info['xinkeshu'] ?? 0;
            $salary_info['xinkezongkeshu']=bcadd($salary_info['xinkezongkeshu'],$xinkeshu);
        }
        if (in_array("chuzhiduizhangjiner", $keys)) {
            $sub = $reportService->kuadian([strtotime($begin), strtotime($end)],0,1,$item['id']);
            $add = $reportService->kuadian([strtotime($begin), strtotime($end)],$item['id'],1,0);
            $salary_info['chuzhiduizhangjiner']=bcsub($add,$sub);
        }
        if (in_array("cikazhiduizhangjiner", $keys)) {
            $sub = $reportService->kuadian([strtotime($begin), strtotime($end)],0,2,$item['id']);
            $add = $reportService->kuadian([strtotime($begin), strtotime($end)],$item['id'],2,0);
            $salary_info['cikazhiduizhangjiner']=bcsub($add,$sub);
        }
        if (in_array("xiangmushu", $keys) || in_array("xiaohaoxiangmushu", $keys)) {
            /** @var ReportProductServices $reportProductService */
            $reportProductService = app()->make(ReportProductServices::class);
            if (in_array("xiangmushu", $keys)) {
                $salary_info['xiangmushu'] = $reportProductService->sumStoreSalesQuantity([$begin, $end], (int)$item['id']);
            }
            if (in_array("xiaohaoxiangmushu", $keys)) {
                $salary_info['xiaohaoxiangmushu'] = $reportProductService->sumStoreWriteoffNum([$begin, $end], (int)$item['id']);
            }
        }
        //计算公式了
        $list = SalaryField::where("type", 2)->whereFindInSet("table_ids", $table_ids)->select();
        foreach ($list as $k => $v) {
            $salary_info[$v['key']] = $reportService->calculateByFormula($salary_info, $v['info']);
        }
        return $salary_info;
    }

    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $service = app()->make(ReportServices::class);
        $yejiDao = app()->make(StaffYejiDao::class);
        $list = $this->dao->getStoreStaffList($where, "*", $page, $limit, [], "position");
        $count = $this->dao->count($where);
        $table_ids = $where['table_ids'];
        $date = explode("-", $where['date']);
        $begin = $date[0];
        $end = $date[1];
        $productIds = StoreProductRelation::where("relation_id", 78)->where("type", 1)->column("product_id");
        $sourceAttr = CashSource::whereNotIn("id", [6, 7,11,12])->column("id");
        $keys = SalaryField::whereFindInSet("table_ids", $table_ids)->column("key");
        foreach ($list as $nk => $item) {
            $info = $this->getReport($item, $begin, $end, $service, $yejiDao, $productIds, $sourceAttr, $table_ids, $keys);
            $list[$nk] = array_merge($info, $item);
        }
        return compact('count', 'list');
    }

    public function getReport($nv, $begin, $end, $service, $dao, $productIds, $sourceAttr, $table_ids, $keys)
    {
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
        $mendianshijikoukaticheng = 0;//门店实际扣卡余额销售提成
        $mendianshijikoukayeji = 0;//门店余额实际扣卡销售提成
        $storeWorkYeji = 0;//门店手工销售提成
        foreach ($yeji as $k => $v) {
            if ($v['position_level_id'] > 0 && $v['position_level_id'] != $nv['position_level']) {
                continue;
            }
            $where = [];
            $isProduct = false;
            if ($v['range_type'] == 1) {
                //门店
                if ($v['type'] == 1 && (!in_array("mendianxianjinyeji", $keys) && !in_array("mendianxianjinticheng", $keys))) {
                    //门店销售
                    continue;
                }
                if ($v['type'] == 2 && (!in_array("mendianlaodongyeji", $keys) && !in_array("mendianlaodongticheng", $keys))) {
                    //门店劳动
                    continue;
                }
                if ($v['type'] == 3 && (!in_array("mendiankoukayeji", $keys) && !in_array("mendiankoukaticheng", $keys))) {
                    //门店扣储值
                    continue;
                }
            } else {
                //个人
                if (in_array($v['type'], [1, 3])) {
                    //销售
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
                    if ($isProduct && (!in_array("product_yeji", $keys) && !in_array("product_money", $keys))) {
                        continue;
                    }
                    if (!$isProduct && (!in_array("xiao_yeji", $keys) && !in_array("xiao_money", $keys))) {
                        continue;
                    }
                }
                if ($v['type'] == 2) {
                    //手工
                    if (!in_array("work_yeji", $keys) && !in_array("work_get", $keys)) {
                        continue;
                    }
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
                if ($where['yeji_type'] == 2) {
                    //手工的就取 销售业绩 用来判断范围提点
                    $where['yeji_type'] = 1;
                    $xiaoshouYeji = $service->getYeji($nv['id'], $nv['store_id'], [$begin, $end], $where);
                } else {
                    $xiaoshouYeji = $orderYeji;
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
            if ($orderYeji > 0 && $commission > 0) {
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
            }
        }
        $salary_info = [];
        $salary_info['xiao_yeji'] = intval($xiao_yeji);
        $salary_info['xiao_commission'] = $xiao_yeji_dian;
        $salary_info['xiao_money'] = intval($xiao_yeji_get);
        $salary_info['product_yeji'] = intval($product_yeji);
        $salary_info['product_commission'] = $product_dian;
        $salary_info['product_money'] = intval($product_get);
        $salary_info['work_yeji'] = intval($work_yeji);
        $salary_info['work_commission'] = $work_dian;
        $salary_info['work_get'] = intval($work_get);
        if (in_array("zhidingke", $keys)) {
            $salary_info['zhidingke'] = $service->dianke($begin . "-" . $end, 0, $dao, 1, $nv['id']);
        }
        if (in_array("keci", $keys)) {
            $salary_info['keci'] = $service->dianke($begin . "-" . $end, 0, $dao, 0, $nv['id']);
        }
        // 劳动项目数：link_id+goods_id 为一项，N 人平分（与工资表 MakeSalary、StaffYejiDao::projectNumFractional 一致）
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
            $salary_info['mendianshijikoukayeji'] = intval($storeHomeMetrics['store_use_yue']);
            $salary_info['mendianlaodongyeji'] = intval($storeHomeMetrics['store_writeoff_order_price']);
        } else {
            $salary_info['mendianshijikoukayeji'] = 0;
            $salary_info['mendianlaodongyeji'] = 0;
        }
        $salary_info['mendianshijikoukaticheng'] = intval($mendianshijikoukaticheng);
        if (in_array("xinkeshu", $keys)) {
            $salary_info['xinkeshu'] = $service->sourceStaffOrder($sourceAttr, 1, [strtotime($begin), strtotime($end)], 1, $nv['store_id'], $productIds, false, false, $nv['id']);
        }
        if (in_array("xinkezongkeshu", $keys)) {
            $salary_info['xinkezongkeshu'] = $service->sourceStaffOrder($sourceAttr, 0, [strtotime($begin), strtotime($end)], 1, $nv['store_id'], $productIds, false, false, $nv['id']);
            $xinkeshu=$salary_info['xinkeshu'] ?? 0;
            $salary_info['xinkezongkeshu']=bcadd($salary_info['xinkezongkeshu'],$xinkeshu,2);
        }
        $salary_info['store_name'] = SystemStore::where("id", $nv['store_id'])->value("name");
        $salary_info['nicheng'] = User::where("uid", $nv['uid'])->value("nickname");
        $salary_info['zhiwu'] = Position::where("id", $nv['position'])->value("name");
        $salary_info['zhiji'] = PositionLevel::where("id", $nv['position_level'])->value("name");
        $salary_info['xingming'] = $nv['staff_name'];
        //计算公式了
        $list = SalaryField::where("type", 2)->whereFindInSet("table_ids", $table_ids)->select();
        foreach ($list as $k => $v) {
            $salary_info[$v['key']] = $service->calculateByFormula($salary_info, $v['info']);
        }
        return $salary_info;
    }

    //合计--员工
    public function moneyCount(array $where)
    {
        $list = $this->dao->getSelectList($where, "*");
        $data = SalaryField::where("is_show", 1)
            ->where("is_count", 1)
            ->whereFindInSet("table_ids", $where['table_ids'])
            ->select();
        $beginKeys = array_column($data->toArray(), "key");
        if (empty($beginKeys)) {
            return [];
        }
        $keys = $beginKeys;
        $table_ids = $where['table_ids'];
        $date = explode("-", $where['date']);
        $begin = $date[0];
        $end = $date[1];
        $productIds = StoreProductRelation::where("relation_id", 78)->where("type", 1)->column("product_id");
        $sourceAttr = CashSource::whereNotIn("id", [6, 7,11,12])->column("id");
        $total = [];
        $service = app()->make(ReportServices::class);
        $yejiDao = app()->make(StaffYejiDao::class);
        foreach ($data as $kk => $vv) {
            if ($vv['type'] == 2 && !empty($vv['info'])) {
                $keys = array_merge($keys, $service->getKeys($vv['info']));
            }
        }
        foreach ($list as $nk => $item) {
            $salaryInfo = $this->getReport($item, $begin, $end, $service, $yejiDao, $productIds, $sourceAttr, $table_ids, $keys);
            foreach ($data as $k => $v) {
                if (in_array($v['key'], $beginKeys)) {
                    if (!isset($total[$v['key']])) {
                        $total[$v['key']]['count'] = $salaryInfo[$v['key']] ?? '';
                        $total[$v['key']]['name'] = $v['name'];
                    } else {
                        $total[$v['key']]['count'] = bcadd($salaryInfo[$v['key']], $total[$v['key']]['count']);
                    }
                }
            }
        }
        return array_values($total);
    }

    //合计--门店
    public function moneyStoreCount(array $where)
    {
        $where['salary_status'] = 1;
        $data = SalaryField::where("is_show", 1)
            ->where("is_count", 1)
            ->whereFindInSet("table_ids", $where['table_ids'])
            ->select();
        $dao = app()->make(SystemStoreDao::class);
        $list = $dao->getStore($where);
        $table_ids = $where['table_ids'];
        $date = explode("-", $where['date']);
        $begin = $date[0];
        $end = $date[1];
        $beginKeys = array_column($data->toArray(), "key");
        if (empty($beginKeys)) {
            return [];
        }
        $keys = $beginKeys;
        $fencheng = SystemStoreStaff::where("is_fencheng", 1)->column("id");
        $service = app()->make(YejiPkServices::class);
        $reportService = app()->make(ReportServices::class);
        foreach ($data as $kk => $vv) {
            if ($vv['type'] == 2 && !empty($vv['info'])) {
                $keys = array_merge($keys, $reportService->getKeys($vv['info']));
            }
        }
        $sourceAttr = CashSource::whereNotIn("id", [6, 7,11,12])->column("id");
        $productIds = StoreProductRelation::where("relation_id", 78)->where("type", 1)->column("product_id");
        $yejiDao = app()->make(StaffYejiDao::class);
        $total = [];
        foreach ($list as $nk => $item) {
            $salaryInfo = $this->getStoreReport($item, $begin, $end, $keys, $fencheng, $service, $reportService, $sourceAttr, $productIds, $yejiDao, $table_ids);
            foreach ($data as $k => $v) {
                if (in_array($v['key'], $beginKeys)) {
                    if (!isset($total[$v['key']])) {
                        $total[$v['key']]['count'] = $salaryInfo[$v['key']] ?? '';
                        $total[$v['key']]['name'] = $v['name'];
                    } else {
                        $total[$v['key']]['count'] = bcadd($salaryInfo[$v['key']], $total[$v['key']]['count']);
                    }
                }
            }
        }
        return array_values($total);
    }

    /**
     * 固定搜索项 key（门店、时间，始终展示，不可被自定义项覆盖）
     */
    public function getReservedReportSearchKeys(bool $isStore = false): array
    {
        return $isStore ? ['date'] : ['store_id', 'date'];
    }

    /**
     * 报表搜索项：固定搜索 + 自定义搜索项（门店端固定仅时间，门店ID由登录上下文注入）
     */
    public function getReportSearchFields(string $tableIds, bool $isStore = false): array
    {
        $reserved = $this->getReservedReportSearchKeys($isStore);
        $fields = $isStore ? $this->getDefaultStoreReportSearchFields() : $this->getDefaultReportSearchFields();
        $usedKeys = array_column($fields, 'key');
        $customCount = 0;

        if ($tableIds !== '') {
            try {
                $list = SalarySearchField::where('is_show', 1)
                    ->whereFindInSet('table_ids', $tableIds)
                    ->order('sort', 'asc')
                    ->select();
                foreach ($list ?: [] as $row) {
                    $item = is_array($row) ? $row : $row->toArray();
                    $key = (string)($item['key'] ?? '');
                    if ($key === '' || in_array($key, $reserved, true) || in_array($key, $usedKeys, true)) {
                        continue;
                    }
                    $item['input_info'] = !empty($item['info']) ? explode(',', (string)$item['info']) : [];
                    $fields[] = $item;
                    $usedKeys[] = $key;
                    $customCount++;
                }
            } catch (\Throwable $e) {
            }
        }

        return [
            'use_default' => $customCount === 0,
            'has_custom' => $customCount > 0,
            'fields' => $fields,
        ];
    }

    /**
     * 默认搜索栏：门店、时间（管理端）
     */
    public function getDefaultReportSearchFields(): array
    {
        return [
            [
                'id' => 0,
                'key' => 'store_id',
                'name' => '选择门店',
                'input_type' => 5,
                'info' => '',
                'input_info' => [],
                'sort' => 1,
                'is_show' => 1,
                'table_ids' => '',
            ],
            [
                'id' => 0,
                'key' => 'date',
                'name' => '时间选择',
                'input_type' => 6,
                'info' => '',
                'input_info' => [],
                'sort' => 2,
                'is_show' => 1,
                'table_ids' => '',
            ],
        ];
    }

    /**
     * 门店端默认搜索栏：仅时间（store_id 由后端自动带入）
     */
    public function getDefaultStoreReportSearchFields(): array
    {
        return [
            [
                'id' => 0,
                'key' => 'date',
                'name' => '时间选择',
                'input_type' => 6,
                'info' => '',
                'input_info' => [],
                'sort' => 1,
                'is_show' => 1,
                'table_ids' => '',
            ],
        ];
    }

    /**
     * SQL 自定义报表（salary_field.type=5 的 info 存 SQL，列字段 type!=5 定义展示列）
     */
    public function sqlReportList(array $where, array $allData = [], int $isCount = 0)
    {
        $params = $this->mergeSqlReportParams($where, $allData);
        $tableIds = (string)($params['table_ids'] ?? '');
        $list = $this->fetchSqlReportList($params);
        if ($isCount === 1) {
            return $this->sqlReportCount($list, $tableIds);
        }
        [$page, $limit] = $this->getPageValue();
        $count = count($list);
        $list = $this->arrayPaginate($list, $page, $limit);
        return compact('count', 'list');
    }

    /**
     * 合并请求参数（含自定义搜索项）供 SQL 占位符替换。
     * date 以非空值为准（getMore 可能带空 date 覆盖 request->param()）；
     * 解析为 {{date_start}}/{{date_end}} 在 buildSqlReportVariables 中完成。
     */
    public function mergeSqlReportParams(array $where, array $allData = []): array
    {
        $allData = is_array($allData) ? $allData : [];
        $merged = array_merge($allData, $where);
        $requestDate = trim((string)($allData['date'] ?? ''));
        $whereDate = trim((string)($where['date'] ?? ''));
        if ($whereDate !== '') {
            $merged['date'] = $whereDate;
        } elseif ($requestDate !== '') {
            $merged['date'] = $requestDate;
        } else {
            $merged['date'] = '';
        }
        return $merged;
    }

    /**
     * 执行 SQL 报表查询并格式化行数据
     */
    protected function fetchSqlReportList(array $params): array
    {
        $tableIds = (string)($params['table_ids'] ?? '');
        $sql = $this->getSqlReportSql($tableIds);
        $vars = $this->buildSqlReportVariables($params);
        $sql = $this->replaceSqlReportPlaceholders($sql, $vars);
        $this->validateSqlReportQuery($sql);
        try {
            $rows = Db::query($sql);
        } catch (\Throwable $e) {
            throw new ValidateException('SQL 执行失败：' . $e->getMessage());
        }
        if (!is_array($rows)) {
            $rows = [];
        }
        $columnFields = SalaryField::order('sort', 'asc')
            ->where('is_show', 1)
            ->whereFindInSet('table_ids', $tableIds)
            ->where('type', '<>', 5)
            ->select();
        return $this->formatSqlReportRows($rows, $columnFields);
    }

    /**
     * SQL 报表导出（无列配置时从 SQL/结果集取表头，支持分页累加导出）
     */
    public function sqlReportExcelExport(array $where, array $allData, string $filename): array
    {
        $params = $this->mergeSqlReportParams($where, $allData);
        $tableIds = (string)($params['table_ids'] ?? '');
        $list = $this->fetchSqlReportList($params);
        [$header, $filekey] = $this->buildSqlReportExportColumns($tableIds, $list, $params);
        [$page, $limit] = $this->getPageValue();
        $pageList = $this->arrayPaginate($list, $page, $limit);
        $export = [];
        foreach ($pageList as $row) {
            $exportOne = [];
            foreach ($filekey as $key) {
                $exportOne[$key] = $row[$key] ?? '';
            }
            $export[] = $exportOne;
        }
        return compact('header', 'filekey', 'export', 'filename');
    }

    /**
     * 导出表头与字段 key（优先字段配置，否则 SQL 列名）
     */
    public function buildSqlReportExportColumns(string $tableIds, array $list, array $params = []): array
    {
        $configured = SalaryField::order('sort', 'asc')
            ->where('is_show', 1)
            ->where('type', '<>', 5)
            ->whereFindInSet('table_ids', $tableIds)
            ->select();
        if ($configured && count($configured) > 0) {
            $header = [];
            $filekey = [];
            foreach ($configured as $field) {
                $header[] = $field['name'];
                $filekey[] = $field['key'];
            }
            return [$header, $filekey];
        }
        $keys = [];
        if ($this->hasSqlReportConfig($tableIds)) {
            try {
                $keys = $this->getSqlReportColumnKeys($tableIds, $params);
            } catch (\Throwable $e) {
            }
        }
        if (empty($keys) && !empty($list[0]) && is_array($list[0])) {
            $keys = array_keys($list[0]);
        }
        return [$keys, $keys];
    }

    /**
     * 是否已配置 SQL 报表（type=5 且 info 非空）
     */
    public function hasSqlReportConfig(string $tableIds): bool
    {
        if ($tableIds === '') {
            return false;
        }
        $field = SalaryField::whereFindInSet('table_ids', $tableIds)
            ->where('type', 5)
            ->order('sort', 'asc')
            ->find();
        return $field && trim((string)($field['info'] ?? '')) !== '';
    }

    /**
     * SQL 报表表头（优先 salary_field 列配置，缺省或多余列从 SQL 结果集解析）
     */
    public function sqlReportSelfColumn(string $tableIds, array $where = [], array $allData = [], bool $isStore = false): array
    {
        $configured = SalaryField::order('sort', 'asc')
            ->where('is_show', 1)
            ->where('type', '<>', 5)
            ->whereFindInSet('table_ids', $tableIds)
            ->select();
        $configuredList = $configured ? $configured->toArray() : [];
        $usedKeys = [];
        $leftFixedKeys = $isStore
            ? ['store_name', 'zhiwu', 'xingming', 'zhiji']
            : ['date', 'store_name', 'zhiwu', 'xingming', 'zhiji'];

        $sqlKeys = [];
        if ($this->hasSqlReportConfig($tableIds)) {
            try {
                $sqlKeys = $this->getSqlReportColumnKeys($tableIds, $this->mergeSqlReportParams($where, $allData));
            } catch (\Throwable $e) {
                if (empty($configuredList)) {
                    throw $e;
                }
            }
        }

        $columns = [];
        foreach ($configuredList as $field) {
            $columns[] = $this->buildSqlReportColumnItem($field, $leftFixedKeys, $isStore);
            $usedKeys[] = $field['key'];
        }
        foreach ($sqlKeys as $key) {
            if (in_array($key, $usedKeys, true)) {
                continue;
            }
            $columns[] = $this->buildSqlReportColumnItem([
                'key' => $key,
                'name' => $key,
                'type' => 0,
                'info' => '',
            ], $leftFixedKeys, $isStore);
        }
        return $columns;
    }

    /**
     * 从 SQL 解析 SELECT 列名（别名）
     */
    public function getSqlReportColumnKeys(string $tableIds, array $params = []): array
    {
        $sql = $this->getSqlReportSql($tableIds);
        $params = $this->mergeSqlReportParams($params, []);
        $vars = $this->buildSqlReportVariables($params);
        if (trim((string)($vars['date_start'] ?? '')) === '') {
            $vars['date_start'] = '1970-01-01 00:00:00';
            $vars['date_end'] = '2099-12-31 23:59:59';
        }
        $sql = $this->replaceSqlReportPlaceholders($sql, $vars);
        $this->validateSqlReportQuery($sql);
        return $this->introspectSqlReportColumns($sql);
    }

    /**
     * 去掉 SQL 末尾已有 LIMIT，避免探测时重复拼接
     */
    protected function stripSqlTrailingLimit(string $sql): string
    {
        return preg_replace('/\s+LIMIT\s+\d+(\s*,\s*\d+)?\s*$/is', '', trim($sql));
    }

    /**
     * 探测 SQL 结果集列名（子查询包裹，禁止在原始 SQL 后直接拼 LIMIT）
     */
    protected function introspectSqlReportColumns(string $sql): array
    {
        $sql = $this->stripSqlTrailingLimit(rtrim(trim($sql), ';'));
        $wrap = static function (string $innerSql, string $limitClause): string {
            return 'SELECT * FROM (' . $innerSql . ') AS __crmeb_sql_probe ' . $limitClause;
        };

        try {
            $pdo = Db::connect()->getPdo();
            $stmt = $pdo->query($wrap($sql, 'LIMIT 0'));
            if ($stmt && $stmt->columnCount() > 0) {
                $columns = [];
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $name = $meta['name'] ?? '';
                    if ($name !== '') {
                        $columns[] = $name;
                    }
                }
                if (!empty($columns)) {
                    return $columns;
                }
            }
        } catch (\Throwable $e) {
            // PDO 元数据不可用时改用子查询 LIMIT 1
        }

        try {
            $rows = Db::query($wrap($sql, 'LIMIT 1'));
            if (!empty($rows[0]) && is_array($rows[0])) {
                return array_keys($rows[0]);
            }
        } catch (\Throwable $e) {
            throw new ValidateException('无法从 SQL 解析列名：' . $e->getMessage());
        }

        throw new ValidateException('无法从 SQL 解析列名，请检查语句或先在字段表配置列');
    }

    protected function buildSqlReportColumnItem(array $field, array $leftFixedKeys, bool $isStore): array
    {
        $columnOne = [
            'title' => $field['name'] ?? $field['key'],
            'minWidth' => 100,
            'sortable' => true,
        ];
        if ($isStore) {
            $columnOne['input_type'] = 0;
            $columnOne['input_info'] = [];
            if (in_array((int)($field['type'] ?? 0), [1, 3, 4], true)) {
                $columnOne['slot'] = $field['key'];
                $columnOne['key'] = '';
                $columnOne['input_type'] = (int)$field['type'];
                $columnOne['input_info'] = !empty($field['info']) ? explode(',', (string)$field['info']) : [];
            } else {
                $columnOne['key'] = $field['key'];
                $columnOne['slot'] = '';
            }
        } else {
            $columnOne['key'] = $field['key'];
            $columnOne['slot'] = '';
        }
        if (in_array($field['key'], $leftFixedKeys, true)) {
            $columnOne['fixed'] = 'left';
        }
        return $columnOne;
    }

    /**
     * 读取报表绑定的 SQL（type=5 字段，info 存语句）
     */
    public function getSqlReportSql(string $tableIds): string
    {
        $field = SalaryField::whereFindInSet('table_ids', $tableIds)
            ->where('type', 5)
            ->order('sort', 'asc')
            ->find();
        if (!$field || trim((string)($field['info'] ?? '')) === '') {
            throw new ValidateException('请先在报表字段中配置 type=5 的 SQL 语句');
        }
        return trim((string)$field['info']);
    }

    /**
     * 解析报表日期区间（支持 2026/05/01-2026/05/31 或 2026-05-01-2026-05-31）
     */
    public function parseReportDateRange(string $dateRange): array
    {
        $dateRange = trim(str_replace('/', '-', $dateRange));
        if ($dateRange === '') {
            return ['', ''];
        }
        if (preg_match(
            '/^(\d{4}-\d{1,2}-\d{1,2}(?:\s+\d{1,2}:\d{1,2}:\d{1,2})?)\s*-\s*(\d{4}-\d{1,2}-\d{1,2}(?:\s+\d{1,2}:\d{1,2}:\d{1,2})?)$/',
            $dateRange,
            $m
        )) {
            return [trim($m[1]), trim($m[2])];
        }
        $parts = explode('-', $dateRange, 2);
        return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
    }

    /**
     * 构建 SQL 占位变量（搜索栏 key 与 {{key}} 对应，含 date_start/store_where 等）
     */
    public function buildSqlReportVariables(array $params): array
    {
        [$dateStart, $dateEnd] = $this->parseReportDateRange((string)($params['date'] ?? ''));
        if ($dateEnd !== '' && strlen($dateEnd) <= 10) {
            $dateEnd .= ' 23:59:59';
        }
        $storeId = (int)($params['store_id'] ?? 0);
        $vars = [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'start_time' => $dateStart,
            'end_time' => $dateEnd,
            'store_id' => $storeId,
            'store_where' => $storeId > 0 ? ' AND h.store_id = ' . $storeId : '',
            'store_where_order' => $storeId > 0 ? ' AND o.store_id = ' . $storeId : '',
        ];
        $skipKeys = ['table_ids', 'salary_status', 'is_excel', 'page', 'limit', 'product_type', 'date'];
        foreach ($params as $key => $val) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }
            if (!is_scalar($val) && $val !== null) {
                continue;
            }
            $strVal = $val === null ? '' : (string)$val;
            $vars[$key] = $strVal;
            if ($strVal !== '' && preg_match('/\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', $strVal)) {
                [$rangeStart, $rangeEnd] = $this->parseReportDateRange($strVal);
                if ($rangeStart !== '' && $rangeEnd !== '') {
                    if (strlen($rangeEnd) <= 10) {
                        $rangeEnd .= ' 23:59:59';
                    }
                    $vars[$key . '_start'] = $rangeStart;
                    $vars[$key . '_end'] = $rangeEnd;
                }
            }
        }
        return $vars;
    }

    /**
     * 替换 {{var}} / {var} 占位符
     */
    public function replaceSqlReportPlaceholders(string $sql, array $vars): string
    {
        foreach ($vars as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $rep = (string)$value;
            $sql = str_replace(['{{' . $key . '}}', '{' . $key . '}'], $rep, $sql);
        }
        // 未传参的占位符置空，避免残留 {{xxx}} 导致语法错误
        $sql = preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '', $sql);
        $sql = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $sql);
        return $sql;
    }

    /**
     * 仅允许只读 SELECT
     */
    protected function validateSqlReportQuery(string $sql): void
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new ValidateException('SQL 不能为空');
        }
        if (preg_match('/;\s*\S/i', $sql)) {
            throw new ValidateException('不允许执行多条 SQL 语句');
        }
        $upper = strtoupper(ltrim($sql));
        if (!preg_match('/^(SELECT|WITH)\b/', $upper)) {
            throw new ValidateException('仅允许 SELECT 查询');
        }
        $forbidden = [
            ' DROP ', ' DELETE ', ' UPDATE ', ' INSERT ', ' TRUNCATE ', ' ALTER ', ' CREATE ',
            ' REPLACE ', ' GRANT ', ' REVOKE ', ' CALL ', ' EXEC ', ' EXECUTE ',
            ' INTO OUTFILE', ' LOAD_FILE', ' LOAD DATA', ' OUTFILE ',
        ];
        $check = ' ' . $upper . ' ';
        foreach ($forbidden as $word) {
            if (strpos($check, $word) !== false) {
                throw new ValidateException('SQL 包含不允许的操作');
            }
        }
    }

    /**
     * 按字段配置映射 SQL 结果列（key 与 SQL 列名/别名一致）
     */
    protected function formatSqlReportRows(array $rows, $columnFields): array
    {
        $fieldList = is_array($columnFields) ? $columnFields : $columnFields->toArray();
        $keys = array_column($fieldList, 'key');
        $list = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = [];
            if (!empty($keys)) {
                foreach ($keys as $key) {
                    $item[$key] = $row[$key] ?? '';
                }
            } else {
                foreach ($row as $k => $v) {
                    $item[$k] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }
            $list[] = $item;
        }
        return $list;
    }

    /**
     * SQL 报表合计行
     */
    protected function sqlReportCount(array $list, string $tableIds): array
    {
        $sumFields = SalaryField::where('is_show', 1)
            ->where('is_count', 1)
            ->where('type', '<>', 5)
            ->whereFindInSet('table_ids', $tableIds)
            ->select();
        $total = [];
        foreach ($sumFields as $field) {
            $key = $field['key'];
            $sum = '0';
            foreach ($list as $row) {
                $val = $row[$key] ?? 0;
                if (is_numeric($val)) {
                    $sum = bcadd((string)$sum, (string)$val, 2);
                }
            }
            $total[] = ['name' => $field['name'], 'count' => $sum];
        }
        return $total;
    }
}
