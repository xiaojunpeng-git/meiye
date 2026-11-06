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
    public const METRIC_VERSION = 'employee-dashboard-actual-position-v2';

    /** @param int[] $stores */
    public function dashboard(array $stores, array $range, array $input = []): array
    {
        $stores = array_values(array_unique(array_filter(array_map('intval', $stores))));
        if ($stores === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        $range = $this->range($range);
        $role = trim((string)($input['role'] ?? ''));
        $population = new EmployeeDashboardPopulationServices();
        $allStaff = $this->authorizedPeople($population->active($stores, '', $range['end']), $input);
        $staff = $role === '' || $role === '全部岗位' ? $allStaff : array_values(array_filter($allStaff, fn($r)=>in_array($role,$r['positions'],true)));
        $overview = $this->overview($staff, $range['end'], count($this->authorizedPeople($population->departures($stores, $range, $role), $input)));
        $roles = $this->roles($staff, $stores, $role);
        $performance = $this->performance($stores, $range);
        $target = $this->target($stores, $range, $performance);
        $scopeData = $this->scopeSummary($stores, $performance);
        $result = [
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
                'roles' => '当前有效实际岗位任职；兼岗人员分别计入各岗位；编制读取集团看板已保存的门店月度目标外的员工编制配置。',
                'performance' => '成功销售分配、完成服务和消耗业绩事实；退款/作废按反向事实净额处理。',
                'target' => '门店目标报表当前引用的有效方案；目标与完成值同为全店实际业绩，未配置显示“-”。',
            ],
        ];
        // 每个下钻描述均冻结本次已执行条件；参数在明细入口重新经过后端权限裁剪。
        $filters=['store_ids'=>implode(',',$stores),'start_date'=>$range['start'],'end_date'=>$range['end'],'role'=>$role];
        foreach($result['overview']['cards']as&$card)if(in_array($card['key'],['total','active_staff','beautician_count','departures'],true))$card['drilldown']=$filters+['kind'=>$card['key']];unset($card);
        foreach(['education'=>'education','ages'=>'age','tenure'=>'tenure']as$key=>$kind)foreach($result['overview'][$key]as$i=>&$row)$row['drilldown']=$filters+['kind'=>$kind,'bucket'=>(string)$i];unset($row);
        foreach($result['roles']as&$row)$row['drilldown']=$filters+['kind'=>'position','bucket'=>(string)$row['position_id']];unset($row);
        $roleNames=[];foreach($allStaff as$row)foreach($row['positions']as$name)$roleNames[$name]=$name;sort($roleNames,SORT_STRING);$result['role_options']=array_values($roleNames);
        return $result;
    }

    /** 仅使用控制器注入的认证员工ID；个人权限与汇总、分页、导出共用同一限制。 */
    private function authorizedPeople(array $people, array $input): array
    {
        $employeeId = (int)($input['_employee_id'] ?? 0);
        return $employeeId > 0 ? array_values(array_filter($people, fn($row) => (int)$row['employee_id'] === $employeeId)) : $people;
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

    /** 汇总与明细共用人口分组，不从手艺人资格推断岗位。 */
    private function overview(array $staff,string $end,int $departureCount=0):array
    {
        $population=new EmployeeDashboardPopulationServices();$edu=array_fill_keys(EmployeeDashboardPopulationServices::EDUCATION,0);$ages=array_fill_keys(EmployeeDashboardPopulationServices::AGES,0);$tenure=array_fill_keys(EmployeeDashboardPopulationServices::TENURE,0);
        $ageSum=0;$ageCount=0;$tenureSum=0;$tenureCount=0;
        foreach($staff as$row){$p=$row['profile'];$edu[EmployeeDashboardPopulationServices::EDUCATION[$p['education_bucket']]]++;if($p['age']!==null){$ages[EmployeeDashboardPopulationServices::AGES[$p['age_bucket']]]++;$ageSum+=$p['age'];$ageCount++;}if($p['years']!==null){$tenure[EmployeeDashboardPopulationServices::TENURE[$p['tenure_bucket']]]++;$tenureSum+=$p['years'];$tenureCount++;}}
        $total=count($staff);
        return ['cards'=>[
            $this->card('total','员工总人数',$total,'人','当前在职且启用员工，跨店任职按员工去重','blue'),
            $this->card('average_age','平均年龄',$ageCount?round($ageSum/$ageCount,1):null,'岁','仅按已填写年龄或生日的员工计算','green'),
            $this->card('average_tenure','平均工龄',$tenureCount?round($tenureSum/$tenureCount,1):null,'年','仅按已填写入职日期的员工计算','teal'),
            $this->card('active_staff','在职人数',$total,'人','当前权限范围内有效任职，按员工去重','purple'),
            $this->card('beautician_count','美容师人数',count($population->select($staff,'beautician_count')),'人','当前有效岗位名称为美容师的员工，不按手艺人资格统计','amber'),
            $this->card('departures','期间流失人数',$departureCount,'人','期间发生离职的员工，调店和停职不计入','red')],
            'education'=>$this->rows($edu),'ages'=>$this->rows($ages),'tenure'=>$this->rows($tenure)];
    }

    /** 每个实际岗位内按员工去重；兼任多个岗位可分别列入各岗位，不伪造“美容师”岗位。 */
    private function roles(array $staff,array $stores,string $role):array
    {
        $groups=[];foreach($staff as$row){$positions=$row['positions']?:[0=>'未设置岗位'];foreach($positions as$id=>$name){if($role!==''&&$role!=='全部岗位'&&$name!==$role)continue;if(!isset($groups[$id]))$groups[$id]=['position_id'=>$id,'label'=>$name,'value'=>0];$groups[$id]['value']++;}}
        $quotas=[];foreach(Db::name('staffing_quota')->where('tenant_id','0')->where('scope_type','store')->whereIn('scope_id',$stores)->select()->toArray()as$row)$quotas[(int)$row['position_id']]=($quotas[(int)$row['position_id']]??0)+(int)$row['quota_count'];
        ksort($groups);$colors=['#3984ad','#3f9c92','#d29b45','#7a6ca8','#bb6d57'];$out=[];foreach($groups as$id=>$row)$out[]=$row+['quota'=>$quotas[$id]??$row['value'],'color'=>$colors[count($out)%count($colors)]];return $out;
    }

    /** 人员管理的报表明细模式；只查询当前页，完整命中总数与导出使用同一人群选择器。 */
    public function people(array $stores,array $range,array $input):array
    {
        $range=$this->range($range);$role=trim((string)($input['role']??''));$kind=(string)($input['kind']??'');$bucket=(string)($input['bucket']??'');$population=new EmployeeDashboardPopulationServices();
        $people=$kind==='departures'?$population->departures($stores,$range,$role):$population->active($stores,$role,$range['end']);
        $people=$population->select($this->authorizedPeople($people,$input),$kind,$bucket);$keyword=trim((string)($input['keyword']??''));if($keyword!=='')$people=array_values(array_filter($people,fn($r)=>mb_stripos((string)$r['name'],$keyword)!==false||(string)$r['employee_id']===$keyword));
        usort($people,fn($a,$b)=>(int)$a['employee_id']<=>(int)$b['employee_id']);$count=count($people);$limit=max(1,min(200,(int)($input['limit']??20)));$pages=max(1,(int)ceil($count/$limit));$page=max(1,min($pages,(int)($input['page']??1)));
        $rows=[];foreach(array_slice($people,($page-1)*$limit,$limit)as$r)$rows[]=['id'=>(int)$r['staff_id'],'employee_id'=>(int)$r['employee_id'],'name'=>$r['name']?:'历史人员档案缺失','store_names'=>implode('、',$r['stores']),'position_names'=>implode('、',array_unique($r['positions']))?:'未设置岗位','age'=>$r['profile']['age'],'education'=>EmployeeDashboardPopulationServices::EDUCATION[$r['profile']['education_bucket']],'entry_time'=>(string)$r['entry_time'],'tenure_years'=>$r['profile']['years']===null?null:round($r['profile']['years'],1),'departed_at'=>isset($r['end_time'])?date('Y-m-d H:i:s',(int)$r['end_time']):''];
        return ['list'=>$rows,'count'=>$count,'page'=>$page,'limit'=>$limit,'date_range'=>$range,'kind'=>$kind,'role'=>$role,'columns'=>[['key'=>'employee_id','title'=>'员工ID'],['key'=>'name','title'=>'姓名'],['key'=>'store_names','title'=>'统计门店'],['key'=>'position_names','title'=>$kind==='departures'?'期间离职岗位':'当前实际岗位'],['key'=>'age','title'=>'年龄'],['key'=>'education','title'=>'学历'],['key'=>'entry_time','title'=>'入职日期'],['key'=>'tenure_years','title'=>'工龄（年）'],['key'=>'departed_at','title'=>'期间离职时间']]];
    }

    private function performance(array $stores, array $range): array
    {
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $cash = $reader->summary('staff_sales_yeji','0',$stores,$range); $consume = $reader->summary('consume_amount','0',$stores,$range);
        $services = $reader->summary('completed_service_item_count','0',$stores,$range);
        $customers = $reader->summary('customer_active','0',$stores,$range);
        $trend=[]; $cursor=date('Y-m-01',strtotime($range['start'])); $endMonth=date('Y-m-01',strtotime($range['end'])); while($cursor<=$endMonth){$mStart=$cursor;$mEnd=date('Y-m-t',strtotime($cursor));$v=$this->sumPerformance($stores,['start'=>max($range['start'],$mStart),'end'=>min($range['end'],$mEnd)],'staff_sales_yeji');$trend[]=$v/100;$cursor=date('Y-m-01',strtotime($cursor.' +1 month'));}
        $branches=[]; foreach(Db::name('system_store')->whereIn('id',$stores)->column('name','id') as $sid=>$name){$v=$this->sumPerformance([(int)$sid],$range,'staff_sales_yeji');$n=(int)Db::name('system_store_staff')->where('store_id',(int)$sid)->where('status',1)->where('is_del',0)->count();$branches[]=['label'=>(string)$name,'value'=>$v/100,'staff'=>$n,'color'=>'#3984ad'];}
        $dictionary = new \app\services\metric\MetricDictionaryServices();
        $salesDefinition = $dictionary->getTooltip('staff_sales_yeji');
        $consumeDefinition = $dictionary->getTooltip('consume_amount');
        return ['cards'=>[$this->registeredCard('performance_total','staff_sales_yeji',$cash/100,'元','blue','业绩总额',$cash),$this->registeredCard('active_customers','customer_active',$customers,'人','green','',null),$this->registeredCard('service_count','completed_service_item_count',$services,'次','teal','',null),$this->registeredCard('consumption_performance','consume_amount',$consume/100,'元','amber','',$consume)], 'trend'=>$trend, 'trendLabels'=>array_map(fn($v)=>substr($v,5).'月',array_filter($this->months($range))), 'categories'=>[['label'=>(string)$salesDefinition['name'],'value'=>$cash/100,'value_cents'=>$cash,'percent'=>$cash?100:0,'color'=>'#3984ad'],['label'=>(string)$consumeDefinition['name'],'value'=>$consume/100,'value_cents'=>$consume,'percent'=>$cash?round($consume*100/$cash,1):0,'color'=>'#3f9c92']], 'branches'=>$branches];
    }

    private function target(array $stores,array $range,array $performance): array
    {
        $month = substr($range['end'], 0, 7);
        $schemes = new \app\services\target\TargetSchemeServices();
        $context = ['tenant_id' => '0', 'store_ids' => $stores];
        $effective = $schemes->effective($context, 'store_target', [$month]);
        $targets = [];
        foreach ($effective['lines'] as $line) {
            if ($line['metric_code'] === 'actual_performance' && $line['scope_type'] === 'store')
                $targets[(int)$line['store_id']] = (int)$line['target_value'];
        }
        $coveredStores = array_keys($targets);
        $actual = $coveredStores ? $this->sumPerformance($coveredStores, $range, 'actual_performance') : 0;
        $target = array_sum($targets);
        $consumption = $this->sumPerformance($stores, $range, 'consume_amount');
        $branches = [];
        foreach (Db::name('system_store')->whereIn('id', $stores)->column('name', 'id') as $sid => $name) {
            $t = (int)($targets[(int)$sid] ?? 0);
            $value = $t ? $this->sumPerformance([(int)$sid], $range, 'actual_performance') : 0;
            $branches[] = ['label' => (string)$name, 'consumption' => $value / 100,
                'target' => $t / 100, 'completion' => $t ? round($value * 100 / $t, 1) : null];
        }
        $trend = []; $trendLabels = [];
        $cursor = date('Y-m-01', strtotime($range['start']));
        $endMonth = date('Y-m-01', strtotime($range['end']));
        while ($cursor <= $endMonth) {
            $monthKey = substr($cursor, 0, 7);
            $lines = $schemes->effective($context, 'store_target', [$monthKey])['lines'];
            $monthTargets = [];
            foreach ($lines as $line) if ($line['metric_code'] === 'actual_performance' && $line['scope_type'] === 'store')
                $monthTargets[(int)$line['store_id']] = (int)$line['target_value'];
            $monthTarget = array_sum($monthTargets);
            $monthActual = $monthTargets ? $this->sumPerformance(array_keys($monthTargets), [
                'start' => max($range['start'], $cursor), 'end' => min($range['end'], date('Y-m-t', strtotime($cursor))),
            ], 'actual_performance') : 0;
            $trend[] = $monthTarget ? round($monthActual * 100 / $monthTarget, 1) : null;
            $trendLabels[] = substr($cursor, 5) . '月';
            $cursor = date('Y-m-01', strtotime($cursor . ' +1 month'));
        }
        // 消耗卡保持自身指标；只有目标卡使用实际业绩的同口径分子与分母。
        return ['cards' => [
            $this->registeredCard('consumption_amount', 'consume_amount', $consumption / 100, '元', 'teal'),
            $this->card('consumption_ratio', '消耗/销售比例', $performance['cards'][0]['value']
                ? round($consumption / $performance['cards'][0]['value'], 1) : null,
                '%', '消耗业绩除以销售业绩，分母为零显示“-”', 'green'),
            $this->card('target_amount', '实际业绩目标', $target / 100, '元', '门店目标有效方案', 'blue'),
            $this->card('target_completion', '目标完成率', $target ? round($actual * 100 / $target, 1) : null,
                '%', '仅统计有目标的门店，同口径实际业绩除以目标', 'amber'),
        ], 'trend' => $trend, 'trendLabels' => $trendLabels, 'branches' => $branches];
    }

    private function scopeSummary(array $stores,array $performance): array { $amount=(float)($performance['cards'][0]['value']??0);$cons=(float)($performance['cards'][3]['value']??0);return ['总部'=>['total'=>(int)Db::name('system_store_staff')->whereIn('store_id',$stores)->where('status',1)->where('is_del',0)->count(),'amount'=>$amount,'consumption'=>$cons]]; }
    private function sumPerformance(array $stores,array $range,string $metric): int { return (new \app\services\query\metric\RegisteredMetricReadServices())->summary($metric,'0',$stores,$range); }
    private function card(string $key,string $label,$value,string $unit,string $source,string $tone): array { return ['key'=>$key,'label'=>$label,'value'=>$value,'unit'=>$unit,'source'=>$source,'tone'=>$tone]; }
    private function registeredCard(string $key,string $metricCode,$value,string $unit,string $tone,string $contextLabel='',?int $valueCents=null): array { $definition=(new \app\services\metric\MetricDictionaryServices())->getTooltip($metricCode);if(($definition['user_ready']??false)!==true)throw new \RuntimeException('EMPLOYEE_METRIC_DICTIONARY_NOT_READY');$card=$this->card($key,$contextLabel!==''?$contextLabel:(string)$definition['name'],$value,$unit,(string)$definition['summary'],$tone);if($valueCents!==null)$card['value_cents']=$valueCents;return $card; }
    private function rows(array $values): array { $colors=['#3984ad','#3f9c92','#d29b45','#7a6ca8','#bb6d57','#6283a1'];$i=0;$out=[];foreach($values as $label=>$value)$out[]=['label'=>$label,'value'=>(int)$value,'color'=>$colors[$i++%count($colors)]];return $out; }
    private function months(array $range): array { $out=[];$cursor=date('Y-m-01',strtotime($range['start']));$end=date('Y-m-01',strtotime($range['end']));while($cursor<=$end){$out[]=$cursor;$cursor=date('Y-m-01',strtotime($cursor.' +1 month'));}return $out; }
}
