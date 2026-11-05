<?php

declare(strict_types=1);
namespace app\services\report;

use think\facade\Db;

/** 人效看板只读聚合。汇总、排行、明细和导出共用本次授权范围内的完整事实集合。 */
final class HumanEfficiencyDashboardServices
{
    private $categories = [];
    private $categoryRoots = [];
    private $retainFacts = false;
    private $detailCollector;

    /** 调用方必须先按后台身份裁剪门店；这里不接受前端的金额、排名或组织路径。 */
    public function read(array $stores, array $range, array $input = []): array
    {
        // 行明细只能缩小已经授权的门店范围；前端行键绝不能扩大范围。
        if(($input['action']??'')==='detail' && preg_match('/^(\d+):\d+$/',(string)($input['entity_id']??''),$match)){
            $store=(int)$match[1];if(!in_array($store,$stores,true))throw new \InvalidArgumentException('该人员不在当前查询范围');
            $stores=[$store];
        }
        $this->retainFacts = ($input['action'] ?? '') === 'detail';
        $reader = new HumanEfficiencyFactReader();
        $staff = $reader->staff($stores);
        $metadata = (new HumanEfficiencyMetadataServices())->metadata();
        $this->categories = $metadata['categories'];
        $this->categoryRoots = $this->categoryRootMap();
        $this->detailCollector=$this->retainFacts?new HumanEfficiencyDetailCollector($input,$this->categoryRoots,$this->categories):null;
        $end = strtotime($range['end'] . ' 23:59:59');
        $roster = $this->roster($staff, $end);
        $selfEmployee=max(0,(int)($input['_employee_id']??0));
        if($selfEmployee)$roster=array_filter($roster,static fn(array $r):bool=>$r['employee_id']===$selfEmployee);
        // 分门店读取共享事实，避免集团跨月查询一次加载所有宽事实而耗尽工作进程内存。
        $entities = [];
        foreach ($roster as $key => $person) $entities[$key] = $this->emptyEntity($person);
        foreach (array_chunk($stores,4) as $scopeChunk) {
        foreach ((new StoreUnifiedReportPhaseSixServices())->humanEfficiencyFacts($scopeChunk, $range, $this->retainFacts) as $fact) {
            if($selfEmployee && (int)$fact['employee_id']!==$selfEmployee)continue;
            // 现金业绩沿用正向销售分配；退款净额属于实际业绩，不混入本看板现金口径。
            if (in_array($fact['source_type'] ?? '', ['manual_refund','imported_card_refund'], true)) continue;
            $key = (int)$fact['store_id'] . ':' . (int)$fact['employee_id'];
            if (!isset($entities[$key])) {
                // 已离职员工的业务仍保留；最近一次历史岗位仅用于归类，绝不改变分配事实。
                $person = $this->historicalPerson($staff, $fact, $end);
                $entities[$key] = $this->emptyEntity($person);
            }
            $this->append($entities[$key], $fact);
        }
        }
        $storeNames = array_column($staff['stores'], 'name', 'id');
        $beautyCounts = [];
        foreach ($roster as $person) if ($person['employed'] && $person['role_code'] === 'beauty') $beautyCounts[$person['store_id']][$person['employee_id']] = true;
        $targets = $reader->targets($stores, $range);
        $storeTargets = [];
        foreach ($targets as $target) $storeTargets[(int)$target['store_id']] = ($storeTargets[(int)$target['store_id']] ?? 0) + (int)$target['target_value'];
        $beauty = []; $advisors = []; $leaders = [];
        foreach ($entities as $key => &$entity) {
            if (!$entity['employed'] && !$entity['_has_facts']) continue;
            $entity['store'] = $storeNames[$entity['store_id']] ?? $entity['store'];
            $entity['staff'] = count($beautyCounts[$entity['store_id']] ?? []);
            $entity['target_cents'] = $storeTargets[$entity['store_id']] ?? null;
            $entity['completion'] = HumanEfficiencyRules::ratio($entity['cash_cents'], $entity['target_cents']);
            $entity['target_status'] = $entity['target_cents'] === null ? '未配置门店现金业绩目标' : ($entity['target_cents'] === 0 ? '门店目标为0' : '个人现金业绩 ÷ 门店目标');
            $this->finish($entity);
            if ($entity['role_code'] === 'beauty') $beauty[] = $entity;
            elseif ($entity['role_code'] === 'advisor') $advisors[] = $entity;
            elseif ($entity['role_code'] === 'leader') $leaders[] = $entity;
        }
        unset($entity);
        // 全部门店保留可见行；未配置店长时明确显示空岗位，不伪造人员或个人业绩。
        $leaderStores=array_fill_keys(array_column($leaders,'store_id'),true);
        foreach($storeNames as $storeId=>$name)if(!$selfEmployee&&!isset($leaderStores[$storeId])){
            $placeholder=$this->emptyEntity(['id'=>'vacant:'.$storeId,'store_id'=>(int)$storeId,'employee_id'=>0,
                'name'=>'未配置店长','store'=>$name,'role'=>'店长','role_code'=>'leader','employed'=>false,'placeholder'=>true]);
            $placeholder['staff']=count($beautyCounts[$storeId]??[]);$placeholder['target_cents']=$storeTargets[$storeId]??null;
            $placeholder['target_status']='未配置店长，不计算个人完成率';$this->finish($placeholder);
            $leaders[]=$placeholder;$entities[$placeholder['id']]=$placeholder;
        }
        $cohorts = [];
        foreach (['three_years'=>'满3年及以上','one_year'=>'满1年、不满3年','six_months'=>'满6个月、不满1年','new'=>'不满6个月','unknown'=>'入职日期未维护'] as $key=>$label) {
            $members = array_values(array_filter($beauty, static fn(array $p): bool => $p['tenure_group'] === $key));
            $cohorts[] = $this->summary($members) + ['id'=>$key,'name'=>$label];
        }
        $beautySummary = $this->summary($beauty);
        foreach ($cohorts as &$cohort) {
            $cohort['share'] = HumanEfficiencyRules::ratio($cohort['count'], $beautySummary['count']);
            foreach (['cash','consume','items','visits','orders','active','buyers'] as $metric) $cohort['contribution'][$metric] = HumanEfficiencyRules::ratio($cohort[$metric], $beautySummary[$metric]);
        }
        unset($cohort);
        $managerResult = $selfEmployee?['rows'=>[],'warnings'=>['当前为个人数据权限，仅展示本人期间贡献和期末人数，不展示全店或经理汇总。']]:$this->managers($reader, $stores, $range, $targets);
        // 查询、下钻与导出都由同一个响应模型生成；私有事实集合只供后端使用。
        $tables = [
            'advisor' => HumanEfficiencyRules::page(array_merge($advisors, $leaders), 'advisor', max(1, (int)($input['advisor_page'] ?? 1))),
            'leader' => HumanEfficiencyRules::page($leaders, 'leader', max(1, (int)($input['leader_page'] ?? 1))),
            'manager' => HumanEfficiencyRules::page($managerResult['rows'], 'manager', max(1, (int)($input['manager_page'] ?? 1))),
        ];
        $warnings = $managerResult['warnings'];
        // 表头从完整榜单生成，分页和导出复用同一分类显隐规则。
        $rankingRows = ['advisor'=>array_merge($advisors,$leaders),'leader'=>$leaders,'manager'=>$managerResult['rows']];
        $result = ['ranking_layouts'=>(new HumanEfficiencyMetadataServices())->layouts($this->categories,$rankingRows),'categories'=>$this->categories,'beauty'=>$beautySummary,'cohorts'=>$cohorts,
            'leader_summary'=>$this->summary($leaders),'advisor_summary'=>$this->summary($advisors),
            'manager_summary'=>$this->summary($managerResult['rows']),
            'personal_summary'=>$this->summary(array_merge($leaders,$advisors)), 'tables'=>$tables,
            'warnings'=>$warnings,'scope'=>['store_ids'=>$stores,'range'=>$range],
            'source_explanations'=>$metadata['source_explanations'], 'data_as_of'=>date('Y-m-d H:i:s')];
        if ($this->detailCollector) {
            $key=(string)$input['entity_id'];$all=$entities;foreach($managerResult['rows'] as $r)$all[$r['id']]=$r;
            $summaryKeys=['summary:beauty','summary:leader','summary:advisor','summary:manager'];
            foreach($cohorts as $c)$summaryKeys[]='summary:cohort:'.$c['id'];
            if(!isset($all[$key])&&!in_array($key,$summaryKeys,true))throw new \InvalidArgumentException('该人员或经理不在当前查询范围');
            if(($input['metric']??'')==='people'){
                $seen=[];foreach($all as $r)if($this->detailCollector->matches($r)&&!empty($r['employed'])&&!isset($seen[$r['employee_id']])){
                    $seen[$r['employee_id']]=true;$this->detailCollector->push(['employee_name'=>$r['name'],'store_name'=>$r['store'],'item_name'=>$r['role']]);
                }
            }
            if(($input['metric']??'')==='stores'){
                $seen=[];foreach($all as $r)if($this->detailCollector->matches($r))foreach($r['_stores']??[] as $id=>$name){
                    if(isset($seen[$id]))continue;$seen[$id]=true;$this->detailCollector->push(['store_id'=>$id,'store_name'=>$name]);
                }
            }
            return $this->detailCollector->result($all[$key]['name']??'当前范围');
        }
        if (($input['action'] ?? '') === 'export') return $this->exportRows($entities, $managerResult['rows']);
        return $this->publicResult($result);
    }

