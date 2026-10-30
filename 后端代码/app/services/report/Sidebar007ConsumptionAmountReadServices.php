<?php
declare(strict_types=1);

namespace app\services\report;

/**
 * 门店统计和效能的项目消耗对账候选。该源先核旧核销全量覆盖、正式
 * 消费事实及即买即用成交行；它按执行门店/核销行计额，不能直接填入
 * 按服务订单归属门店/订单行统计的 007 消耗列。
 */
final class Sidebar007ConsumptionAmountReadServices
{
    private $reportReader;

    /** 注入点仅用于合同测试；生产复用唯一项目消耗 Reader，不另算 SQL。 */
    public function __construct(?callable $reportReader=null)
    {
        $this->reportReader=$reportReader ?: static fn(array $stores,array $range):array=>
            (new ItemConsumption007ReadServices())->read($stores,$range);
    }

    /** 仅当所有授权店的项目消耗来源完整时，汇总为供订单桥对账的整数分。 */
    public function read(array $stores,array $range):array
    {
        $allowed=[];
        foreach($stores as $id){
            if(!is_int($id)||$id<1||isset($allowed[$id]))
                throw new \InvalidArgumentException('消耗聚合门店范围无效');
            $allowed[$id]=true;
        }
        if(!$allowed)throw new \InvalidArgumentException('消耗聚合门店范围为空');
        $result=($this->reportReader)($stores,$range);
        if(!is_array($result)||!is_array($result['rows']??null)||!is_array($result['gaps']??null))
            throw new \LogicException('项目消耗结果契约无效');
        if(($result['complete']??false)!==true||$result['gaps']!==[])
            return ['by_store'=>array_fill_keys($stores,null),'complete'=>false,
                'gaps'=>$result['gaps'],'source'=>'item_consumption_007_verified'];
        $out=array_fill_keys($stores,0);
        foreach($result['rows'] as $row){
            $id=$row['store_id']??null;
            if(!is_int($id)||!isset($allowed[$id]))throw new \LogicException('项目消耗越过门店授权');
            $out[$id]+=$this->cents($row['consumption_amount']??null);
        }
        return ['by_store'=>$out,'complete'=>true,'gaps'=>[],
            'source'=>'item_consumption_007_verified'];
    }

    /** 源报告以元返回，转分时只接受最多两位的确定十进制值。 */
    private function cents($value):int
    {
        $text=is_int($value)||is_float($value)||is_string($value)?(string)$value:'';
        if(!preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D',$text,$parts))
            throw new \LogicException('项目消耗金额不能精确换算为分');
        return (int)$parts[1]*100+(int)str_pad($parts[2]??'0',2,'0');
    }
}
