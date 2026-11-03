<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 007 销售数据与销售分析的 V3 只读适配边界。授权门店由 Host 给出，
 * 这里仅按真人页面的纵向指标、横向门店生成矩阵，并核查 V3 销售事实覆盖。
 * 旧客源、合作项目和服务客口径尚无同义的已认证聚合时保留缺口，不能借
 * 另一个名字相似的指标填零或直接展示。
 */
final class Sidebar007SalesV3ReadServices
{
    private const REPORTS = ['sales_data', 'sales_analysis'];
    private const SALES_MONEY_ROWS = [1, 3, 5, 7, 9, 11, 13, 15, 17, 19];

    /**
     * $certifiedByStore 仅供后端事实 Reader 注入已核准的同口径基础指标，
     * 不得来自请求、前端、旧报表 SQL 或按展示文本猜测的分类。
     * 销售数据键为指标序号（0..19）；销售分析键为投影器定义的基础指标名。
     * 返回值不含未经证实的经营数值；V3 覆盖数只是诊断，不是 007 报表指标。
     *
     * @param int[] $authorizedStoreIds
     * @param array<int,array{id:int,name:string}> $stores
     * @param array<int,array<int|string,int>> $certifiedByStore
     */
    public function read(
        string $report,
        array $authorizedStoreIds,
        array $stores,
        string $startDate,
        string $endDate,
        array $certifiedByStore = [],
        ?callable $coverageReader = null,
        ?callable $partnerFactQuery = null,
        ?callable $sourceMappingReader = null,
        ?callable $sourceCategoryReader = null,
        ?callable $serviceOrderReader = null,
        ?callable $designatedReader = null
    ): array {
        if (!in_array($report, self::REPORTS, true)) throw new \InvalidArgumentException('未登记销售侧栏报表');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if (!$from || !$to || $from->format('Y-m-d') !== $startDate || $to->format('Y-m-d') !== $endDate || $from > $to) {
            throw new \InvalidArgumentException('统计日期范围无效');
        }
        $allowed = [];
        foreach ($authorizedStoreIds as $id) {
            if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('门店授权范围无效');
            $allowed[$id] = true;
        }
        if (!$allowed) throw new \InvalidArgumentException('门店授权范围为空');
        $columns = [];
        $selected = [];
        foreach ($stores as $store) {
            $id = $store['id'] ?? null;
            $name = trim((string)($store['name'] ?? ''));
            if (!is_int($id) || $id < 1 || !isset($allowed[$id]) || isset($selected[$id]) || $name === '') {
                throw new \InvalidArgumentException('门店列超出授权范围或身份不完整');
            }
            $selected[$id] = true;
            $columns[] = ['key' => 'store_' . $id, 'store_id' => $id, 'label' => $name];
        }
        foreach ($certifiedByStore as $id => $values) {
            if (!isset($selected[(int)$id]) || !is_array($values)) throw new \InvalidArgumentException('认证指标超出当前门店列');
            foreach ($values as $key => $value) {
                $known = $report === 'sales_data'
                    ? is_int($key) && $key >= 0 && $key < 20
                    : in_array($key, ['new_customer_count', 'casual_customer_count', 'designated_customer_count',
                        'service_customer_count'], true);
                if (!$known || ($report === 'sales_data' && $key === 17)
                    || ($value !== null && (!is_int($value) || $value < 0))) {
                    throw new \InvalidArgumentException('认证指标的字段或整数值无效');
                }
            }
        }
        $storeIds = array_keys($selected);
        $coverage = $coverageReader ? $coverageReader($storeIds, $startDate, $endDate) : $this->readV3Coverage($storeIds, $startDate, $endDate);
        $coverage = $this->validatedCoverage($coverage, $selected);
        // 迁入来源的旧 ID 与新快照 ID 以执行时审计映射为准；仅记录映射
        // 证据仍不足以证明金额和客数，不能因此把类别单元格直接填数。
        $sourceMapping = $sourceMappingReader ? $sourceMappingReader()
            : (new Sidebar007SourceMappingReadServices())->read();
        if (!is_array($sourceMapping) || !is_bool($sourceMapping['complete'] ?? null))
            throw new \LogicException('007 客源映射结果无效');
        // 客源分组与合作方业绩都需核同一批旧分配事实。正式查询在同一
        // 只读事务、同一门店和日期范围内复用结果，避免重复扫描历史表。
        $historicalPartnerReader = null;
        if ($report === 'sales_data' && $sourceCategoryReader === null && $partnerFactQuery === null) {
            $historicalPartnerCache = null;
            $historicalPartnerReader = static function (array $scope, array $period) use (&$historicalPartnerCache): array {
                if ($historicalPartnerCache === null) {
                    $historicalPartnerCache = (new HistoricalPartnerSales007ReadServices())->read($scope, $period);
                }
                return $historicalPartnerCache;
            };
        }
        $sourceCategories = $sourceCategoryReader
            ? $sourceCategoryReader($storeIds,['start'=>$startDate,'end'=>$endDate],$sourceMapping)
            : (new Sidebar007SalesCategoryReadServices(null, null, null, $historicalPartnerReader))->read($storeIds,
                ['start'=>$startDate,'end'=>$endDate],$sourceMapping);
        if (!is_array($sourceCategories) || !is_array($sourceCategories['by_store'] ?? null))
            throw new \LogicException('007 客源分类结果无效');
        foreach ($sourceCategories['by_store'] as $id=>$groups) {
            if (!isset($selected[(int)$id]) || !is_array($groups))
                throw new \LogicException('007 客源分类越过门店授权');
            $values = $groups[$report] ?? [];
            if (!is_array($values)) throw new \LogicException('007 客源指标结构无效');
            foreach ($values as $key=>$value) {
                if (!is_int($value) || $value < 0) throw new \LogicException('007 客源指标金额或人数无效');
                if (array_key_exists($key,$certifiedByStore[(int)$id]??[])
                    && $certifiedByStore[(int)$id][$key]!==$value)
                    throw new \LogicException('007 客源指标存在相互矛盾的后端事实');
                $certifiedByStore[(int)$id][$key]=$value;
            }
        }
        // 销售数据“总客数”和销售分析“总客数”均调用 007 同一
        // consume(..., isCount=1)，复用服务子单桥，不能以销售客数代替。
        $serviceOrders=$serviceOrderReader
            ?$serviceOrderReader($storeIds,['start'=>$startDate,'end'=>$endDate])
            :(new Sidebar007ServiceOrderReadServices())->read($storeIds,
                ['start'=>$startDate,'end'=>$endDate]);
        if(!is_array($serviceOrders)||!is_bool($serviceOrders['complete']??null)
            ||!is_array($serviceOrders['by_store']??null))
            throw new \LogicException('销售侧栏服务子单来源无效');
        if($serviceOrders['complete'])foreach($storeIds as $id){
            $count=$serviceOrders['by_store'][$id]['service_customer_count']??null;
            if(!is_int($count)||$count<0)throw new \LogicException('销售侧栏服务客数缺失');
            $key=$report==='sales_data'?18:'service_customer_count';
            if(array_key_exists($key,$certifiedByStore[$id]??[])
                &&$certifiedByStore[$id][$key]!==$count)
                throw new \LogicException('销售侧栏服务客数来源矛盾');
            $certifiedByStore[$id][$key]=$count;
        }
        if($report==='sales_data'&&$serviceOrders['complete'])foreach($storeIds as $id){
            if(!isset($sourceCategories['by_store'][$id]))continue;
            $sales=$sourceCategories['after_sales_entries'][$id]??null;
            $services=$serviceOrders['qualified_entries']??null;
            if(!is_array($sales)||!is_array($services))continue;
            // 007 售后客数把来源11有现金原销售与有效服务子单放在
            // 同一人口去重；会员同日一次，朋友每张原行计一次。
            $seen=[];$count=0;$entryIds=[];
            foreach(array_merge($sales,$services) as $entry){
                $store=(int)($entry['store_id']??0);
                if(!isset($selected[$store]))throw new \LogicException('售后客来源越过授权门店');
                if($store!==$id)continue;
                if(($entry['aftercare_cash_eligible']??true)!==true)continue;
                $member=(int)($entry['member_id']??0);
                if($member<=0)continue;
                $day=(string)($entry['business_date']??'');
                $order=(string)($entry['order_id']??'');
                $friend=$entry['friend']??null;
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)||!is_bool($friend)||$order==='')
                    throw new \LogicException('售后客原行身份无效');
                // 一张服务单可有多条项目事实，也可同时服务本人和朋友。
                // 本人按会员当日去重、朋友按服务单去重；同对象的身份冲突仍拒绝。
                $entryKey=$order.'|'.($friend?'friend':'self');
                $identity=[$store,$day,$member,$friend];
                if(isset($entryIds[$entryKey])){
                    if($entryIds[$entryKey]!==$identity)
                        throw new \LogicException('售后客同单身份冲突');
                    continue;
                }
                $entryIds[$entryKey]=$identity;
                $key=$friend?'record:'.$order:'member:'.$day.':'.$member;
                if(!isset($seen[$key])){$seen[$key]=true;++$count;}
            }
            if(array_key_exists(14,$certifiedByStore[$id]??[])
                &&$certifiedByStore[$id][14]!==$count)
                throw new \LogicException('售后客数来源矛盾');
            $certifiedByStore[$id][14]=$count;
        }
        $designated=null;
        if($report==='sales_analysis'){
            $designated=$designatedReader
                ?$designatedReader($storeIds,['start'=>$startDate,'end'=>$endDate])
                :(new Sidebar007DesignatedCustomerReadServices())->read($storeIds,
                    ['start'=>$startDate,'end'=>$endDate]);
            if(!is_array($designated)||!is_bool($designated['complete']??null)
                ||!is_array($designated['by_store']??null))
                throw new \LogicException('销售分析指定客来源无效');
            if($designated['complete'])foreach($storeIds as $id){
                $point=$designated['by_store'][$id]??null;
                if(!is_int($point)||$point<0)
                    throw new \LogicException('销售分析指定客数缺失');
                if(array_key_exists('designated_customer_count',$certifiedByStore[$id]??[])
                    &&$certifiedByStore[$id]['designated_customer_count']!==$point)
                    throw new \LogicException('销售分析指定客来源矛盾');
                $certifiedByStore[$id]['designated_customer_count']=$point;
            }
        }
        $partner = null;
        if ($report === 'sales_data') {
            // 007 第 18 行“合作项目业绩”按本轮产品定义仅展示真正分配给
            // partner 人员的销售业绩；不能复用按商品分类比例生成的合作金额。
            $partner = (new Sidebar007PartnerAllocationReadServices())->read($storeIds,
                ['start'=>$startDate,'end'=>$endDate],$partnerFactQuery,$historicalPartnerReader);
            foreach ($storeIds as $id) if ($partner['by_store'][$id] !== null) {
                $certifiedByStore[$id][17] = $partner['by_store'][$id];
            }
            // 原页“门店总业绩”是各客源业绩行之和；售后等任何一项
            // 尚无同口径来源时整格留空，不能拿收款总额替代该公式。
            foreach($storeIds as $id){
                $parts=[1,3,5,7,9,11,13,15,17];$sum=0;$ready=true;
                foreach($parts as $part){
                    $value=$certifiedByStore[$id][$part]??null;
                    if(!is_int($value)){$ready=false;break;}
                    if(($value>0&&$sum>PHP_INT_MAX-$value)
                        ||($value<0&&$sum<PHP_INT_MIN-$value))
                        throw new \OverflowException('门店总业绩金额溢出');
                    $sum+=$value;
                }
                if($ready){
                    if(array_key_exists(19,$certifiedByStore[$id]??[])
                        &&$certifiedByStore[$id][19]!==$sum)
                        throw new \LogicException('门店总业绩与已认证客源金额不符');
                    $certifiedByStore[$id][19]=$sum;
                }
            }
        }
        $matrix = $report === 'sales_analysis'
            ? Sidebar007ParityPlanServices::projectMatrix($report, $authorizedStoreIds, $stores, $certifiedByStore, $startDate, $endDate)
            : $this->salesDataMatrix($columns, $certifiedByStore);
        return $matrix + [
            'report' => $report,
            'orientation' => 'metric_rows',
            'coverage' => $coverage,
            'coverage_note' => 'V3 销售事实覆盖数仅用于排查迁移缺口，不等于 007 任一经营指标',
            'metric_source' => '仅接受后端已认证的同口径 V3 基础指标；销售数据合作方业绩取人员类型快照和实际分配事实',
            'coverage_source' => 'cashier_v3_sale_fact',
            'row_sources' => Sidebar007ParityPlanServices::rowSources($report),
            'source_mapping' => $sourceMapping,
            'source_categories' => $sourceCategories,
            'service_order_source' => $serviceOrders,
            'designated_source' => $designated,
            'partner_allocation' => $partner,
            'scope' => ['store_ids' => $storeIds, 'date_range' => ['start' => $startDate, 'end' => $endDate]],
        ];
    }

    /** 销售数据的 20 行与 007 一致；只投影经同口径事实认证的单元格。 */
    private function salesDataMatrix(array $columns, array $base): array
    {
        $labels = Sidebar007ParityPlanServices::layout('sales_data')['rows'];
        $rows = [];
        $gaps = [];
        foreach ($labels as $index => $label) {
            $row = ['row_id' => 'sales_data:' . $index, 'metric' => $label,
                'unit' => in_array($index, self::SALES_MONEY_ROWS, true) ? 'money_cents' : 'count', 'cells' => []];
            foreach ($columns as $column) {
                $id = $column['store_id'];
                $values = $base[$id] ?? [];
                $value = $values[$index] ?? null;
                if ($value !== null && (!is_int($value) || ($index !== 17 && $value < 0))) {
                    throw new \InvalidArgumentException('销售数据认证指标的整数值无效');
                }
                $row['cells'][$column['key']] = $value;
                if ($value === null) $gaps[] = ['store_id' => $id, 'metric' => $label,
                    'reason' => $index === 17
                        ? '人员类型快照缺失，无法核实合作方实际分配业绩'
                        : '现有 V3 事实尚未完成 007 客源、收款或服务客筛选口径映射'];
            }
            $rows[] = $row;
        }
        return ['columns' => $columns, 'rows' => $rows, 'gaps' => $gaps, 'complete' => !$gaps];
    }

    /** 事实行数量仅验证迁移是否有数据；按授权门店与业务日期过滤，拒绝越界结果。 */
    private function readV3Coverage(array $storeIds, string $start, string $end): array
    {
        if (!$storeIds) return [];
        return Db::name('cashier_v3_sale_fact')->where('tenant_id', '0')
            ->whereIn('store_id', $storeIds)->whereBetween('business_date', [$start, $end])
            ->where('status', 'effective')->where('fact_direction', 'forward')
            ->fieldRaw('store_id,COUNT(*) AS sale_fact_rows,COUNT(DISTINCT order_id) AS sale_orders')
            ->group('store_id')->select()->toArray();
    }

    /** 不接受 SQL 结果越权、重复门店或负数，避免诊断信息旁路泄露。 */
    private function validatedCoverage(array $coverage, array $selected): array
    {
        $out = [];
        foreach ($coverage as $row) {
            $id = filter_var($row['store_id'] ?? null, FILTER_VALIDATE_INT);
            $facts = filter_var($row['sale_fact_rows'] ?? null, FILTER_VALIDATE_INT);
            $orders = filter_var($row['sale_orders'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || !isset($selected[$id]) || isset($out[$id]) || $facts === false || $orders === false
                || $facts < 0 || $orders < 0 || $orders > $facts) {
                throw new \LogicException('V3 销售事实覆盖结果越权或异常');
            }
            $out[$id] = ['store_id' => $id, 'sale_fact_rows' => $facts, 'sale_orders' => $orders];
        }
        foreach ($selected as $id => $_) $out[$id] ??= ['store_id' => $id, 'sale_fact_rows' => 0, 'sale_orders' => 0];
        return array_values($out);
    }
}
