<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 第六阶段九张报表。
 *
 * 本服务只接收控制器已经裁剪后的门店范围，并从 V3 事实层读取数据。
 * 所有列都带业务化来源说明，前端只按返回的列契约展示。
 */
final class StoreUnifiedReportPhaseSixServices
{
    public const METRIC_VERSION = 'report-phase-six-v1';

    public const REPORTS = [
        'phase_six_garden_item_analysis' => ['name' => '花园品项分析表', 'both_ends' => false],
        'phase_six_monthly_featured_item' => ['name' => '月主推数据统计表', 'both_ends' => false],
        'phase_six_headquarters_acquisition' => ['name' => '总部拓客数据统计表', 'both_ends' => false],
        'phase_six_other_multi_payment' => ['name' => '其他多收款业绩表', 'both_ends' => true],
        'phase_six_salary_summary' => ['name' => '员工薪资汇总月报表', 'both_ends' => true],
        'phase_six_salary_detail' => ['name' => '员工薪资明细月报表', 'both_ends' => true],
        'phase_six_training_employee' => ['name' => '教培员工需求统计表', 'both_ends' => false],
        'phase_six_acquisition_source' => ['name' => '拓客部门客户来源数据分析表', 'both_ends' => false],
        'phase_six_human_store_health' => ['name' => '人力-院店健康报表', 'both_ends' => false],
    ];

    public static function reportCodes(): array { return array_keys(self::REPORTS); }

    public static function platformOnlyReportCodes(): array
    {
        return array_values(array_filter(self::reportCodes(), static fn(string $code): bool => empty(self::REPORTS[$code]['both_ends'])));
    }

    public static function catalogEntries(bool $platform = true): array
    {
        $rows = [];
        foreach (self::REPORTS as $code => $definition) {
            if (!$platform && empty($definition['both_ends'])) continue;
            $rows[] = [
                'folder' => '其他报表', 'code' => $code,
                'name' => $definition['name'], 'platform_only' => empty($definition['both_ends']),
            ];
        }
        return $rows;
    }

    public function supports(string $report): bool { return isset(self::REPORTS[$report]); }

