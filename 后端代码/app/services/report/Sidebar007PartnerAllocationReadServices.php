<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 合作方业绩只取业务发生时人员类型快照为 partner 的实际销售分配事实。
 * 不用当前商品分类、合作开关、默认分成比例或当前人员类型回推历史。
 * 类型快照缺失的人员事实使所在门店结果不完整，不能把漏数当作零。
 */
final class Sidebar007PartnerAllocationReadServices
{
    /**
     * @param int[] $stores 后端已授权的门店，不接受浏览器自报范围
     * @param array{start:string,end:string} $range
     * @return array{by_store:array<int,int|null>,separate_facts:array,gaps:array,complete:bool,source:string}
     */
    public function read(array $stores, array $range, ?callable $factQuery = null): array
    {
        $ids = [];
        foreach ($stores as $store) {
            if (!is_int($store) || $store < 1) throw new \InvalidArgumentException('合作方门店授权范围无效');
            $ids[$store] = $store;
        }
        if (!$ids) throw new \InvalidArgumentException('合作方门店授权范围为空');
        foreach (['start','end'] as $key) {
            $date = $range[$key] ?? null;
            $day = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d',$date) : false;
            if (!$day || $day->format('Y-m-d') !== $date) throw new \InvalidArgumentException('合作方统计日期无效');
        }
        if ($range['start'] > $range['end'] || $range['start'] < StoreUnifiedReportServices::COVERAGE_START) {
            throw new \InvalidArgumentException('合作方统计日期超出 V3 事实覆盖范围');
        }
        $factQuery ??= static function (array $scope, array $period): array {
            return Db::name('cashier_v3_performance_fact')->where('tenant_id','0')
                ->whereIn('store_id',$scope)->whereBetween('business_date',[$period['start'],$period['end']])
                ->where('status','effective')->whereIn('performance_type',['sales_performance_allocated','refund_sales_deduction'])
                ->whereIn('fact_direction',['forward','reversal'])
                // 金额列本身有符号；不能按 reversal 再取反。历史改派写入
                // 的正额 reversal 若未补正，显式置缺口以免高估合作方业绩。
                ->fieldRaw("store_id,SUM(CASE WHEN performance_type='sales_performance_allocated' AND employee_type_snapshot='partner' THEN amount_cents ELSE 0 END) AS partner_amount_cents,SUM(CASE WHEN performance_type='sales_performance_allocated' AND employee_type_snapshot='outsourced' THEN amount_cents ELSE 0 END) AS outsourced_amount_cents,SUM(CASE WHEN performance_type='refund_sales_deduction' THEN amount_cents ELSE 0 END) AS refund_deduction_cents,SUM(CASE WHEN performance_type='sales_performance_allocated' AND employee_id>0 AND (employee_type_snapshot NOT IN ('internal','partner','outsourced') OR employee_type_authority_version=0) THEN 1 ELSE 0 END) AS unclassified_rows,SUM(CASE WHEN performance_type='sales_performance_allocated' AND employee_type_snapshot='partner' AND fact_direction='reversal' AND amount_cents>0 THEN 1 ELSE 0 END) AS unsigned_reversal_rows")
                ->group('store_id')->limit(10001)->select()->toArray();
        };
        $facts = $factQuery(array_values($ids),$range);
        if (!is_array($facts) || count($facts)>10000) throw new \LogicException('合作方事实结果无效或过大');
        $byStore = array_fill_keys(array_values($ids),0); $separate=[]; $seen=[]; $gaps=[];
        foreach ($facts as $row) {
            $id = $this->integer($row['store_id'] ?? null,false);
            $amount = $this->integer($row['partner_amount_cents'] ?? null,true);
            $outsourced = $this->integer($row['outsourced_amount_cents'] ?? null,true);
            $refund = $this->integer($row['refund_deduction_cents'] ?? null,true);
            $unknown = $this->integer($row['unclassified_rows'] ?? null,false);
            $unsignedReversals = $this->integer($row['unsigned_reversal_rows'] ?? null,false);
            if (!isset($ids[$id]) || isset($seen[$id])) throw new \LogicException('合作方事实越权或重复');
            $seen[$id]=true;
            $separate[$id]=['outsourced_amount_cents'=>$outsourced,'refund_deduction_cents'=>$refund];
            if ($unknown > 0 || $unsignedReversals > 0) {
                $byStore[$id]=null;
                $gaps[]=['store_id'=>$id,'code'=>'PARTNER_EMPLOYEE_TYPE_SNAPSHOT_UNVERIFIED',
                    'message'=>'存在 '.$unknown.' 条类型快照缺失及 '.$unsignedReversals.' 条正额冲销的销售分配事实，不能确定合作方业绩'];
            } else $byStore[$id]=$amount;
        }
        foreach ($ids as $id) $separate[$id] ??= ['outsourced_amount_cents'=>0,'refund_deduction_cents'=>0];
        return ['by_store'=>$byStore,'separate_facts'=>$separate,'gaps'=>$gaps,'complete'=>$gaps===[],
            'source'=>'cashier_v3_performance_fact.sales_performance_allocated + employee_type_snapshot=partner'];
    }

    /** SQL 聚合经字符串传输；精确验证整数且不经 float，允许退款冲减为负。 */
    private function integer($value, bool $signed): int
    {
        $text = is_int($value) ? (string)$value : (is_string($value) ? $value : '');
        if (!preg_match($signed ? '/^-?(?:0|[1-9][0-9]*)$/D' : '/^(?:0|[1-9][0-9]*)$/D',$text)) {
            throw new \LogicException('合作方事实整数结果无效');
        }
        $number = filter_var($text,FILTER_VALIDATE_INT);
        if ($number === false) throw new \LogicException('合作方事实整数溢出');
        return $number;
    }
}
