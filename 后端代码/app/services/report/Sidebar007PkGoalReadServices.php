<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * PK 旧手录月目标的只读迁接。该表已有持久化值但无版本/幂等审计契约；
 * 只能按授权门店、自然月读回，不开放新的编辑写入或冒充目标方案指标。
 */
final class Sidebar007PkGoalReadServices
{
    private $rowsReader;

    public function __construct(?callable $rowsReader = null)
    {
        $this->rowsReader = $rowsReader ?: static function(array $stores,int $timestamp):array {
            return Db::name('yeji_pk')->whereIn('store_id',$stores)->where('date',$timestamp)
                ->field('id,store_id,goal,date')->limit(10001)->select()->toArray();
        };
    }

    /** 缺记录沿用 007 的 0 目标；同门店月份重复记录则留缺口，不能任取一条。 */
    public function read(array $stores,string $month):array
    {
        if($stores===[]||count($stores)!==count(array_unique($stores)))
            throw new \InvalidArgumentException('PK 目标门店范围无效');
        foreach($stores as $id)if(!is_int($id)||$id<1)
            throw new \InvalidArgumentException('PK 目标门店 ID 无效');
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month))
            throw new \InvalidArgumentException('PK 目标月份无效');
        $date=new \DateTimeImmutable($month.'-01 00:00:00',new \DateTimeZone('Asia/Shanghai'));
        $timestamp=$date->getTimestamp();
        $rows=($this->rowsReader)($stores,$timestamp);
        if(!is_array($rows)||count($rows)>10000)throw new \LogicException('PK 目标来源过大');
        $byStore=[];$gaps=[];$seen=[];
        foreach($rows as $row){
            $id=$row['store_id']??null;$savedDate=$row['date']??null;
            if(!is_int($id)&&!(is_string($id)&&ctype_digit($id)))
                throw new \LogicException('PK 目标门店身份无效');
            $id=(int)$id;
            if(!in_array($id,$stores,true)||(string)$savedDate!==(string)$timestamp)
                throw new \LogicException('PK 目标越权或月份不符');
            if(isset($seen[$id])){
                $byStore[$id]=null;
                $gaps[$id][]=['code'=>'PK_GOAL_DUPLICATE','message'=>'同门店月份存在多条旧手录目标，需核准唯一记录。'];
                continue;
            }
            $seen[$id]=true;
            $byStore[$id]=$this->yuanToCents($row['goal']??null);
        }
        foreach($stores as $id)if(!array_key_exists($id,$byStore))$byStore[$id]=0;
        return ['by_store'=>$byStore,'gaps'=>$gaps,'source'=>'yeji_pk.goal',
            'mode'=>'legacy_read_only','expected_version'=>null,'month'=>$month,
            'stable_keys'=>array_combine($stores,array_map(static fn(int $id):string=>
                'sales_pk:'.$month.':'.$id,$stores))];
    }

    /** 旧金额是元，严格按十进制定点转为分，不用浮点改动历史目标。 */
    private function yuanToCents($value):int
    {
        $text=(string)$value;
        if(!preg_match('/^(\d{1,13})(?:\.(\d{1,2})(?:0{1,4})?)?$/D',$text,$parts))
            throw new \LogicException('PK 旧手录目标金额精度无效');
        return (int)$parts[1]*100+(int)str_pad($parts[2]??'0',2,'0');
    }
}
