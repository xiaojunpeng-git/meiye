<?php
declare(strict_types=1);
namespace app\services\report;

/**
 * 已封存历史门店统计的统一明细投影。只适配导入批次声明的历史期间，
 * 不生成收款/核销事实，不更新余额、卡权益，也不接收客户端提供的指标值。
 * 原007的截尾时点、商品分类扣减和按日客数去重保留在唯一后端中。
 */
final class HistoricalStoreSummaryFacts
{
    public const POLICY = 'legacy-store-summary-sealed-v1';
    public const DOMAINS = ['orders','staff','quantities'];

    /** 导入前验证封存文件的完整内容，转换为可按日期/门店查询的显式事实列。 */
    public static function normalize(array $payload): array
    {
        $hash=$payload['payload_sha256']??'';unset($payload['payload_sha256']);
        if(($payload['format']??'')!=='legacy-store-summary-facts-v1'
            || !hash_equals(self::hash($payload),(string)$hash)
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($payload['source_archive_sha256']??'')))
            throw new \InvalidArgumentException('历史统计原件指纹不一致');
        self::range($payload['period']);
        $out=['period'=>$payload['period'],'payload_sha256'=>$hash,
            'source_archive_sha256'=>$payload['source_archive_sha256'],
            'source_schema'=>$payload['source_schema'],'stores'=>$payload['stores']];
        $stores=[];foreach($out['stores'] as $s){$id=(int)$s['id'];
            if($id<1||isset($stores[$id]))throw new \InvalidArgumentException('历史门店身份重复');$stores[$id]=true;}
        foreach(self::DOMAINS as $domain){
            $out[$domain]=[];$seen=[];
            foreach($payload[$domain] as $source){
                $kind=$domain==='quantities'?(string)$source['fact_kind']:$domain;
                $id=(int)$source['source_id'];$store=(int)$source['store_id'];$day=(string)$source['business_date'];
                $key=$kind.':'.$id;
                if($id<1||!isset($stores[$store])||isset($seen[$key])
                    ||$day<$out['period']['start']||$day>$out['period']['end']
                    ||date('Y-m-d',(int)$source['occurred_at'])!==$day)
                    throw new \InvalidArgumentException('历史事实身份或日期无效');
                $seen[$key]=true;
                $row=['source_id'=>$id,'store_id'=>$store,'business_date'=>$day,
                    'occurred_at'=>(int)$source['occurred_at'],'source_hash'=>self::hash($source)];
                if($domain==='orders'){
                    $row+=['source_no'=>(string)$source['source_no'],'member_id'=>(int)$source['member_id'],
                        'friend_service'=>trim((string)$source['service_object'])==='朋友'?1:0];
                    foreach(['cash_amount','cooperation_cash','consumption_amount','inside_consumption_amount'] as $k)
                        $row[$k.'_cents']=self::cents($source[$k]);
                    foreach(['service_customer','new_customer','casual_customer'] as $k)$row[$k]=self::flag($source[$k]);
                }elseif($domain==='staff'){
                    $row+=['order_id'=>(int)$source['order_id'],'link_id'=>(int)$source['link_id'],
                        'staff_id'=>(int)$source['staff_id'],'member_id'=>(int)$source['member_id'],
                        'friend_service'=>trim((string)$source['service_object'])==='朋友'?1:0,
                        'partner_amount_cents'=>self::cents($source['partner_amount']),
                        'designated_customer'=>self::flag($source['designated_customer'])];
                }else{
                    if(!in_array($kind,['sale','writeoff'],true)
                        ||!preg_match('/^\d+(?:\.\d{1,6})?$/D',(string)$source['quantity']))
                        throw new \InvalidArgumentException('历史项目数量无效');
                    $row+=['fact_kind'=>$kind,'order_id'=>(int)$source['order_id'],
                        'product_id'=>(int)$source['product_id'],'quantity'=>bcadd((string)$source['quantity'],'0',6)];
                }
                // 明确列参与查询；完整来源JSON只用于逐笔追溯与重新认证，不能替代事实查询。
                $row['source_json']=json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $out[$domain][]=$row;
            }
        }
        return $out;
    }

    /** 同一明细集合服务平台、门店、合计和导出；日期与门店必须在聚合前校验。 */
    public static function aggregate(array $facts,array $dimensions,array $range): array
    {
        self::range($range);$acc=[];
        foreach($dimensions as $s){
            $id=(int)$s['id'];if($id<1||isset($acc[$id]))throw new \InvalidArgumentException('历史报表门店重复');
            $acc[$id]=['cash'=>0,'cooperation'=>0,'consume'=>0,'inside_consume'=>0,'partner'=>0,
                'customer'=>[],'new'=>[],'casual'=>[],'designated'=>[],'sale_quantity'=>'0','writeoff_quantity'=>'0'];
        }
        foreach(self::DOMAINS as $domain){$seen=[];
            foreach($facts[$domain] as $r){
                $id=(int)$r['store_id'];$day=(string)$r['business_date'];
                if(!isset($acc[$id])||$day<$range['start']||$day>$range['end'])
                    throw new \LogicException('历史报表事实超出授权范围');
                $identity=($r['fact_kind']??$domain).':'.$r['source_id'];
                if(isset($seen[$identity]))throw new \LogicException('历史报表事实重复');$seen[$identity]=true;
                $a=&$acc[$id];
                if($domain==='orders'){
                    foreach(['cash'=>'cash_amount_cents','cooperation'=>'cooperation_cash_cents',
                        'consume'=>'consumption_amount_cents','inside_consume'=>'inside_consumption_amount_cents'] as $to=>$from)
                        $a[$to]=self::add($a[$to],self::integer($r[$from]));
                    $key=self::customerKey($r);
                    foreach(['customer'=>'service_customer','new'=>'new_customer','casual'=>'casual_customer'] as $to=>$from)
                        if(self::flag($r[$from]))$a[$to][$key]=true;
                }elseif($domain==='staff'){
                    $a['partner']=self::add($a['partner'],self::integer($r['partner_amount_cents']));
                    if(self::flag($r['designated_customer']))$a['designated'][self::customerKey($r)]=true;
                }else{
                    $k=$r['fact_kind'].'_quantity';
                    if(!array_key_exists($k,$a)||!preg_match('/^\d+(?:\.\d{1,6})?$/D',(string)$r['quantity']))
                        throw new \LogicException('历史数量事实无效');
                    $a[$k]=bcadd($a[$k],(string)$r['quantity'],6);
                }
                unset($a);
            }
        }
        $rows=[];
        foreach($dimensions as $s){$id=(int)$s['id'];$a=$acc[$id];
            // 007先把全店现金截为整元，再扣精确到分的合作商品金额并截尾；
            // 不得对每单四舍五入，也不能以人员分成代替商品类别扣减。
            $cash=intdiv($a['cash'],100);$consume=intdiv($a['consume'],100);
            $new=count($a['new']);$total=$new+count($a['casual']);
            $rows[]=['row_id'=>'store_summary_007:'.$id,'store_id'=>$id,'store_name'=>$s['name'],
                'cash_performance'=>(string)$cash,'actual_performance'=>(string)($cash-intdiv($a['partner'],100)),
                'inside_cash_performance'=>(string)intdiv(max(0,$cash*100-$a['cooperation']),100),
                'consumption_performance'=>(string)$consume,
                'inside_consumption_performance'=>(string)intdiv($a['inside_consume'],100),
                'consumption_cash_ratio'=>$cash>0?intdiv($consume*100,$cash):0,
                'customer_count'=>count($a['customer']),'designated_customer_count'=>count($a['designated']),
                'new_customer_count'=>$new,'new_customer_total_count'=>$total,
                'new_customer_conversion_rate'=>$total>0?intdiv($new*100,$total):0,
                'sold_project_count'=>(int)$a['sale_quantity'],'consumed_project_count'=>(int)$a['writeoff_quantity']];
        }
        return $rows;
    }

    public static function hash(array $value): string
    { return hash('sha256',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }

    private static function customerKey(array $r): string
    {
        // 朋友/游客按本域原始记录逐次计；会员按门店+自然日+会员ID去重。
        return (int)$r['member_id']===0||self::flag($r['friend_service'])
            ?'record:'.$r['source_id']:$r['business_date'].':member:'.$r['member_id'];
    }
    private static function flag($value): int
    { if(!in_array($value,[0,1,'0','1'],true))throw new \InvalidArgumentException('历史资格标记无效');return (int)$value; }
    private static function cents($value): int
    {
        if(!preg_match('/^-?\d+(?:\.\d{1,2})?$/D',(string)$value))throw new \InvalidArgumentException('历史金额精度无效');
        return self::integer(bcmul((string)$value,'100',0));
    }
    private static function integer($value): int
    {
        if(!preg_match('/^-?\d+$/D',(string)$value)||bccomp((string)$value,(string)PHP_INT_MAX,0)>0
            ||bccomp((string)$value,(string)PHP_INT_MIN,0)<0)throw new \OverflowException('历史金额超出整数范围');
        return (int)$value;
    }
    private static function add(int $a,int $b): int { return self::integer(bcadd((string)$a,(string)$b,0)); }
    private static function range(array $r): void
    {
        foreach(['start','end'] as $k){$v=$r[$k]??'';$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
            if(!$d||$d->format('Y-m-d')!==$v)throw new \InvalidArgumentException('历史期间无效');}
        if($r['start']>$r['end'])throw new \InvalidArgumentException('历史期间倒置');
    }
}