    /** 期末状态由任职区间确定；无历史期间的旧档仅在有效入职日期与当前在职都成立时补证。 */
    private function roster(array $data, int $end): array
    {
        $tenures = []; $jobs = [];
        foreach ($data['tenures'] as $period) $tenures[(int)$period['staff_id']][] = $period;
        foreach ($data['jobs'] as $job) $jobs[(int)$job['staff_id']][] = $job;
        $out = [];
        foreach ($data['staff'] as $staff) {
            $id = (int)$staff['staff_id']; $entry = $this->entryDate($staff['join_date']); $active = false;
            foreach ($tenures[$id] ?? [] as $period) if (HumanEfficiencyRules::employedAt($period, $end)) $active = true;
            if (!isset($tenures[$id])) $active = (int)$staff['status'] === 1 && (int)$staff['is_del'] === 0 && $entry !== null && strtotime($entry) <= $end;
            if (!isset($tenures[$id]) && (int)$staff['status'] === 1 && (int)$staff['is_del'] === 0) {
                // 入职日期可能未维护，但当期有效岗位本身可证明任职；工龄仍归入未知组。
                foreach ($jobs[$id] ?? [] as $evidence) if (HumanEfficiencyRules::employedAt($evidence, $end)) $active = true;
            }
            $jobRows = $jobs[$id] ?? []; usort($jobRows, static fn(array $a,array $b):int => (int)$b['start_time'] <=> (int)$a['start_time']);
            $job = null;
            foreach ($jobRows as $candidate) if ((int)$candidate['start_time'] <= $end) { $job = $candidate; break; }
            $role = $this->role($job, $staff);
            $key = (int)$staff['store_id'] . ':' . (int)$staff['employee_id'];
            $out[$key] = ['id'=>$key,'store_id'=>(int)$staff['store_id'],'employee_id'=>(int)$staff['employee_id'],
                'name'=>(string)$staff['name'],'store'=>'','role_code'=>$role,'role'=>$job['position_name'] ?? ($role==='beauty'?'美容师':'未设置岗位'),
                'employed'=>$active,'tenure_group'=>HumanEfficiencyRules::tenureGroup($entry,date('Y-m-d',$end))];
        }
        return $out;
    }

