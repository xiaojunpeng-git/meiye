<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 员工看板统一只读投影。
 * 员工人数/岗位来自员工主档及有效门店任职，业绩与消耗来自 V3 事实，
 * 目标复用集团看板目标事实；前端不得自行重算这些指标。
 */
final class EmployeeDashboardServices
{
    public const CONTRACT_VERSION = 'employee-dashboard-v1';
    public const METRIC_VERSION = 'employee-dashboard-facts-v1';

    /** @param int[] $stores */
    public function dashboard(array $stores, array $range, array $input = []): array
    {
        $stores = array_values(array_unique(array_filter(array_map('intval', $stores))));
        if ($stores === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        $range = $this->range($range);
        $role = trim((string)($input['role'] ?? ''));
        $staff = $this->staffRows($stores, $role);
        $overview = $this->overview($staff, $range['end']);
        $roles = $this->roles($staff, $stores);
        $performance = $this->performance($stores, $range);
        $target = $this->target($stores, $range, $performance);
        $scopeData = $this->scopeSummary($stores, $performance);
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'metric_version' => self::METRIC_VERSION,
            'data_as_of' => date('Y-m-d H:i:s'),
            'aggregation_caught_up' => true,
            'aggregation_status' => '已读取统一员工、销售、服务和目标事实。',
            'scope' => ['store_ids' => $stores, 'date_range' => $range, 'role' => $role ?: '全部岗位'],
            'overview' => $overview,
            'roles' => $roles,
            'performance' => $performance,
            'target' => $target,
            'scopes' => $scopeData,
            'source_explanations' => [
                'overview' => '在职且启用的员工档案及有效门店任职关系；年龄和工龄按查询截止日计算。',
                'roles' => '当前有效岗位任职和员工美容师能力开关；编制读取集团看板已保存的门店月度目标外的员工编制配置。',
                'performance' => '成功销售分配、完成服务和消耗业绩事实；退款/作废按反向事实净额处理。',
                'target' => '集团管理看板按门店、月份保存的目标事实；未配置目标显示“-”。',
            ],
        ];
    }

