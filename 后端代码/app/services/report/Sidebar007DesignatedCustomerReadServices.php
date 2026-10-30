<?php
declare(strict_types=1);

namespace app\services\report;

use app\services\query\metric\ServiceCustomerMetricReadServices;
use think\facade\Db;

/**
 * 007 指定客只读桥：完成服务的点客劳务事实按会员同店同日去重，
 * 游客/朋友按劳动记录逐条计客，不把多人服务份额求和当人数。
 */
final class Sidebar007DesignatedCustomerReadServices
{
    private $serviceReader;
    private $metricReader;
    private $legacyCoverageReader;
    private $eligibilityReader;

    /** 注入点仅供离线合同；生产依赖正式服务、劳务事实及原身份覆盖审计。 */
    public function __construct(?callable $serviceReader=null,?callable $metricReader=null,
        ?callable $legacyCoverageReader=null,?callable $eligibilityReader=null)
    {
        $this->serviceReader=$serviceReader ?: static fn(array $stores,array $range):array=>
            (new Sidebar007ServiceOrderReadServices())->read($stores,$range);
        $this->metricReader=$metricReader ?: static fn(array $stores,array $range):array=>
            (new ServiceCustomerMetricReadServices())->employeeMetrics('0',$stores,$range);
        $this->legacyCoverageReader=$legacyCoverageReader ?: [$this,'legacyCoverage'];
        $this->eligibilityReader=$eligibilityReader ?: [$this,'friendEligibilityCount'];
    }

    public function read(array $stores,array $range):array
    {
        if($stores===[]||count($stores)!==count(array_unique($stores)))
            throw new \InvalidArgumentException('指定客门店范围无效');
        foreach($stores as $id)if(!is_int($id)||$id<1)
            throw new \InvalidArgumentException('指定客授权门店无效');
        foreach(['start','end'] as $key){
            $value=$range[$key]??null;
            $day=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if(!$day||$day->format('Y-m-d')!==$value)
                throw new \InvalidArgumentException('指定客业务日期无效');
        }
        if($range['start']>$range['end']||$range['start']<StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('指定客日期超出V3覆盖期');
        $services=($this->serviceReader)($stores,$range);
        if(!is_array($services)||!is_bool($services['complete']??null))
            throw new \LogicException('指定客服务来源无效');
        if(!$services['complete'])return $this->gap($stores,$services['gaps']??[]);
        $coverage=($this->legacyCoverageReader)($stores,$range);
        if(!is_array($coverage)||!is_bool($coverage['complete']??null))
            throw new \LogicException('指定客历史劳务覆盖无效');
        if(!$coverage['complete'])return $this->gap($stores,$coverage['gaps']??[]);
        // 007 对朋友服务逐条计客；V3 的“不计客”标记使通用员工指标
        // 跳过该行，故另外按同次有效点客劳务事实补算，不改写V3事实。
        $excluded=($this->eligibilityReader)($stores,$range);
        if(!is_array($excluded)||!is_bool($excluded['complete']??null)
            ||!is_array($excluded['by_store']??null))
            throw new \LogicException('朋友服务点客来源无效');
        if(!$excluded['complete'])return $this->gap($stores,$excluded['gaps']??[]);
        $metric=($this->metricReader)($stores,$range);
        if(!is_array($metric)||!is_array($metric['detail_context_by_labor_key']??null))
            throw new \LogicException('指定客劳务事实来源无效');
        $allowed=array_fill_keys($stores,true);$counts=array_fill_keys($stores,0);$seen=[];
        foreach($metric['detail_context_by_labor_key'] as $line){
            if(!is_array($line))throw new \LogicException('指定客劳务行无效');
            $id=(int)($line['store_id']??0);
            if(!isset($allowed[$id]))throw new \LogicException('指定客劳务越权');
            if(($line['point']??false)!==true)continue;
            $subject=(string)($line['subject']??'');
            $day=(string)($line['business_date']??'');
            if($subject===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day))
                throw new \LogicException('指定客客户或日期身份无效');
            if(strpos($subject,'record:')===0){++$counts[$id];continue;}
            if(strpos($subject,'member:')!==0)
                throw new \LogicException('指定客客户身份无效');
            $key=$id.'|'.$day.'|'.$subject;
            if(!isset($seen[$key])){$seen[$key]=true;++$counts[$id];}
        }
        foreach($stores as $id){
            $extra=$excluded['by_store'][$id]??null;
            if(!is_int($extra)||$extra<0)
                throw new \LogicException('朋友服务点客数量缺失');
            $counts[$id]+=$extra;
        }
        return ['by_store'=>$counts,'complete'=>true,'gaps'=>[],
            'legacy_coverage'=>$coverage,'excluded_friend_source'=>$excluded,
            'source'=>'v3_point_labor_customer_identity'];
    }