    private function historicalPerson(array $staff, array $fact, int $end): array
    {
        $matches = array_values(array_filter($staff['jobs'], static fn(array $j):bool => (int)$j['employee_id']===(int)$fact['employee_id'] && (int)$j['store_id']===(int)$fact['store_id'] && (int)$j['start_time'] <= $end));
        usort($matches, static fn(array $a,array $b):int => (int)$b['start_time'] <=> (int)$a['start_time']);
        $job=$matches[0]??null;
        return ['id'=>(int)$fact['store_id'].':'.(int)$fact['employee_id'],'store_id'=>(int)$fact['store_id'],
            'employee_id'=>(int)$fact['employee_id'],'name'=>(string)$fact['employee_name'],'store'=>(string)($fact['store_name']??''),
            'role_code'=>$this->role($job,[]),'role'=>$job['position_name']??'未设置岗位','employed'=>false,'tenure_group'=>'unknown'];
    }

    /** 共用纯规则确保在职名册与离职人员的岗位归类一致。 */
    private function role(?array $job, array $staff): string
    {
        return HumanEfficiencyRules::role($job, $staff);
    }

    private function entryDate($value): ?string
    {
        if (is_numeric($value)) return (int)$value>0 ? date('Y-m-d',(int)$value) : null;
        $day=substr((string)$value,0,10); $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$day);
        return $date && $date->format('Y-m-d')===$day ? $day : null;
    }

    private function emptyEntity(array $person): array
    {
        return $person+['cash_cents'=>0,'consume_cents'=>0,'items'=>0,'visits'=>0,'_active'=>[],'_buyers'=>[],'_orders'=>[],
            '_has_facts'=>false,'category_values'=>[],'staff'=>0,'target_cents'=>null,'completion'=>null];
    }

    /** 客户集合先保留稳定身份，到汇总时重新去重；不能直接相加个人或分类人头。 */
    private function append(array &$row, array $fact): void
    {
        $cash=(int)($fact['cash_cents']??0); $member=(int)($fact['member_id']??0);
        $service=(string)($fact['service_fact_id']??'');
        $row['cash_cents']+=$cash; $row['consume_cents']+=(int)($fact['consumption_cents']??0);
        $row['items']+=(float)($fact['project_count']??0); $row['visits']+=(int)($fact['service_visit_tenths']??0)/10;
        if ($service!=='' && $member>0 && (int)($fact['service_people_tenths']??0)>0) $row['_active'][$member]=true;
        if ($cash>0) {
            if ($member>0) $row['_buyers'][$member]=true;
            $order=(string)(($fact['order_id']??'')!==''?$fact['order_id']:($fact['checkout_request_id']??''));
            if ($order!=='') $row['_orders'][$fact['store_id'].':'.$order]=true;
        }
        foreach ((array)($fact['sales_category_cents']??[]) as $id=>$amount) {
            $root=$this->categoryRoots[(int)$id]??0;
            if (!$root) continue;
            if (!isset($row['category_values'][$root])) $row['category_values'][$root]=['cash_cents'=>0,'_buyers'=>[]];
            $row['category_values'][$root]['cash_cents']+=(int)$amount;
            if ($amount>0 && $member>0) $row['category_values'][$root]['_buyers'][$member]=true;
        }
        $row['_has_facts']=true;
        if($this->detailCollector)$this->detailCollector->add($row,$fact);
    }

    private function finish(array &$row): void
    {
        $row['active']=count($row['_active']); $row['buyers']=count($row['_buyers']); $row['orders']=count($row['_orders']);
        $row['cash']=$row['cash_cents']/100; $row['consume']=$row['consume_cents']/100;
        $row['target']=$row['target_cents']===null?null:$row['target_cents']/100;
        $row['average_active']=HumanEfficiencyRules::ratio($row['active'],$row['staff']);
        $row['average_visits']=HumanEfficiencyRules::ratio($row['visits'],$row['staff']);
        foreach ($this->categories as $category) {
            $id=$category['id']; $part=$row['category_values'][$id]??['cash_cents'=>0,'_buyers'=>[]];
            $part['cash']=$part['cash_cents']/100; $part['buyers']=count($part['_buyers']); $row['category_values'][$id]=$part;
        }
    }

    /** 汇总重新去重并由后端给出人均，期末人数为零时返回null。 */
    private function summary(array $rows): array
    {
        $out=['count'=>0,'active'=>0,'buyers'=>0,'orders'=>0,'cash_cents'=>0,'consume_cents'=>0,'items'=>0,'visits'=>0];
        $people=[];$active=[];$buyers=[];$orders=[];$stores=[];
        foreach($rows as $row){
            if(!empty($row['employed']))$people[$row['employee_id']]=true;
            foreach(['cash_cents','consume_cents','items','visits'] as $key)$out[$key]+=$row[$key];
            $stores+=($row['_stores']??[]);
            $active+=$row['_active'];$buyers+=$row['_buyers'];$orders+=$row['_orders'];
        }
        $out['count']=count($people);$out['active']=count($active);$out['buyers']=count($buyers);$out['orders']=count($orders);
        $out['cash']=$out['cash_cents']/100;$out['consume']=$out['consume_cents']/100;
        $out['average']=[];foreach(['active','buyers','orders','cash','consume','items','visits'] as $key)$out['average'][$key]=HumanEfficiencyRules::ratio($out[$key],$out['count']);
        $out['stores']=count($stores);$out['average_store_cash']=HumanEfficiencyRules::ratio($out['cash'],$out['stores']);
        $out['sale_average']=HumanEfficiencyRules::ratio($out['cash'],$out['orders']);
        return $out;
    }

    private function categoryRootMap(): array
    {
        $rows=Db::name('store_product_category')->where('type',0)->where('relation_id',0)->field('id,pid')->select()->toArray();
        $parents=array_column($rows,'pid','id');$enabled=array_fill_keys(array_column($this->categories,'id'),true);$out=[];
        foreach($parents as $id=>$parent){$at=$id;$seen=[];while(isset($parents[$at])&&!isset($seen[$at])){if(isset($enabled[$at])){$out[$id]=(int)$at;break;}$seen[$at]=true;$at=(int)$parents[$at];}}
        return $out;
    }

    /** 产品经理改为当前归属：期间原单金额不改，经理归集和目标使用同一当前组织绑定。 */
    private function managers(HumanEfficiencyFactReader $reader,array $stores,array $range,array $targets):array
    {
        // 未配置经理的门店不进入经理榜单及汇总，也不混入经理下钻和导出。原业务事实不改。
        $current=array_filter($reader->currentManagers($stores),static fn(array $m):bool=>(string)$m['organization_id']!=='unknown');
        $stores=array_values(array_map('intval',array_keys($current)));
        if(!$stores)return ['rows'=>[],'warnings'=>[]];
        $targets=array_values(array_filter($targets,static fn(array $t):bool=>in_array((int)$t['store_id'],$stores,true)));
        $rows=[];
        $resolve=static fn(array $f):array=>$current[(int)$f['store_id']];
        // 目标仍按查询覆盖的自然日分摊，但每一天均归当前经理；不是伪造历史管辖资料。
        $history=['days'=>[],'managers'=>[],'complete'=>true];
        foreach($current as $store=>$manager){
            $id=(string)$manager['organization_id'];$history['managers'][$id]=$manager['organization_name_snapshot'];
            for($day=new \DateTimeImmutable($range['start']);$day->format('Y-m-d')<=$range['end'];$day=$day->modify('+1 day'))$history['days'][$store][$day->format('Y-m-d')]=$id;
        }
        $ensure=function(array $manager)use(&$rows):string{
            $id=(string)$manager['organization_id'];
            if(!isset($rows[$id]))$rows[$id]=$this->emptyEntity(['id'=>'manager:'.$id,'manager_id'=>$id,'store_id'=>0,
                'employee_id'=>$id,'name'=>$manager['organization_name_snapshot'],'store'=>'','role'=>'城市经理',
                'role_code'=>'manager','employed'=>$id!=='unknown','_stores'=>[],'_visits'=>[]]);
            return $id;
        };
        foreach($history['managers'] as $id=>$name)$ensure(['organization_id'=>$id,'organization_name_snapshot'=>$name]);
        foreach($history['days'] as $store=>$dates)foreach(array_unique($dates) as $id)$rows[$id]['_stores'][$store]='';
        $registered=\app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory();
        foreach($stores as $scopeStore){
        foreach($registered->categoryRows('cash_performance','0',[$scopeStore],$range) as $f){
            $id=$ensure($resolve($f));$rows[$id]['_stores'][(int)$f['store_id']]=(string)($f['store_name']??'');
            $category=(int)($f['category_id']??0);
            $this->append($rows[$id],array_merge($f,['cash_cents'=>(int)$f['amount_cents'],'sales_category_cents'=>[$category=>(int)$f['amount_cents']],'project_count'=>0,'consumption_cents'=>0,'service_visit_tenths'=>0,'service_fact_id'=>'']));
        }
        foreach($reader->services([$scopeStore],$range) as $f){
            $id=$ensure($resolve($f));$member=(int)$f['member_id'];
            $rows[$id]['_stores'][(int)$f['store_id']]=(string)$f['store_name_snapshot'];
            if($member>0 && \app\services\query\metric\StoreCustomerVisitEligibility::allows($f))$rows[$id]['_active'][$member]=true;
            // 经理复用门店注册售后人次资格及会员日键，原单只在第一次出现时承载一人次。
            $visit=\app\services\query\metric\StoreCustomerVisitEligibility::dailyVisitKey($f);
            $visitShare=$visit!==null && !isset($rows[$id]['_visits'][$visit]) ? 10 : 0;
            if($visit!==null)$rows[$id]['_visits'][$visit]=true;
            $rows[$id]['items']+=(float)$f['quantity'];
            if($this->detailCollector)$this->detailCollector->add($rows[$id],array_merge($f,['row_key'=>$f['service_fact_id'],'store_name'=>$f['store_name_snapshot'],
                'member_name'=>$f['member_name_snapshot'],'order_no_snapshot'=>$f['document_no_snapshot'],
                'service_visit_tenths'=>$visitShare,'active_eligible'=>\app\services\query\metric\StoreCustomerVisitEligibility::allows($f),'project_count'=>$f['quantity'],'item_name'=>$f['project_name_snapshot'],'cash_cents'=>0,'consumption_cents'=>0]));
        }
        foreach($registered->categoryRows('consume_amount','0',[$scopeStore],$range) as $f){
            $id=$ensure($resolve($f));$rows[$id]['consume_cents']+=(int)$f['amount_cents'];
            // 消耗明细保留独立事实，不拿人员劳动分配替代门店核销总额。
            if($this->detailCollector)$this->detailCollector->add($rows[$id],array_merge($f,['cash_cents'=>0,'project_count'=>0,'service_visit_tenths'=>0,'consumption_cents'=>(int)$f['amount_cents'],'active_eligible'=>false,'service_fact_id'=>'consumption:'.$f['fact_id']]));
        }
        }
        $names=Db::name('system_store')->whereIn('id',$stores)->column('name','id');
        $projection=(new HumanEfficiencyTargetProjector())->project($targets,$range,$history['days']);
        $projection['history_complete']=$projection['history_complete']&&$history['complete'];
        foreach($rows as &$r){
            foreach($r['_stores'] as $id=>$_)$r['_stores'][$id]=$names[$id]??('门店'.$id);
            $completion=(new HumanEfficiencyTargetProjector())->managerCompletion($r['cash_cents'],$r['manager_id'],$projection);
            $r['target_cents']=$completion['target_cents'];$r['completion']=$completion['completion'];$r['target_status']=$completion['message'];
            $r['stores']=count($r['_stores']);$r['visits']=count($r['_visits']);$this->finish($r);
        }unset($r);
        return ['rows'=>array_values($rows),'warnings'=>[]];
    }

    private function exportRows(array $entities,array $managers):array
    {
        $sections=['beauty'=>[],'advisor'=>[],'leader'=>[],'manager'=>$managers,'unknown'=>[]];
        foreach($entities as $r){
            if(!$r['employed']&&!$r['_has_facts']&&empty($r['placeholder']))continue;
            if(isset($sections[$r['role_code']]))$sections[$r['role_code']][]=$r;
        }
        $sections['advisor']=array_merge($sections['advisor'],$sections['leader']);
        $rows=[];$sectionCategories=[];
        foreach($sections as $kind=>$members){
            $sectionCategories[$kind]=HumanEfficiencyRules::visibleCategories($this->categories,$members);
            if(in_array($kind,['beauty','unknown'],true))$ordered=$members;
            else{
                $first=HumanEfficiencyRules::page($members,$kind,1);$ordered=$first['rows'];
                for($page=2;$page<=$first['pages'];$page++)$ordered=array_merge($ordered,HumanEfficiencyRules::page($members,$kind,$page)['rows']);
            }
            foreach($ordered as $r){$r['section']=$kind;$rows[]=$r;}
        }
        return $this->publicResult(['categories'=>$this->categories,'section_categories'=>$sectionCategories,'rows'=>$rows]);
    }

    /** 私有去重集合与完整原单不随汇总泄露；明细必须重新经过同一权限及筛选。 */
    private function publicResult(array $value):array
    {
        $out=[];foreach($value as $key=>$item){if(is_string($key)&&strpos($key,'_')===0)continue;$out[$key]=is_array($item)?$this->publicResult($item):$item;}return $out;
    }
}