    private function range(array $range): array
    {
        $start = (string)($range['start'] ?? date('Y-m-01'));
        $end = (string)($range['end'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end) {
            throw new \InvalidArgumentException('统计日期范围不正确');
        }
        return ['start' => $start, 'end' => $end];
    }

    /** @return array<int,array<string,mixed>> */
    private function staffRows(array $stores, string $role): array
    {
        $q = Db::name('system_store_staff')->alias('ss')->join('employee e', 'e.id=ss.employee_id')
            ->leftJoin('staff_job_position jp', 'jp.staff_id=ss.id AND jp.is_del=0 AND jp.status=1 AND jp.end_time=0')
            ->leftJoin('position p', 'p.id=jp.position_id AND p.status=1')
            ->whereIn('ss.store_id', $stores)->where('ss.status', 1)->where('ss.is_del', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->field('ss.id staff_id,MAX(ss.store_id) store_id,MAX(ss.employee_id) employee_id,MAX(ss.cashier_craftsman_enabled) cashier_craftsman_enabled,MAX(e.name) name,MAX(ss.age) age,MAX(ss.birthday_date) birthday,MAX(ss.join_date) entry_time,MAX(p.name) position_name')
            ->group('ss.id')->order('ss.id', 'asc');
        if ($role !== '' && $role !== '全部岗位') $q->whereLike('p.name', '%' . $role . '%');
        return $q->select()->toArray();
    }

    private function overview(array $staff, string $end): array
    {
        $unique = [];
        foreach ($staff as $row) {
            $id = (int)($row['employee_id'] ?? 0);
            if ($id > 0 && !isset($unique[$id])) $unique[$id] = $row;
        }
        $staff = array_values($unique ?: $staff);
        $now = strtotime($end . ' 23:59:59') ?: time();
        $total = count($staff); $ages = ['18-25岁'=>0,'26-35岁'=>0,'36-45岁'=>0,'46-55岁'=>0,'56岁以上'=>0]; $tenure = ['1年以内'=>0,'1-3年'=>0,'3-5年'=>0,'5-10年'=>0,'10年以上'=>0]; $edu = ['本科'=>0,'大专'=>0,'高中及以下'=>0,'其他'=>0];
        $ageSum = 0; $tenureSum = 0;
        foreach ($staff as $row) {
            $birthday = $this->timestamp($row['birthday'] ?? 0); $storedAge = (int)($row['age'] ?? 0); if ($storedAge > 0) { $age = $storedAge; $bucket = $age<=25?'18-25岁':($age<=35?'26-35岁':($age<=45?'36-45岁':($age<=55?'46-55岁':'56岁以上'))); $ages[$bucket]++; $ageSum += $age; } elseif ($birthday > 0) { $age = max(0, (int)date('Y',$now)-(int)date('Y',$birthday) - (date('md',$now)<date('md',$birthday)?1:0)); if ($age < 18) $age = 18; $bucket = $age<=25?'18-25岁':($age<=35?'26-35岁':($age<=45?'36-45岁':($age<=55?'46-55岁':'56岁以上'))); $ages[$bucket]++; $ageSum += $age; }
            $entry = $this->timestamp($row['entry_time'] ?? 0); if ($entry > 0) { $years = max(0, ($now-$entry)/31557600); $bucket = $years<1?'1年以内':($years<3?'1-3年':($years<5?'3-5年':($years<10?'5-10年':'10年以上'))); $tenure[$bucket]++; $tenureSum += $years; }
            $education = trim((string)($row['education'] ?? '')); $edu[$education !== '' && isset($edu[$education]) ? $education : (str_contains($education,'本')?'本科':(str_contains($education,'专')?'大专':'其他'))]++;
        }
        return ['cards' => [
            $this->card('total','员工总人数',$total,'人','在职且启用员工档案统计','blue'), $this->card('average_age','平均年龄',$total?round($ageSum/$total,1):null,'岁','有生日资料员工按查询截止日计算','green'), $this->card('average_tenure','平均工龄',$total?round($tenureSum/$total,1):null,'年','有入职日期员工按查询截止日计算','teal'), $this->card('active_staff','在职人数',$total,'人','当前权限范围内有效门店任职统计','purple'), $this->card('beautician_count', '美容师人数', count(array_filter($staff, fn($r)=>(int)($r['cashier_craftsman_enabled']??0)===1)), '人','启用美容师能力的有效任职统计','amber'), $this->card('departures','期间流失人数',0,'人','员工离职事实接入后按离职日期统计','red')], 'education'=>$this->rows($edu), 'ages'=>$this->rows($ages), 'tenure'=>$this->rows($tenure)];
    }

    private function roles(array $staff, array $stores): array
    {
        $counts=[]; foreach($staff as $row){$name=trim((string)($row['position_name']??'')); if($name==='')$name=((int)($row['cashier_craftsman_enabled']??0)===1?'美容师':'未设置岗位'); $counts[$name]=($counts[$name]??0)+1;}
        $beauticianQuota = 0;
        try { $beauticianQuota = (int)Db::name('cashier_v3_report_beautician_establishment')->whereIn('store_id',$stores)->sum('establishment_count'); } catch (\Throwable $e) { $beauticianQuota = 0; }
        $colors=['#3984ad','#3f9c92','#d29b45','#7a6ca8','#bb6d57']; $i=0; $out=[]; foreach($counts as $label=>$value){$quota=(str_contains($label,'美容师')&&$beauticianQuota>0)?$beauticianQuota:$value; $out[]=['label'=>$label,'value'=>$value,'quota'=>$quota,'color'=>$colors[$i++%count($colors)]];} return $out;
    }

    private function performance(array $stores, array $range): array
    {
        $cash = $this->sumPerformance($stores,$range,'sales_performance_allocated'); $consume = $this->sumPerformance($stores,$range,'consumption_performance_recorded'); $services = (int)Db::name('cashier_v3_entitlement_service_fact')->whereIn('store_id',$stores)->whereBetween('business_date',[$range['start'],$range['end']])->where('service_status','completed')->count(); $customers = (int)Db::name('cashier_v3_entitlement_service_fact')->whereIn('store_id',$stores)->whereBetween('business_date',[$range['start'],$range['end']])->where('service_status','completed')->where('member_id','>',0)->distinct(true)->count('member_id');
        $trend=[]; $cursor=date('Y-m-01',strtotime($range['start'])); $endMonth=date('Y-m-01',strtotime($range['end'])); while($cursor<=$endMonth){$mStart=$cursor;$mEnd=date('Y-m-t',strtotime($cursor));$v=$this->sumPerformance($stores,['start'=>max($range['start'],$mStart),'end'=>min($range['end'],$mEnd)],'sales_performance_allocated');$trend[]=$v/100;$cursor=date('Y-m-01',strtotime($cursor.' +1 month'));}
        $branches=[]; foreach(Db::name('system_store')->whereIn('id',$stores)->column('name','id') as $sid=>$name){$v=$this->sumPerformance([(int)$sid],$range,'sales_performance_allocated');$n=(int)Db::name('system_store_staff')->where('store_id',(int)$sid)->where('status',1)->where('is_del',0)->count();$branches[]=['label'=>(string)$name,'value'=>$v/100,'staff'=>$n,'color'=>'#3984ad'];}
        return ['cards'=>[$this->card('performance_total','业绩总额',$cash/100,'元','有效销售业绩分配事实净额','blue'),$this->card('active_customers','活客数',$customers,'人','期间完成有效服务的去重会员','green'),$this->card('service_count','服务人次',$services,'次','期间完成服务事实数量','teal'),$this->card('consumption_performance','消耗业绩',$consume/100,'元','项目完成服务后的消耗业绩事实','amber')], 'trend'=>$trend, 'trendLabels'=>array_map(fn($v)=>substr($v,5).'月',array_filter($this->months($range))), 'categories'=>[['label'=>'现金业绩','value'=>$cash/100,'percent'=>$cash?100:0,'color'=>'#3984ad'],['label'=>'消耗业绩','value'=>$consume/100,'percent'=>$cash?round($consume*100/$cash,1):0,'color'=>'#3f9c92']], 'branches'=>$branches];
    }

    private function target(array $stores,array $range,array $performance): array
    {
        $year=(int)substr($range['end'],0,4);$month=(int)substr($range['end'],5,2);$targets=[];try{$targets=(new GroupManagementDashboardTargetServices())->totals(['tenant_id'=>'0','store_ids'=>$stores],$year,[$month]);}catch(\Throwable $e){$targets=[];}$consumption=$this->sumPerformance($stores,$range,'consumption_performance_recorded');$target=array_sum($targets);$branches=[];foreach(Db::name('system_store')->whereIn('id',$stores)->column('name','id') as $sid=>$name){$t=(int)($targets[(int)$sid]??0);$c=$this->sumPerformance([(int)$sid],$range,'consumption_performance_recorded');$branches[]=['label'=>(string)$name,'consumption'=>$c/100,'target'=>$t/100,'completion'=>$t?round($c*100/$t,1):null];}
        $trend=[];$trendLabels=[];$cursor=date('Y-m-01',strtotime($range['start']));$endMonth=date('Y-m-01',strtotime($range['end']));while($cursor<=$endMonth){$mStart=$cursor;$mEnd=date('Y-m-t',strtotime($cursor));$mRange=['start'=>max($range['start'],$mStart),'end'=>min($range['end'],$mEnd)];$mTarget=0;try{$mTarget=array_sum((new GroupManagementDashboardTargetServices())->totals(['tenant_id'=>'0','store_ids'=>$stores],(int)substr($cursor,0,4),[(int)substr($cursor,5,2)]));}catch(\Throwable $e){}$mConsume=$this->sumPerformance($stores,$mRange,'consumption_performance_recorded');$trend[]=$mTarget?round($mConsume*100/$mTarget,1):null;$trendLabels[]=substr($cursor,5).'月';$cursor=date('Y-m-01',strtotime($cursor.' +1 month'));}
        return ['cards'=>[$this->card('consumption_amount','消耗业绩',$consumption/100,'元','项目完成服务后的消耗业绩事实','teal'),$this->card('consumption_ratio','消耗/销售比例',$performance['cards'][0]['value']?round($consumption/100*100/$performance['cards'][0]['value'],1):null,'%','消耗业绩除以销售业绩，分母为零显示“-”','green'),$this->card('target_amount','业绩目标',$target/100,'元','集团管理看板门店月度目标事实','blue'),$this->card('target_completion','目标完成率',$target?round($consumption*100/$target,1):null,'%','消耗业绩除以目标，未配置目标显示“-”','amber')], 'trend'=>$trend, 'trendLabels'=>$trendLabels, 'branches'=>$branches];
    }

    private function scopeSummary(array $stores,array $performance): array { $amount=(float)($performance['cards'][0]['value']??0);$cons=(float)($performance['cards'][3]['value']??0);return ['总部'=>['total'=>(int)Db::name('system_store_staff')->whereIn('store_id',$stores)->where('status',1)->where('is_del',0)->count(),'amount'=>$amount,'consumption'=>$cons]]; }
    private function sumPerformance(array $stores,array $range,string $type): int { $row=Db::name('cashier_v3_performance_fact')->whereIn('store_id',$stores)->whereBetween('business_date',[$range['start'],$range['end']])->where('status','effective')->where('performance_type',$type)->fieldRaw('COALESCE(SUM(amount_cents),0) amount')->find();return (int)($row['amount']??0); }
    private function card(string $key,string $label,$value,string $unit,string $source,string $tone): array { return ['key'=>$key,'label'=>$label,'value'=>$value,'unit'=>$unit,'source'=>$source,'tone'=>$tone]; }
    private function rows(array $values): array { $colors=['#3984ad','#3f9c92','#d29b45','#7a6ca8','#bb6d57'];$i=0;$out=[];foreach($values as $label=>$value)$out[]=['label'=>$label,'value'=>(int)$value,'color'=>$colors[$i++%count($colors)]];return $out; }
    private function months(array $range): array { $out=[];$cursor=date('Y-m-01',strtotime($range['start']));$end=date('Y-m-01',strtotime($range['end']));while($cursor<=$end){$out[]=$cursor;$cursor=date('Y-m-01',strtotime($cursor.' +1 month'));}return $out; }
    private function timestamp($value): int { if(is_numeric($value)){ $v=(int)$value; return $v>1000000000?$v:0; } return strtotime((string)$value)?:0; }
}
