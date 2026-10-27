<?php
declare(strict_types=1);
namespace app\services\payroll;

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\StoreSalespersonNetReadServices;
use think\facade\Db;

/**
 * 平台工资、员工统计与手机共用的只读项目提成投影。
 * 调用者先授权门店/员工；每店每人按完整自然月净销售落档，查询日期只筛展示。
 * 使用查询内缓存批量读取，缺配置返回 null+缺口，绝不回退到现有手工费。
 */
final class ProjectLaborCommissionReadServices
{
    private $reader;
    private $calculator;
    public function __construct($reader=null)
    {
        $this->reader=$reader ?? new RegisteredMetricReadServices(null,null,null,null,true);
        $this->calculator=new ProjectLaborCommissionCalculator();
    }

    /** 返回明细及员工门店合计；生效事实的筛选完全复用已注册 Reader。 */
    public function read(array $stores,array $range,array $employees=[]): array
    {
        $facts=$this->reader->personnelDetailResult('staff_labor_yeji','0',$stores,$range,$employees,0,true)['rows'];
        if (!$facts) return ['details'=>[],'totals'=>[],'complete'=>true];
        $metadata=[];
        foreach (array_chunk(array_column($facts,'fact_id'),300) as $ids) {
            $rows=Db::name('cashier_v3_performance_fact')->alias('p')
                ->leftJoin('cashier_v3_entitlement_service_fact s','s.tenant_id=p.tenant_id AND s.store_id=p.store_id AND s.checkout_request_id=p.checkout_request_id AND s.source_line_id=p.source_line_id')
                ->leftJoin('store_product product','product.id=s.project_id')
                ->leftJoin('store_product platform','platform.id=product.pid AND platform.type=0 AND platform.relation_id=0')
                ->where('p.tenant_id','0')->whereIn('p.store_id',$stores)->whereIn('p.fact_id',$ids)
                ->field('p.fact_id,p.employee_type_snapshot,s.project_id,product.id product_id,CASE WHEN product.type=0 AND product.relation_id=0 THEN product.id WHEN product.type=1 AND product.relation_id>0 THEN platform.id ELSE NULL END platform_id,s.project_name_snapshot')->select()->toArray();
            foreach ($rows as $row) {
                $id=(string)$row['fact_id'];
                if (isset($metadata[$id])) $metadata[$id]['ambiguous']=true;
                else $metadata[$id]=$row;
            }
        }
        $projectIds=[];
        foreach ($metadata as $meta) if ((int)($meta['product_id'] ?? 0)>0)
            $projectIds[]=(int)($meta['platform_id'] ?? 0);
        $configs=[];
        if ($projectIds) foreach (Db::name('yeji_commission')->whereIn('product_id',array_unique($projectIds))->field('id,product_id,commission_json')->select()->toArray() as $row)
            $configs[(int)$row['product_id']][]=$row;
        $thresholdEmployees=array_values(array_unique(array_map('intval',array_column($facts,'employee_id'))));
        $monthly=[]; $details=[]; $totals=[]; $complete=true;
        foreach ($facts as $fact) {
            $id=(string)$fact['fact_id']; $pair=$fact['store_id'].':'.$fact['employee_id'];
            $month=substr((string)$fact['business_date'],0,7);
            if (!isset($monthly[$month])) {
                $start=$month.'-01'; $end=(new \DateTimeImmutable($start,new \DateTimeZone('Asia/Shanghai')))->format('Y-m-t');
                $monthly[$month]=[];
                foreach ((new StoreSalespersonNetReadServices())->matrix($stores,['start'=>$start,'end'=>$end],$thresholdEmployees)['records'] as $sale)
                    $monthly[$month][$sale['store_id'].':'.$sale['employee_id']]=(int)$sale['total_metric_value'];
            }
            if (!isset($totals[$pair])) $totals[$pair]=['amount_cents'=>0,'basis_cents'=>0,'complete'=>true,'gaps'=>[],'details'=>[]];
            $meta=$metadata[$id] ?? []; $project=(int)($meta['platform_id'] ?? 0);
            $detail=['fact_id'=>$id,'business_date'=>$fact['business_date'],'project_id'=>$project,
                'project_name'=>(string)($meta['project_name_snapshot'] ?? ''),'month'=>$month,'status'=>'预计','amount_cents'=>null];
            try {
                if (!empty($meta['ambiguous'])) throw new \DomainException('同一劳动事实对应多个项目，需核对来源');
                // employee_type_snapshot 是内外部用工类型，不是提成/手工资格；不能混用。
                if ((int)$fact['metric_value'] !== 0 && ($project<1 || count($configs[$project] ?? [])!==1))
                    throw new \DomainException('项目提成配置缺失或重复，项目 '.$project);
                $configuration=$configs[$project][0] ?? ['id'=>0,'commission_json'=>'{}'];
                $json=json_decode((string)$configuration['commission_json'],true);
                if (!is_array($json)) throw new \DomainException('项目提成配置格式无效');
                $detail=array_replace($detail,$this->calculator->calculate($fact,$json,$monthly[$month][$pair] ?? 0),['rule_id'=>(int)$configuration['id']]);
                $totals[$pair]['amount_cents']+=$detail['amount_cents'];
                $totals[$pair]['basis_cents']+=(int)$fact['metric_value'];
            } catch (\DomainException $error) {
                $detail['status']='待核对';$detail['message']=$error->getMessage();
                $totals[$pair]['complete']=false;$totals[$pair]['gaps'][]=$detail['message'];$complete=false;
            }
            $detail['display_basis']=\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$fact['metric_value']);
            $detail['display_threshold']=\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)($detail['threshold_cents'] ?? ($monthly[$month][$pair] ?? 0)));
            $detail['display_commission']=$detail['amount_cents']===null ? '待核对' : \app\services\query\metric\MetricMoneyFormatter::integerYuan($detail['amount_cents']);
            $details[$id]=$detail;$totals[$pair]['details'][]=$detail;
        }
        foreach ($totals as &$total) if (!$total['complete']) $total['amount_cents']=null;
        unset($total);
        return ['details'=>$details,'totals'=>$totals,'complete'=>$complete];
    }
}