    public function query(string $report, $storeIds, array $range, array $input = []): array
    {
        if (!$this->supports($report)) throw new \InvalidArgumentException('不支持的第六阶段报表类型');
        $stores = array_values(array_unique(array_filter(array_map('intval', (array)$storeIds))));
        if ($stores === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        $range = $this->range($range, $input);
        switch ($report) {
            case 'phase_six_garden_item_analysis': return $this->garden($stores, $range);
            case 'phase_six_monthly_featured_item': return $this->featured($stores, $range);
            case 'phase_six_headquarters_acquisition': return $this->headquarters($stores, $range);
            case 'phase_six_other_multi_payment': return $this->multiPayment($stores, $range);
            case 'phase_six_salary_summary': return $this->salarySummary($stores, $range);
            case 'phase_six_salary_detail': return $this->salaryDetail($stores, $range);
            case 'phase_six_training_employee': return $this->trainingV2($stores, $range);
            case 'phase_six_acquisition_source': return $this->acquisitionSource($stores, $range);
            case 'phase_six_human_store_health': return $this->healthWithStaffing($stores, $range);
        }
        throw new \InvalidArgumentException('不支持的第六阶段报表类型');
    }

    /** Current, non-historical headcount maintenance. */
    public function staffing(array $storeIds): array
    {
        $stores = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($stores === []) return [];
        return Db::name('cashier_v3_report_beautician_establishment')->whereIn('store_id', $stores)
            ->field('store_id,establishment_count,version,updated_at')->select()->toArray();
    }

    public function saveStaffing(array $context, array $payload): array
    {
        $storeId = (int)($payload['store_id'] ?? 0);
        $count = (int)($payload['establishment_count'] ?? 0);
        if ($storeId <= 0 || $count < 0) throw new \InvalidArgumentException('门店和美容师编制人数不正确');
        if (!in_array($storeId, array_map('intval', (array)($context['store_ids'] ?? [])), true)) {
            throw new \InvalidArgumentException('无权限维护该门店编制人数');
        }
        $expected = (int)($payload['expected_version'] ?? 0);
        return Db::transaction(function () use ($storeId, $count, $expected, $context): array {
            $now = time();
            // The row lock must be acquired inside the transaction; locking a
            // read before the transaction leaves concurrent saves unprotected.
            $existing = Db::name('cashier_v3_report_beautician_establishment')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('store_id', $storeId)->lock(true)->find();
            if ($existing && $expected !== (int)$existing['version']) {
                throw new \InvalidArgumentException('编制人数已被其他人修改，请刷新后重试');
            }
            $row = [
                'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'store_id' => $storeId,
                'establishment_count' => $count, 'version' => $existing ? (int)$existing['version'] + 1 : 1,
                'updated_by' => (int)($context['admin_id'] ?? 0),
                'updated_by_name_snapshot' => (string)($context['admin_name'] ?? ''),
                'created_at' => $existing ? (int)$existing['created_at'] : $now, 'updated_at' => $now,
            ];
            if ($existing) Db::name('cashier_v3_report_beautician_establishment')->where('id', (int)$existing['id'])->update($row);
            else Db::name('cashier_v3_report_beautician_establishment')->insert($row);
            Db::name('cashier_v3_report_beautician_establishment_audit')->insert([
                'tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'store_id' => $storeId,
                'before_count' => $existing ? (int)$existing['establishment_count'] : null,
                'after_count' => (int)$row['establishment_count'], 'version' => (int)$row['version'],
                'operator_id' => (int)($context['admin_id'] ?? 0), 'operator_name_snapshot' => (string)($context['admin_name'] ?? ''),
                'occurred_at' => $now,
            ]);
            return $row;
        });
    }

    private function garden(array $stores, array $range): array
    {
        $facts = array_values(array_filter($this->cashFacts($stores, $range), static fn(array $r): bool => trim((string)($r['category_path'] ?? '')) === '花园' || strpos((string)($r['category_path'] ?? ''), '花园 /') === 0));
        $monthly = [];
        foreach ($facts as $fact) if ((int)$fact['member_id'] > 0) $monthly[(string)$fact['member_id']] = ($monthly[(string)$fact['member_id']] ?? 0) + (int)$fact['amount_cents'];
        $rows = [];
        foreach ($facts as $fact) {
            $member = (int)$fact['member_id'];
            $rows[] = [
                'company_name' => (string)$fact['company_name'], 'store_name' => (string)$fact['store_name'],
                'member_name' => (string)$fact['member_name'], 'salesperson_name' => (string)$fact['salesperson_name'],
                'partner_name' => (string)$fact['partner_name'], 'experience_people' => (int)$fact['is_experience'],
                'deal_people' => $member > 0 && (int)($monthly[(string)$member] ?? 0) >= 100000 ? 1 : 0,
                'item_name' => (string)$fact['item_name'], 'quantity' => (int)$fact['quantity'],
                'cash_amount' => $this->money((int)$fact['amount_cents']), 'row_key' => (string)$fact['allocation_id'],
            ];
        }
        return $this->result('花园品项分析表', [
            $this->column('company_name','分公司','销售事实发生时的组织快照。',false,true),
            $this->column('store_name','门店','销售事实发生时的门店快照。',false,true),
            $this->column('member_name','会员','销售明细对应会员或散客标识。'),
            $this->column('salesperson_name','门店销售人','销售事实中的销售人历史姓名快照。'),
            $this->column('partner_name','合作方名称','商品分类合作方名称快照。'),
            $this->column('experience_people','体验人数','购物车体验标签的有效记录。',true),
            $this->column('deal_people','成交人数','花园月累计现金业绩达到1000元的会员或顾客。',true),
            $this->column('item_name','成交品名','现金购买商品明细名称。'),
            $this->column('quantity','商品数量','对应销售明细数量。',true),
            $this->column('cash_amount','成交金额','现金业绩分摊事实。',true),
            $this->manualColumn('expert_name','专家姓名','按稳定明细行键保存的手动字段.'),
        ], $rows, $range);
    }

    private function featured(array $stores, array $range): array
    {
        $facts = $this->cashFacts($stores, $range); $groups = []; $categories = [];
        foreach ($facts as $f) { $key = (int)$f['store_id']; $cat = (string)$f['category_path']; if ($cat !== '') $categories[$cat] = true; if (!isset($groups[$key])) $groups[$key] = ['store_id'=>$key,'company_name'=>$f['company_name'],'store_name'=>$f['store_name'],'cash_amount'=>0]; $groups[$key]['cash_amount'] += (int)$f['amount_cents']; }
        $columns = [$this->column('company_name','区域','组织权限范围。',false,true),$this->column('store_name','门店','门店权限范围。',false,true),$this->manualColumn('target_amount','业绩目标','门店月度目标手动输入。')];
        foreach (array_keys($categories) as $cat) { $safe = 'featured_' . substr(sha1($cat),0,10); $columns[] = $this->column($safe.'_experience',$cat.'/体验人数','当月配置品项且有体验标签的有效记录数。',true,false,110,[],$cat); $columns[] = $this->column($safe.'_deal',$cat.'/成交人数','达到业务门槛的会员或顾客数。',true,false,110,[],$cat); $columns[] = $this->column($safe.'_cash',$cat.'/成交金额','该品项现金业绩分摊事实。',true,false,120,[],$cat); }
        $rows = []; foreach ($groups as &$row) { $row['cash_amount'] = $this->money($row['cash_amount']); foreach (array_keys($categories) as $cat) { $safe='featured_'.substr(sha1($cat),0,10); $matching=array_values(array_filter($facts,static fn(array $f):bool=>(int)$f['store_id']===(int)$row['store_id']&&(string)$f['category_path']===$cat)); $members=[]; $amount=0; foreach($matching as $m){$amount+=(int)$m['amount_cents'];if((int)$m['member_id']>0)$members[(int)$m['member_id']]=true;} $row[$safe.'_experience']=count(array_filter($matching,static fn(array $f):bool=>(int)$f['is_experience']===1));$row[$safe.'_deal']=count($members);$row[$safe.'_cash']=$this->money($amount); } } unset($row);
        return $this->result('月主推数据统计表',$columns,array_values($groups),$range);
    }

    private function headquarters(array $stores, array $range): array
    {
        $facts=$this->cashFacts($stores,$range);$sources=['B 导购引流','E 地推拓客','H 沉睡唤醒','G 异业收客','F 美带新客','C 老带新客'];$rows=[];
        foreach($sources as $source){$members=[];$amount=0;$orders=[];foreach($facts as $f){if((string)($f['source_name']??'系统词')!==$source)continue;$id=(int)$f['member_id'];if($id>0){$members[$id]=true;$orders[$id][(string)$f['order_id']]=true;}$amount+=(int)$f['amount_cents'];} $twice=[];$twiceAmount=0;foreach($orders as $id=>$orderSet)if(count($orderSet)>=2){$twice[$id]=true;foreach($facts as $f)if((int)$f['member_id']===$id)$twiceAmount+=(int)$f['amount_cents'];}$rows[]=['source'=>$source,'member_count'=>count($members),'annual_amount'=>$this->money($amount),'two_order_member_count'=>count($twice),'two_order_amount'=>$this->money($twiceAmount)];}
        return $this->result('总部拓客数据统计表',[$this->column('source','来源','年度第一笔正常销售订单冻结的来源。',false,true),$this->column('member_count','会员人数','该来源下有效会员人数。',true),$this->column('annual_amount','年度业绩','年度现金业绩事实合计。',true),$this->column('two_order_member_count','二单以上现金人数','年度至少两笔有效现金销售的会员数。',true),$this->column('two_order_amount','二单以上现金业绩','满足二单条件会员年度现金业绩合计。',true)],$rows,$range);
    }

    private function multiPayment(array $stores,array $range):array{$facts=array_values(array_filter($this->cashFacts($stores,$range),static fn(array $f):bool=>(int)$f['is_experience']===1));$rows=[];foreach($facts as $f)$rows[]=['company_name'=>$f['company_name'],'store_name'=>$f['store_name'],'business_date'=>$f['business_date'],'customer_name'=>$f['member_name'],'employee_name'=>$f['salesperson_name'],'category'=>$f['category_path'],'item_name'=>$f['item_name'],'quantity'=>(int)$f['quantity'],'cash_amount'=>$this->money((int)$f['amount_cents']),'row_key'=>$f['allocation_id']];return $this->result('其他多收款业绩表',[$this->column('company_name','分公司','销售事实组织快照。',false,true),$this->column('store_name','门店','销售事实门店快照。',false,true),$this->column('business_date','日期','成功结账业务日期。'),$this->column('customer_name','顾客姓名','会员或顾客快照。'),$this->column('employee_name','员工姓名','销售或操作员工快照。'),$this->column('category','商品分类','销售明细历史分类快照。'),$this->column('item_name','商品名称','销售明细名称。'),$this->manualColumn('unit_price','单价','手动输入并独立保存。'),$this->column('quantity','数量','销售明细数量。',true),$this->column('cash_amount','现金业绩','体验现金业绩事实。',true),$this->manualColumn('领取日期','领取日期','手动输入并独立保存。'),$this->manualColumn('领取数量','领取数量','手动输入并独立保存。'),$this->manualColumn('备注','备注','手动输入并独立保存。')],$rows,$range);}

    private function salaryDetail(array $stores,array $range):array
    {
        $facts = $this->salaryFacts($stores, $range);
        $rows = [];
        foreach ($facts as $f) {
            $rows[] = [
                'business_date' => $f['business_date'], 'company_name' => $f['company_name'],
                'store_name' => $f['store_name'], 'employee_name' => $f['employee_name'],
                'member_name' => $f['member_name'], 'card_type' => (string)($f['card_type'] ?: '-'),
                'performance_category' => $f['category_path'], 'detail' => $f['item_name'],
                'project_count' => number_format((float)$f['project_count'], 1, '.', ''),
                'consumption_amount' => $this->money((int)$f['consumption_cents']),
                'cash_amount' => $this->money((int)($f['cash_cents'] ?? 0)),
                'labor_fee' => $this->money((int)$f['labor_fee_cents']), 'row_key' => $f['row_key'],
            ];
        }
        return $this->result('员工薪资明细月报表',[
            $this->column('business_date','日期','事实业务日期。'),
            $this->column('company_name','分公司','组织中的分公司统计维度。',false,true),
            $this->column('store_name','门店','业务发生时门店快照。',false,true),
            $this->column('employee_name','员工','销售或手艺人历史姓名快照。'),
            $this->column('member_name','会员','会员历史姓名快照。'),
            $this->column('card_type','卡类型','卡内项目核销的卡类型，非卡内显示-。'),
            $this->column('performance_category','业绩分类','统一业绩分类。'),
            $this->column('detail','明细','项目或产品名称。'),
            $this->column('project_count','项目数','服务记录分配给该手艺人的工资项目数；人工调整时只能按0.5递增。',true),
            $this->column('consumption_amount','消耗业绩','服务完成后的消耗业绩事实。',true),
            $this->column('cash_amount','现金业绩','销售明细现金业绩分摊事实。',true),
            $this->column('labor_fee','手工费','分配手艺人时保存的手工费。',true),
            $this->manualColumn('备注','备注','手动输入并保留审计.')
        ],$rows,$range);
    }

    private function salarySummary(array $stores,array $range):array
    {
        $detail=$this->salaryDetail($stores,$range);$rows=[];
        foreach($this->salaryFacts($stores,$range) as $f){
            $key=(int)$f['store_id'].'|'.(int)$f['employee_id'];
            if(!isset($rows[$key]))$rows[$key]=['company_name'=>$f['company_name'],'store_name'=>$f['store_name'],'employee_name'=>$f['employee_name'],'project_count'=>0,'consumption_cents'=>0,'cash_cents'=>0,'labor_fee_cents'=>0];
            $rows[$key]['project_count']+=(float)$f['project_count'];
            $rows[$key]['consumption_cents']+=(int)$f['consumption_cents'];
            $rows[$key]['cash_cents']+=(int)($f['cash_cents'] ?? 0);
            $rows[$key]['labor_fee_cents']+=(int)$f['labor_fee_cents'];
        }
        foreach($rows as &$r){$r['project_count']=number_format((float)$r['project_count'],1,'.','');$r['consumption_amount']=$this->money($r['consumption_cents']);$r['cash_amount']=$this->money($r['cash_cents']);$r['labor_fee']=$this->money($r['labor_fee_cents']);unset($r['consumption_cents'],$r['cash_cents'],$r['labor_fee_cents']);}unset($r);
        return $this->result('员工薪资汇总月报表',[
            $this->column('company_name','分公司','员工发生业务时组织快照。',false,true),
            $this->column('store_name','门店','员工发生业务时门店快照。',false,true),
            $this->column('employee_name','销售人','员工历史姓名快照。'),
            $this->column('project_count','项目数','员工薪资明细中工资项目数的合计；人工调整值按0.5递增。',true),
            $this->column('consumption_amount','消耗','服务完成或核销形成的消耗业绩。',true),
            $this->column('labor_fee','手工','服务明细手工费。',true),
            $this->column('cash_amount','产品业绩','产品现金业绩事实。',true)
        ],array_values($rows),$range);
    }

    private function training(array $stores,array $range):array{$rows=Db::name('employee')->where('is_del',0)->where('status',1)->field('id,name,entry_time,store_id')->select()->toArray();$allowed=array_flip($stores);$out=[];$i=1;foreach($rows as $r){if(isset($r['store_id'])&&!isset($allowed[(int)$r['store_id']]))continue;$out[]=['sequence'=>$i++,'company_name'=>'','store_name'=>'','beautician_name'=>(string)$r['name'],'entry_time'=>(string)$r['entry_time'],'mentor_name'=>'','entry_cycle'=>'','entry_level'=>'','exam_apply_time'=>'','exam_level'=>'','passed_level'=>'','skill_score'=>'','professional_score'=>'','in_service_3'=>'-','in_service_6'=>'-','in_service_9'=>'-','in_service_12'=>'-','in_service_over_year'=>'-'];}return $this->result('教培员工需求统计表',[$this->column('sequence','序号','页面稳定行号，不作为业务主键。'),$this->column('company_name','分公司','组织中的分公司统计维度。'),$this->column('store_name','门店','员工组织归属。'),$this->column('beautician_name','美容师','员工档案。'),$this->column('entry_time','入职时间','员工正式入职日期。'),$this->manualColumn('mentor_name','师傅','门店分配师傅关系。'),$this->column('entry_cycle','入职周期','按查询日期和每月15日规则计算。'),$this->column('entry_level','入职等级分类','按入职周期分级。'),$this->manualColumn('exam_apply_time','员工申请考试时间','员工手动输入。'),$this->manualColumn('exam_level','员工申请考试级别','员工手动输入。'),$this->manualColumn('passed_level','对应已考等级','教培人员手动输入。'),$this->manualColumn('skill_score','技能分数','教培人员手动输入。'),$this->manualColumn('professional_score','专业分数','教培人员手动输入。'),$this->column('in_service_3','3月是否在职','对应观察时点的历史员工状态。'),$this->column('in_service_6','6月是否在职','对应观察时点的历史员工状态.'),$this->column('in_service_9','9月是否在职','对应观察时点的历史员工状态.'),$this->column('in_service_12','12月是否在职','对应观察时点的历史员工状态.'),$this->column('in_service_over_year','1年以上是否在职','对应观察时点的历史员工状态.')],$out,$range);}

    private function acquisitionSource(array $stores,array $range):array
    {
        $facts=$this->cashFacts($stores,$range);$services=$this->serviceFacts($stores,$range);$map=[];
        foreach($facts as $f){$source=trim((string)($f['source_name']??''))?:'系统词';if(!isset($map[$source]))$map[$source]=['category'=>$source,'visit_count'=>0,'deal_count'=>0,'amount_cents'=>0,'members'=>[],'deal_members'=>[]];$map[$source]['deal_count']++;$map[$source]['amount_cents']+=(int)$f['amount_cents'];$id=(int)$f['member_id'];if($id>0)$map[$source]['deal_members'][$id]=true;}
        foreach($services as $s){$source=trim((string)($s['source_name']??''))?:'系统词';if(!isset($map[$source]))$map[$source]=['category'=>$source,'visit_count'=>0,'deal_count'=>0,'amount_cents'=>0,'members'=>[],'deal_members'=>[]];$id=(int)$s['member_id'];if($id>0)$map[$source]['members'][$id]=true;}
        foreach($map as &$r){$r['visit_count']=count($r['members']);$r['deal_count']=count($r['deal_members']);$r['amount']=$this->money((int)$r['amount_cents']);$r['deal_rate']=$this->ratio((int)$r['deal_count'],(int)$r['visit_count']);$r['unit_output']=$r['deal_count']?$this->money((int)round((int)$r['amount_cents']/(int)$r['deal_count'])):'-';unset($r['members'],$r['deal_members'],$r['amount_cents']);}unset($r);
        return $this->result('拓客部门客户来源数据分析表',[$this->column('category','类别','结账时冻结的来源代码或名称。',false,true),$this->column('visit_count','进店人数','有效到店或服务事实，按统一去重规则统计。',true),$this->column('deal_count','成交人数','成功销售事实按来源统计。',true),$this->column('amount','金额','来源下现金业绩事实。',true),$this->column('deal_rate','成交率','成交人数/进店人数，分母为0显示-。'),$this->column('unit_output','成交单产','金额/成交人数，分母为0显示-。')],array_values($map),$range);
    }

    private function health(array $stores,array $range):array
    {
        $facts=$this->cashFacts($stores,$range);$services=$this->serviceFacts($stores,$range);$rows=[];
        $beauticianPositionId=(int)Db::name('position')->where('name','美容师')->where('status',1)->value('id');
        $est=$beauticianPositionId>0
            ? Db::name('staffing_quota')->where('tenant_id','0')->where('scope_type','store')->whereIn('scope_id',$stores)->where('position_id',$beauticianPositionId)->column('quota_count','scope_id')
            : [];
        foreach($stores as $storeId){
            $storeFacts=array_values(array_filter($facts,static fn(array $f):bool=>(int)$f['store_id']===$storeId));
            $storeServices=array_values(array_filter($services,static fn(array $f):bool=>(int)$f['store_id']===$storeId));
            $storeCash=0;$allMembers=[];$categoryTotals=[];$categoryMembers=[];$categoryServices=[];$categoryConsumption=[];
            foreach($storeFacts as $f){$storeCash+=(int)$f['amount_cents'];$id=(int)$f['member_id'];if($id>0)$allMembers[$id]=true;$cat=$this->topCategory((string)$f['category_path']);$categoryTotals[$cat]=($categoryTotals[$cat]??0)+(int)$f['amount_cents'];if($id>0)$categoryMembers[$cat][$id]=true;}
            foreach($storeServices as $s){$cat=$this->topCategory((string)$s['category_path']);$categoryServices[$cat]=($categoryServices[$cat]??0)+max(0,(int)$s['quantity']);$categoryConsumption[$cat]=($categoryConsumption[$cat]??0)+(int)$s['consumption_cents'];$id=(int)$s['member_id'];if($id>0)$categoryMembers[$cat][$id]=true;}
            $categories=array_values(array_unique(array_merge(array_keys($categoryTotals),array_keys($categoryServices))));if($categories===[])$categories=['全部品项'];
            $beauticians=(int)Db::name('system_store_staff')->where('store_id',$storeId)->where('status',1)->where('is_del',0)->where('cashier_craftsman_enabled',1)->count();$staffing=(int)($est[$storeId]??0);$employeeCount=(int)Db::name('system_store_staff')->where('store_id',$storeId)->where('status',1)->where('is_del',0)->count();$storeName=(string)(Db::name('system_store')->where('id',$storeId)->value('name')??('门店'.$storeId));$company='';foreach($storeFacts as $f){if(trim((string)$f['company_name'])!==''){$company=(string)$f['company_name'];break;}}
            foreach($categories as $cat){$catCash=(int)($categoryTotals[$cat]??0);$members=count((array)($categoryMembers[$cat]??[]));$servicesCount=(int)($categoryServices[$cat]??0);$consumption=(int)($categoryConsumption[$cat]??0);$rows[]=['store_id'=>$storeId,'company_name'=>$company,'store_name'=>$storeName,'item_category'=>$cat,'employee_count'=>$employeeCount,'beautician_establishment_count'=>$staffing,'beautician_fill_rate'=>$staffing>0?$this->ratio($beauticians,$staffing):'-','beautician_turnover_rate'=>'-','active_customer_count'=>$members,'service_count'=>$servicesCount,'average_order_value'=>$members?$this->money((int)round($catCash/$members)):'-','sales_efficiency'=>$employeeCount?$this->money((int)round($catCash/$employeeCount)):'-','ten_thousand_customer_ratio'=>$this->ratio($this->countMembersAtLeast($storeFacts,$cat,1000000),$members),'cash_amount'=>$this->money($catCash),'consumption_amount'=>$this->money($consumption),'consumption_efficiency'=>$employeeCount?$this->money((int)round($consumption/$employeeCount)):'-','effective_sales_headcount'=>$members,'sales_ratio'=>$this->ratio($catCash,$storeCash)];}
        }
        return $this->result('人力-院店健康报表',[$this->column('company_name','分公司','组织中的分公司统计维度。',false,true),$this->column('store_name','门店','门店权限范围。',false,true),$this->column('item_category','品项','启用商品一级分类的历史快照。',false,true),$this->column('employee_count','员工人数','查询截止日有效员工人数。',true),$this->column('beautician_establishment_count','美容师编制人数','每门店唯一当前编制值，不分时间。',true),$this->column('beautician_fill_rate','美容师满岗率','实际美容师人数/当前编制人数，编制为0显示-。'),$this->column('beautician_turnover_rate','美容师离职率','期间离职美容师人数/对应期间在职人数。'),$this->column('active_customer_count','活跃客','区间内不重复的有效到店售后人数。',true),$this->column('service_count','服务人次','区间内有效服务或核销数量。',true),$this->column('average_order_value','客单价','销售业绩/不重复消费人头数。'),$this->column('sales_efficiency','销售人效','销售业绩/员工人数。'),$this->column('ten_thousand_customer_ratio','万元客比','达到1万元门槛客户数/有效客户数。'),$this->column('cash_amount','现金业绩（减退款）','现金业绩净额。',true),$this->column('consumption_amount','消耗业绩','服务或核销消耗业绩。',true),$this->column('consumption_efficiency','消耗人效','消耗业绩/员工人数。'),$this->column('effective_sales_headcount','有效销售人头数','有效销售会员或顾客去重人数。',true),$this->column('sales_ratio','销售比','对应品项业绩/门店现金业绩总额。')],$rows,$range);
    }

    private function cashFacts(array $stores,array $range):array
    {
        return Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')
            ->leftJoin('cashier_v3_report_sale_dimension_fact d','d.tenant_id=a.tenant_id AND d.sale_fact_id=a.sale_fact_id')
            ->leftJoin('cashier_v3_sale_fact s','s.tenant_id=a.tenant_id AND s.fact_id=a.sale_fact_id')
            ->where('a.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('a.store_id',$stores)->whereBetween('a.business_date',[$range['start'],$range['end']])->where('a.status','effective')
            ->field("a.allocation_fact_id allocation_id,a.store_id,a.member_id,a.order_id,a.source_line_id,a.business_date,a.amount_cents,a.payment_method,d.item_name_snapshot item_name,d.category_path_snapshot category_path,d.partner_name_snapshot partner_name,d.is_experience,s.quantity,s.member_name_snapshot member_name,s.organization_name_snapshot company_name,s.organization_path_snapshot organization_path_snapshot,s.store_name_snapshot store_name,s.business_source_label_snapshot source_name,(SELECT GROUP_CONCAT(DISTINCT pf.employee_name_snapshot ORDER BY pf.id SEPARATOR ', ') FROM eb_cashier_v3_performance_fact pf WHERE pf.tenant_id=a.tenant_id AND pf.source_line_id=a.source_line_id AND pf.performance_type='sales_performance_allocated' AND pf.status='effective') salesperson_name")
            ->select()->toArray();
    }

    private function serviceFacts(array $stores,array $range):array
    {
        return Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sv.store_id',$stores)
            ->whereBetween('sv.business_date',[$range['start'],$range['end']])->where('sv.service_status','completed')
            ->field("sv.service_fact_id fact_id,sv.store_id,sv.member_id,sv.business_date,sv.source_line_id,sv.quantity,sv.project_count,sv.project_name_snapshot item_name,sv.project_category_path_snapshot category_path,sv.store_name_snapshot store_name,sv.organization_path_snapshot organization_path_snapshot,sv.is_experience,COALESCE(sv.labor_fee_amount_cents,0) labor_fee_cents,(SELECT COALESCE(SUM(pf.amount_cents),0) FROM eb_cashier_v3_performance_fact pf WHERE pf.tenant_id=sv.tenant_id AND pf.checkout_request_id=sv.checkout_request_id AND pf.source_line_id=sv.source_line_id AND pf.performance_type='consumption_performance_recorded' AND pf.status='effective') consumption_cents,(SELECT MAX(sf.business_source_label_snapshot) FROM eb_cashier_v3_sale_fact sf WHERE sf.tenant_id=sv.tenant_id AND sf.source_line_id=sv.source_line_id AND sf.fact_direction='forward' AND sf.status='effective') source_name")
            ->select()->toArray();
    }

    private function salaryFacts(array $stores,array $range):array
    {
        $rows=Db::name('cashier_v3_performance_fact')->alias('pf')
            ->leftJoin('cashier_v3_entitlement_service_fact sv','sv.tenant_id=pf.tenant_id AND sv.checkout_request_id=pf.checkout_request_id AND sv.source_line_id=pf.source_line_id AND sv.service_status=\'completed\'')
            ->where('pf.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('pf.store_id',$stores)->whereBetween('pf.business_date',[$range['start'],$range['end']])
            ->where('pf.performance_type','labor_performance_allocated')->where('pf.status','effective')
            ->field('pf.id,pf.fact_id,pf.reversal_of,pf.fact_id row_key,pf.store_id,pf.employee_id,pf.employee_name_snapshot employee_name,pf.business_date,pf.checkout_request_id,pf.source_line_id,pf.amount_cents,pf.labor_fee_amount_cents,pf.project_count_half_units,pf.rule_code_snapshot,pf.fact_direction,sv.member_id,sv.member_name_snapshot member_name,sv.store_name_snapshot store_name,sv.organization_name_snapshot company_name,sv.quantity,sv.project_count,sv.project_name_snapshot item_name,sv.project_category_name_snapshot category_path,sv.source_document_type source_type')
            ->order('pf.id','asc')
            ->select()->toArray();
        $reversed=[];
        foreach($rows as $row){if((string)$row['fact_direction']==='reversal'&&trim((string)$row['reversal_of'])!=='')$reversed[(string)$row['reversal_of']]=true;}
        $grouped=[];
        foreach($rows as $row){
            if((string)$row['fact_direction']!=='forward'||isset($reversed[(string)$row['fact_id']]))continue;
            $key=(int)$row['store_id'].'|'.(int)$row['employee_id'].'|'.(string)$row['checkout_request_id'].'|'.(string)$row['source_line_id'];
            if(!isset($grouped[$key])){
                $grouped[$key]=$row;
                $grouped[$key]['amount_cents']=0;
                $grouped[$key]['labor_fee_amount_cents']=0;
                $grouped[$key]['project_count_half_units']=0;
                $grouped[$key]['has_explicit_project_count']=false;
            }
            $grouped[$key]['amount_cents']+=(int)$row['amount_cents'];
            $grouped[$key]['labor_fee_amount_cents']+=(int)$row['labor_fee_amount_cents'];
            $grouped[$key]['project_count_half_units']+=(int)$row['project_count_half_units'];
            $grouped[$key]['has_explicit_project_count']=$grouped[$key]['has_explicit_project_count']
                ||(string)$row['rule_code_snapshot']==='SERVICE-RECORD-CRAFTSMAN-ADJUST-V1'
                ||(int)$row['project_count_half_units']!==0;
            foreach(['row_key','employee_name','member_id','member_name','store_name','company_name','item_name','category_path','source_type'] as $field)$grouped[$key][$field]=$row[$field]??$grouped[$key][$field]??'';
        }
        $lineGroups=[];
        foreach($grouped as $key=>$row){$lineKey=(int)$row['store_id'].'|'.(string)$row['checkout_request_id'].'|'.(string)$row['source_line_id'];$lineGroups[$lineKey][]=$key;}
        foreach($lineGroups as $keys){
            $hasExplicitProjectCount=false;
            foreach($keys as $key)$hasExplicitProjectCount=$hasExplicitProjectCount||!empty($grouped[$key]['has_explicit_project_count']);
            if($hasExplicitProjectCount)continue;
            $sample=$grouped[$keys[0]];
            $legacyProjectCount=(int)($sample['project_count'] ?? 0);
            if($legacyProjectCount<=0)$legacyProjectCount=(int)($sample['quantity'] ?? 0);
            $totalHalfUnits=max(0,$legacyProjectCount)*2;
            $base=intdiv($totalHalfUnits,count($keys));$remainder=$totalHalfUnits-$base*count($keys);
            foreach($keys as $index=>$key)$grouped[$key]['project_count_half_units']=$base+($index>=count($keys)-$remainder?1:0);
        }
        // 现金业绩与服务/手工事实分开记账：纯销售订单没有 labor 行，不能因此从薪资明细中消失。
        // 先按同一业务行、同一员工合并销售事实；若该员工同时参与服务，则与已有 labor 行合并，避免重复展示。
        $salesRows=Db::name('cashier_v3_performance_fact')->alias('pf')
            ->leftJoin('cashier_v3_sale_fact s','s.tenant_id=pf.tenant_id AND s.checkout_request_id=pf.checkout_request_id AND s.source_line_id=pf.source_line_id AND s.fact_direction=\'forward\' AND s.status=\'effective\'')
            ->leftJoin('cashier_v3_report_sale_dimension_fact d','d.tenant_id=pf.tenant_id AND d.sale_fact_id=s.fact_id')
            ->where('pf.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('pf.store_id',$stores)->whereBetween('pf.business_date',[$range['start'],$range['end']])
            ->where('pf.performance_type','sales_performance_allocated')->where('pf.status','effective')
            ->where('pf.employee_id','>',0)
            ->field('pf.id,pf.fact_id,pf.reversal_of,pf.fact_direction,pf.store_id,pf.employee_id,pf.employee_name_snapshot employee_name,pf.business_date,pf.checkout_request_id,pf.source_line_id,pf.amount_cents,s.member_id,s.member_name_snapshot member_name,s.store_name_snapshot store_name,s.organization_name_snapshot company_name,s.source_type,d.item_name_snapshot item_name,d.category_path_snapshot category_path')
            ->order('pf.id','asc')->select()->toArray();
        $salesReversed=[];
        foreach($salesRows as $sale){if((string)$sale['fact_direction']==='reversal'&&trim((string)$sale['reversal_of'])!=='')$salesReversed[(string)$sale['reversal_of']]=true;}
        $salesGrouped=[];
        foreach($salesRows as $sale){
            if((string)$sale['fact_direction']!=='forward'||isset($salesReversed[(string)$sale['fact_id']]))continue;
            $salesKey=(int)$sale['store_id'].'|'.(int)$sale['employee_id'].'|'.(string)$sale['checkout_request_id'].'|'.(string)$sale['source_line_id'];
            if(!isset($salesGrouped[$salesKey])){$salesGrouped[$salesKey]=$sale;$salesGrouped[$salesKey]['amount_cents']=0;}
            $salesGrouped[$salesKey]['amount_cents']+=(int)$sale['amount_cents'];
        }
        foreach($salesGrouped as $salesKey=>$sale){
            if(isset($grouped[$salesKey])){$grouped[$salesKey]['cash_cents']=($grouped[$salesKey]['cash_cents']??0)+(int)$sale['amount_cents'];continue;}
            $sale['row_key']=(string)($sale['fact_id']??'');
            $sale['labor_fee_amount_cents']=0;$sale['project_count_half_units']=0;$sale['has_explicit_project_count']=true;
            // 销售事实只产生现金业绩，不产生服务消耗；不能把销售金额复用为消耗金额。
            $sale['consumption_cents']=0;
            $sale['cash_cents']=(int)$sale['amount_cents'];$sale['member_id']=(int)($sale['member_id']??0);
            $sale['member_name']=(string)($sale['member_name']??'');$sale['store_name']=(string)($sale['store_name']??'');
            $sale['company_name']=(string)($sale['company_name']??'');$sale['item_name']=(string)($sale['item_name']??'');
            $sale['category_path']=(string)($sale['category_path']??'');$sale['source_type']=(string)($sale['source_type']??'');
            $grouped[$salesKey]=$sale;
        }
        $out=[];
        foreach($grouped as $row){
            $halfUnits=(int)$row['project_count_half_units'];
            $row['project_count']=$halfUnits/2;
            $row['consumption_cents']=array_key_exists('consumption_cents',$row)
                ? (int)$row['consumption_cents']
                : (int)$row['amount_cents'];
            $row['labor_fee_cents']=(int)$row['labor_fee_amount_cents'];
            $row['cash_cents']=(int)($row['cash_cents']??0);
            $row['card_type']='';$row['item_name']=(string)($row['item_name']??'');$row['category_path']=(string)($row['category_path']??'');
            if((int)$row['consumption_cents']===0&&(int)$row['labor_fee_cents']===0&&(float)$row['project_count']===0.0&&(int)$row['cash_cents']===0)continue;
            $out[]=$row;
        }
        return $out;
    }

    private function topCategory(string $path): string
    {
        $path=trim($path);if($path==='')return '未分类';$parts=preg_split('/\s*\/\s*/u',$path);return trim((string)($parts[0]??$path))?:'未分类';
    }

    private function countMembersAtLeast(array $facts,string $category,int $thresholdCents): int
    {
        $totals=[];foreach($facts as $f){if($this->topCategory((string)($f['category_path']??''))!==$category)continue;$id=(int)($f['member_id']??0);if($id>0)$totals[$id]=($totals[$id]??0)+(int)($f['amount_cents']??0);}return count(array_filter($totals,static fn(int $amount):bool=>$amount>=$thresholdCents));
    }
    private function range(array $range,array $input):array{$start=trim((string)($range['start']??$input['start_date']??''));$end=trim((string)($range['end']??$input['end_date']??''));if($start===''||$end===''){ $year=max(2000,(int)($input['year']??date('Y')));$start=$year.'-01-01';$end=$year.'-12-31'; }if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||$start>$end)throw new \InvalidArgumentException('日期范围不正确');return['start'=>$start,'end'=>$end];}
    private function result(string $title,array $columns,array $records,array $range):array{$summary=[];foreach($columns as $c){$summary[$c['key']]='-';if(!empty($c['summable'])){$sum=0;$has=false;$moneyKey=(bool)preg_match('/amount|cash|fee|labor|consumption|performance|target|unit_output|average_order|sales_efficiency/i',(string)$c['key']);$halfUnitKey=(string)$c['key']==='project_count';foreach($records as $r){$v=$r[$c['key']]??null;if($moneyKey&&is_string($v)&&preg_match('/^-?\d+(?:\.\d{1,2})?$/',$v)){ $sum+=$this->cents($v);$has=true; }elseif($halfUnitKey&&is_numeric($v)){ $sum+=(int)round((float)$v*2);$has=true; }elseif(!$moneyKey&&is_numeric($v)){ $sum+=(int)$v;$has=true; }}$summary[$c['key']]=$has?($moneyKey?$this->money($sum):($halfUnitKey?number_format($sum/2,1,'.',''):(string)$sum)):'0';}}return['title'=>$title,'columns'=>$columns,'records'=>array_values($records),'summary_row'=>$summary,'column_groups'=>[],'metric_version'=>self::METRIC_VERSION,'data_as_of'=>date('c'),'aggregation_status'=>'caught_up','filters'=>['start_date'=>$range['start'],'end_date'=>$range['end']],'table_layout'=>['fixed'=>true,'summary_fixed'=>true]];}
    private function column(string $key,string $label,string $explanation,bool $summable=false,bool $fixed=false,int $width=120,array $drilldown=[],string $group=''):array{$c=['key'=>$key,'label'=>$label,'source_explanation'=>$explanation,'summable'=>$summable,'width'=>$width];if($fixed)$c['fixed']='left';if($group!=='')$c['group_label']=$group;if($drilldown)$c['drilldown']=$drilldown;return$c;}
    private function manualColumn(string $key,string $label,string $explanation,string $group=''):array{$c=$this->column($key,$label,$explanation,false,false,120,[],$group);$c['manual_input']=['subject_type'=>'phase_six_row','field_key'=>$key,'value_type'=>'text'];return$c;}
    private function money(int $cents):string{$negative=$cents<0;$cents=abs($cents);$value=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);$value=rtrim(rtrim($value,'0'),'.');return($negative?'-':'').($value===''?'0':$value);}
    private function ratio(int $num,int $den):string{return $den===0?'-':(string)round($num*100/$den,2).'%';}
    private function cents($value):int{$text=trim((string)$value);if($text===''||$text==='-')return 0;$negative=substr($text,0,1)==='-';$text=ltrim($text,'+-');$parts=explode('.',$text,2);$whole=(int)($parts[0]??0);$fraction=str_pad(substr((string)($parts[1]??''),0,2),2,'0');$result=$whole*100+(int)$fraction;return $negative?-abs($result):$result;}
    private function healthWithStaffing(array $stores, array $range): array
    {
        $result = $this->health($stores, $range);
        $beauticianPositionId=(int)Db::name('position')->where('name','美容师')->where('status',1)->value('id');
        $versions = $beauticianPositionId>0
            ? Db::name('staffing_quota')->where('tenant_id','0')->where('scope_type','store')->whereIn('scope_id',$stores)->where('position_id',$beauticianPositionId)->column('version','scope_id')
            : [];
        foreach ($result['records'] as &$row) {
            $storeId = (int)($row['store_id'] ?? 0);
            $row['store_id'] = $storeId;
            $row['annotation_subject_type'] = 'phase_six_row';
            $row['annotation_subject_key'] = 'phase_six:health:' . $storeId;
            $row['beautician_establishment_count_version'] = (int)($versions[$storeId] ?? 0);
        }
        unset($row);
        foreach ($result['columns'] as &$column) {
            if ((string)($column['key'] ?? '') === 'beautician_establishment_count') {
                $column = $this->manualColumn('beautician_establishment_count', '美容师编制人数', '每门店唯一当前编制值，不分时间。');
            }
        }
        unset($column);
        return $result;
    }

    private function trainingV2(array $stores, array $range): array
    {
        $rows = Db::name('system_store_staff')->alias('ss')
            ->leftJoin('employee e', 'e.id=ss.employee_id')
            ->leftJoin('system_store st', 'st.id=ss.store_id')
            ->whereIn('ss.store_id', $stores)->where('ss.status', 1)->where('ss.is_del', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->field('e.id,e.name,ss.join_date,ss.mentor_employee_id,ss.store_id,st.name AS store_name')
            ->order('ss.store_id', 'asc')->order('e.id', 'asc')->select()->toArray();
        $mentors = [];
        $mentorIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['mentor_employee_id'] ?? 0), $rows))));
        if ($mentorIds) $mentors = Db::name('employee')->whereIn('id', $mentorIds)->column('name', 'id');
        $out = []; $sequence = 1; $queryEnd = (string)$range['end'];
        foreach ($rows as $row) {
            $joinDate = (string)($row['join_date'] ?? '');
            $months = $joinDate !== '' ? max(0, ((int)date('Y', strtotime($queryEnd)) - (int)date('Y', strtotime($joinDate))) * 12 + (int)date('m', strtotime($queryEnd)) - (int)date('m', strtotime($joinDate))) : 0;
            if ($joinDate !== '' && (int)date('d', strtotime($joinDate)) > 15) $months = max(0, $months - 1);
            $out[] = [
                'sequence' => $sequence++, 'store_id' => (int)$row['store_id'], 'company_name' => '',
                'store_name' => (string)$row['store_name'], 'beautician_name' => (string)$row['name'],
                'entry_time' => $joinDate, 'mentor_name' => (string)($mentors[(int)($row['mentor_employee_id'] ?? 0)] ?? ''),
                'entry_cycle' => $months . '个月',
                'entry_level' => $months < 3 ? '实习' : ($months < 6 ? '初级' : ($months < 12 ? '中级' : '高级')),
                'exam_apply_time' => '', 'exam_level' => '', 'passed_level' => '', 'skill_score' => '', 'professional_score' => '',
                'in_service_3' => '-', 'in_service_6' => '-', 'in_service_9' => '-', 'in_service_12' => '-', 'in_service_over_year' => '-',
                'annotation_subject_type' => 'phase_six_row', 'annotation_subject_key' => 'phase_six:training:' . (int)$row['id'],
            ];
        }
        return $this->result('教培员工需求统计表', [
            $this->column('sequence','序号','页面稳定行号，不作为业务主键。'), $this->column('company_name','分公司','组织中的分公司统计维度。'),
            $this->column('store_name','门店','员工组织归属。'), $this->column('beautician_name','美容师','员工档案。'),
            $this->column('entry_time','入职时间','员工正式入职日期。'), $this->manualColumn('mentor_name','师傅','门店分配师傅关系。'),
            $this->column('entry_cycle','入职周期','按查询日期和每月15日规则计算。'), $this->column('entry_level','入职等级分类','按入职周期分级。'),
            $this->manualColumn('exam_apply_time','员工申请考试时间','员工手动输入。'), $this->manualColumn('exam_level','员工申请考试级别','员工手动输入。'),
            $this->manualColumn('passed_level','对应已考等级','教培人员手动输入。'), $this->manualColumn('skill_score','技能分数','教培人员手动输入。'),
            $this->manualColumn('professional_score','专业分数','教培人员手动输入。'), $this->column('in_service_3','3月是否在职','对应观察时点的历史员工状态。'),
            $this->column('in_service_6','6月是否在职','对应观察时点的历史员工状态。'), $this->column('in_service_9','9月是否在职','对应观察时点的历史员工状态。'),
            $this->column('in_service_12','12月是否在职','对应观察时点的历史员工状态。'), $this->column('in_service_over_year','1年以上是否在职','对应观察时点的历史员工状态。'),
        ], $out, $range);
    }
}