    /**
     * 历史点客记录只用于证明没有漏迁；实际人数来自V3。无V3服务身份、
     * 无对应有效点客劳动分配时整批留空，不以旧记录补数。
     */
    private function legacyCoverage(array $stores,array $range):array
    {
        $performanceTable=Db::name('cashier_v3_performance_fact')->getTable();
        if(!is_string($performanceTable)
            ||!preg_match('/^[A-Za-z0-9_]+$/D',$performanceTable))
            throw new \LogicException('点客劳务事实表名无效');
        $rows=Db::name('staff_yeji')->alias('y')
            ->leftJoin('cashier_v3_entitlement_service_fact s',
                "s.tenant_id='0' AND s.store_id=y.store_id AND s.source_document_type IN ('legacy_writeoff','legacy_paid_project') AND s.source_document_id=CAST(y.link_id AS CHAR)")
            ->leftJoin('cashier_v3_performance_fact p',
                "p.tenant_id=s.tenant_id AND p.store_id=s.store_id AND p.checkout_request_id=s.checkout_request_id AND p.source_line_id=s.source_line_id AND p.employee_id=y.staff_id AND p.performance_type='labor_performance_allocated' AND p.status='effective' AND p.fact_direction='forward' AND p.role_snapshot LIKE '%:point' AND NOT EXISTS (SELECT 1 FROM `{$performanceTable}` rv WHERE rv.tenant_id=p.tenant_id AND rv.reversal_of=p.fact_id AND rv.fact_direction='reversal' AND rv.status='effective')")
            ->whereIn('y.store_id',$stores)->where('y.type',3)
            ->where('y.is_dian',1)->where('y.status',0)
            ->field('y.id old_id,s.service_fact_id,s.business_date,p.fact_id point_fact_id')
            ->limit(50001)->select()->toArray();
        if(count($rows)>50000)return ['complete'=>false,
            'gaps'=>[['code'=>'DESIGNATED_LEGACY_SOURCE_TOO_LARGE']]];
        $gaps=[];$seen=[];
        foreach($rows as $row){
            $id=(int)($row['old_id']??0);
            if($id<1||isset($seen[$id])){
                $gaps[]=['code'=>'DESIGNATED_LEGACY_IDENTITY_AMBIGUOUS'];break;
            }
            $seen[$id]=true;
            $service=(string)($row['service_fact_id']??'');
            if($service===''){
                $gaps[]=['code'=>'DESIGNATED_LEGACY_SERVICE_MISSING'];break;
            }
            $day=(string)($row['business_date']??'');
            if($day>=$range['start']&&$day<=$range['end']
                &&(string)($row['point_fact_id']??'')===''){
                $gaps[]=['code'=>'DESIGNATED_POINT_LABOR_FACT_MISSING'];break;
            }
        }
        return ['complete'=>$gaps===[],'gaps'=>$gaps,'legacy_rows'=>count($rows)];
    }

    /** 被通用指标跳过的朋友服务按原点客劳务记录逐条补算。 */
    private function friendEligibilityCount(array $stores,array $range):array
    {
        $performanceTable=Db::name('cashier_v3_performance_fact')->getTable();
        if(!is_string($performanceTable)
            ||!preg_match('/^[A-Za-z0-9_]+$/D',$performanceTable))
            throw new \LogicException('朋友点客事实表名无效');
        $query=Db::name('cashier_v3_entitlement_service_fact')->alias('s')
            ->leftJoin('cashier_v3_performance_fact p',
                "p.tenant_id=s.tenant_id AND p.store_id=s.store_id AND p.checkout_request_id=s.checkout_request_id AND p.source_line_id=s.source_line_id AND p.performance_type='labor_performance_allocated' AND p.status='effective' AND p.fact_direction='forward' AND NOT EXISTS (SELECT 1 FROM `{$performanceTable}` rv WHERE rv.tenant_id=p.tenant_id AND rv.reversal_of=p.fact_id AND rv.fact_direction='reversal' AND rv.status='effective')")
            ->where('s.tenant_id','0')->whereIn('s.store_id',$stores)
            ->whereBetween('s.business_date',[$range['start'],$range['end']])
            ->where('s.service_status','completed')
            ->where('s.service_object','friend')->where('s.friend_counts_as_customer',0);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query,'s');
        $rows=$query->field('s.service_fact_id,s.store_id,s.labor_amount_cents,'
            .'s.craftsmen_snapshot_json,p.fact_id,p.employee_id,p.role_snapshot')
            ->limit(10001)->select()->toArray();
        if(count($rows)>10000)return ['complete'=>false,'by_store'=>[],
            'gaps'=>[['code'=>'DESIGNATED_FRIEND_SOURCE_TOO_LARGE']]];
        $byService=[];
        foreach($rows as $row){
            $key=(string)($row['service_fact_id']??'');
            if($key==='')throw new \LogicException('朋友服务事实身份无效');
            $byService[$key][]=$row;
        }
        $out=array_fill_keys($stores,0);$gaps=[];
        foreach($byService as $serviceRows){
            $first=$serviceRows[0];$store=(int)$first['store_id'];
            $hasLabor=false;$seen=[];
            foreach($serviceRows as $row){
                $factId=(string)($row['fact_id']??'');
                if($factId==='')continue;
                $hasLabor=true;
                $employee=(int)($row['employee_id']??0);
                if($employee<1||isset($seen[$employee])){
                    $gaps[]=['code'=>'DESIGNATED_FRIEND_LABOR_AMBIGUOUS'];break 2;
                }
                $seen[$employee]=true;
                if(substr((string)($row['role_snapshot']??''),-6)===':point')++$out[$store];
            }
            if($hasLabor)continue;
            if((int)($first['labor_amount_cents']??-1)!==0){
                $gaps[]=['code'=>'DESIGNATED_FRIEND_LABOR_MISSING'];break;
            }
            $snapshot=json_decode((string)($first['craftsmen_snapshot_json']??''),true);
            if(!is_array($snapshot)){
                $gaps[]=['code'=>'DESIGNATED_FRIEND_SNAPSHOT_INVALID'];break;
            }
            foreach($snapshot as $person){
                if(!is_array($person)){
                    $gaps[]=['code'=>'DESIGNATED_FRIEND_SNAPSHOT_INVALID'];break 2;
                }
                $employee=(int)($person['employee_id']??$person['employeeId']??0);
                if($employee<1||isset($seen[$employee])){
                    $gaps[]=['code'=>'DESIGNATED_FRIEND_SNAPSHOT_INVALID'];break 2;
                }
                $seen[$employee]=true;
                if(!empty($person['is_point_customer']??$person['isPointCustomer']??false))++$out[$store];
            }
        }
        return ['complete'=>$gaps===[],'by_store'=>$gaps===[]?$out:[],
            'gaps'=>$gaps];
    }

    private function gap(array $stores,array $gaps):array
    {
        return ['by_store'=>array_fill_keys($stores,null),'complete'=>false,
            'gaps'=>$gaps,'source'=>'v3_point_labor_customer_identity'];
    }
}
