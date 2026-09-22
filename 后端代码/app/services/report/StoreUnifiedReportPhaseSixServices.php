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
            case 'phase_six_garden_item_analysis': $result = $this->garden($stores, $range); break;
            case 'phase_six_monthly_featured_item': $result = $this->featured($stores, $range); break;
            case 'phase_six_headquarters_acquisition': $result = $this->headquarters($stores, $range); break;
            case 'phase_six_other_multi_payment': $result = $this->multiPayment($stores, $range); break;
            case 'phase_six_salary_summary': $result = $this->salarySummary($stores, $range, $input); break;
            case 'phase_six_salary_detail': $result = $this->salaryDetail($stores, $range, $input); break;
            case 'phase_six_training_employee': $result = $this->trainingV2($stores, $range); break;
            case 'phase_six_acquisition_source': $result = $this->acquisitionSource($stores, $range); break;
            case 'phase_six_human_store_health': return $this->healthWithStaffing($stores, $range);
            default: throw new \InvalidArgumentException('不支持的第六阶段报表类型');
        }
        // 事实默认值先生成，手动补充值最后投影；查询和导出共用同一读回路径。
        return $this->withManualAnnotations($report, $result, $stores, !empty($input['_internal_all']));
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
                'store_id' => (int)$fact['store_id'], 'source_line_id' => (string)$fact['source_line_id'],
                'annotation_subject_type' => 'phase_six_row',
                'annotation_subject_key' => 'phase_six:garden:' . (string)$fact['allocation_id'],
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
        foreach ($groups as &$row) {
            // 目标按门店和自然月归属；改变查询起止日不应产生另一份目标。
            $row['annotation_subject_type'] = 'phase_six_row';
            $row['annotation_subject_key'] = 'phase_six:featured:' . (int)$row['store_id'] . ':' . substr($range['end'], 0, 7);
        }
        unset($row);
        return $this->result('月主推数据统计表',$columns,array_values($groups),$range);
    }

    private function headquarters(array $stores, array $range): array
    {
        $facts=$this->cashFacts($stores,$range);$sources=['B 导购引流','E 地推拓客','H 沉睡唤醒','G 异业收客','F 美带新客','C 老带新客'];$rows=[];
        foreach($sources as $source){$members=[];$amount=0;$orders=[];foreach($facts as $f){if((string)($f['source_name']??'系统词')!==$source)continue;$id=(int)$f['member_id'];if($id>0){$members[$id]=true;$orders[$id][(string)$f['order_id']]=true;}$amount+=(int)$f['amount_cents'];} $twice=[];$twiceAmount=0;foreach($orders as $id=>$orderSet)if(count($orderSet)>=2){$twice[$id]=true;foreach($facts as $f)if((int)$f['member_id']===$id)$twiceAmount+=(int)$f['amount_cents'];}$rows[]=['source'=>$source,'member_count'=>count($members),'annual_amount'=>$this->money($amount),'two_order_member_count'=>count($twice),'two_order_amount'=>$this->money($twiceAmount)];}
        return $this->result('总部拓客数据统计表',[$this->column('source','来源','年度第一笔正常销售订单冻结的来源。',false,true),$this->column('member_count','会员人数','该来源下有效会员人数。',true),$this->column('annual_amount','年度业绩','年度现金业绩事实合计。',true),$this->column('two_order_member_count','二单以上现金人数','年度至少两笔有效现金销售的会员数。',true),$this->column('two_order_amount','二单以上现金业绩','满足二单条件会员年度现金业绩合计。',true)],$rows,$range);
    }

    private function multiPayment(array $stores, array $range): array
    {
        $facts = array_values(array_filter($this->cashFacts($stores, $range), static fn(array $fact): bool => (int)$fact['is_experience'] === 1));
        $rows = [];
        foreach ($facts as $fact) {
            // 每笔收款分摊事实有独立稳定键；同一销售明细的多种收款不可共用手动值。
            $rows[] = [
                'store_id' => (int)$fact['store_id'], 'source_line_id' => (string)$fact['source_line_id'],
                'annotation_subject_type' => 'phase_six_row',
                'annotation_subject_key' => 'phase_six:payment:' . (string)$fact['allocation_id'],
                'company_name' => $fact['company_name'], 'store_name' => $fact['store_name'],
                'business_date' => $fact['business_date'], 'customer_name' => $fact['member_name'],
                'employee_name' => $fact['salesperson_name'], 'category' => $fact['category_path'],
                'item_name' => $fact['item_name'], 'quantity' => (int)$fact['quantity'],
                'cash_amount' => $this->money((int)$fact['amount_cents']), 'row_key' => $fact['allocation_id'],
            ];
        }
        return $this->result('其他多收款业绩表', [
            $this->column('company_name','分公司','销售事实组织快照。',false,true),
            $this->column('store_name','门店','销售事实门店快照。',false,true),
            $this->column('business_date','日期','成功结账业务日期。'),
            $this->column('customer_name','顾客姓名','会员或顾客快照。'),
            $this->column('employee_name','员工姓名','销售或操作员工快照。'),
            $this->column('category','商品分类','销售明细历史分类快照。'),
            $this->column('item_name','商品名称','销售明细名称。'),
            $this->manualColumn('unit_price','单价','手动输入并独立保存。'),
            $this->column('quantity','数量','销售明细数量。',true),
            $this->column('cash_amount','现金业绩','体验现金业绩事实。',true),
            $this->manualColumn('领取日期','领取日期','手动输入并独立保存。'),
            $this->manualColumn('领取数量','领取数量','手动输入并独立保存。'),
            $this->manualColumn('备注','备注','手动输入并独立保存。'),
        ], $rows, $range);
    }

    private function salaryDetail(array $stores,array $range,array $input):array
    {
        $employeeName=$this->salaryEmployeeName($input);
        $employeeId=$this->salaryDrilldownId($input,'salary_employee_id');
        $storeId=$this->salaryDrilldownId($input,'salary_store_id');
        $categoryKey=trim((string)($input['salary_category_key']??''));
        if($categoryKey!=='' && !preg_match('/^salary_category_cash_(?:\d+|historical_\d+|unclassified)$/D',$categoryKey))
            throw new \InvalidArgumentException('商品分类筛选条件不正确');
        if($categoryKey!=='' && ($employeeId===0 || $storeId===0))
            throw new \InvalidArgumentException('请从员工薪资汇总表选择员工和门店后查看分类明细');
        // 下钻使用服务端许可门店内的稳定 ID，不能用同名员工或页面文字扩大查询范围。
        if($storeId>0 && !in_array($storeId,$stores,true))throw new \InvalidArgumentException('无权限查看该门店的工资明细');
        $facts=$this->filterSalaryFactsByEmployeeName($this->salaryFactsWithServiceCustomerMetrics($stores,$range),$employeeName);
        if($employeeId>0)$facts=array_values(array_filter($facts,static fn(array $fact):bool=>(int)$fact['employee_id']===$employeeId));
        if($storeId>0)$facts=array_values(array_filter($facts,static fn(array $fact):bool=>(int)$fact['store_id']===$storeId));
        $categories=$categoryKey!==''?$this->salaryCategoryDefinitions($this->observedSalaryCategories($facts)):null;
        $categoryLabel='';
        if($categoryKey!=='')foreach($categories['columns'] as $column){
            if($column['key']===$categoryKey){$categoryLabel=(string)$column['label'];break;}
        }
        $rows = [];
        foreach ($facts as $f) {
            $cashCents=(int)($f['cash_cents']??0);
            if($categoryKey!==''){
                // 卡项可跨多个二级分类；明细只展示所点分类的分摊金额，合计才与汇总单元格相等。
                $projected=$this->salaryCategoryAmountsByColumn($f,$categories['targets']);
                $cashCents=(int)($projected[$categoryKey]??0);
                if($cashCents===0)continue;
            }
            $rows[] = [
                'business_date' => $f['business_date'], 'company_name' => $f['company_name'],
                'store_name' => $f['store_name'], 'employee_name' => $f['employee_name'],
                'member_name' => $f['member_name'], 'card_type' => (string)($f['card_type'] ?: '-'),
                'performance_category' => $categoryKey!==''?$categoryLabel:$f['category_path'], 'detail' => $f['item_name'],
                'project_count' => (string)$f['project_count'],
                // The common reader has already chosen the one stable labor
                // line that carries a daily visit share.  All other project
                // lines for the same employee/customer/day remain zero.
                'service_visit_count' => $this->tenth((int)($f['service_visit_tenths'] ?? 0)),
                'consumption_amount' => $this->money((int)$f['consumption_cents']),
                'cash_amount' => $this->money($cashCents), 'cash_amount_cents' => $cashCents,
                'labor_fee' => $this->money((int)$f['labor_fee_cents']), 'row_key' => $f['row_key'],
                'store_id' => (int)$f['store_id'], 'employee_id' => (int)$f['employee_id'],
                'source_line_id' => (string)$f['source_line_id'],
                'annotation_subject_type' => 'phase_six_row',
                'annotation_subject_key' => 'phase_six:salary:' . (string)$f['row_key'],
            ];
        }
        return $this->salaryResult('员工薪资明细月报表',[
            $this->column('business_date','日期','该笔服务、核销或销售计入工资的日期；后来已作废的记录不再统计。'),
            $this->column('company_name','分公司','该笔业务发生门店所属的分公司。',false,true),
            $this->column('store_name','门店','该笔业务实际发生的门店。',false,true),
            $this->column('employee_name','员工','该笔项目的手艺人，或现金业绩分配到的员工；按结账时记录的姓名显示。',false,true),
            $this->column('member_name','会员','该笔业务对应的会员或顾客姓名。'),
            $this->column('card_type','卡类型','使用会员卡内项目时显示对应卡类型；现金购买或无卡项目显示“-”。'),
            $this->column('performance_category','业绩分类','普通明细显示结账时的分类；从汇总表点击分类金额进入时，显示所点分类，卡项可按卡内项目拆分。'),
            $this->column('detail','明细','该笔业务对应的具体服务项目、卡项或产品名称。'),
            $this->column('project_count','项目数','该员工实际承担的项目数量；多人服务按分配比例拆分，可显示小数，人工调整后按已保存数值计算。',true),
            $this->column('service_visit_count','服务人次','本人服务按“同一会员+同一日期”计一次；朋友算和游客每条有效服务记录计一次，朋友不算不计入；多人服务按实际分配计算。',true),
            $this->column('consumption_amount','消耗业绩','服务完成或卡项核销后计入该员工的消耗金额；作废后不再统计。',true),
            $this->column('cash_amount','现金业绩','成功收款后分配给该员工的金额；从汇总表点击分类金额进入时，只显示该分类分得的金额；作废后不再统计。',true),
            $this->column('labor_fee','手工费','结账时分配给该手艺人的手工费；作废后不再统计。',true),
            $this->manualColumn('备注','备注','有权限的用户手动填写，保存后刷新页面仍会显示。')
        ],$rows,$range,$employeeName,$categoryKey!=='');
    }

    private function salarySummary(array $stores,array $range,array $input):array
    {
        $employeeName=$this->salaryEmployeeName($input);
        $rows=[];$observedCategories=[];
        foreach($this->filterSalaryFactsByEmployeeName(
            $this->salaryFactsWithServiceCustomerMetrics($stores,$range),$employeeName
        ) as $f){
            $key=(int)$f['store_id'].'|'.(int)$f['employee_id'];
            if(!isset($rows[$key]))$rows[$key]=['store_id'=>(int)$f['store_id'],'employee_id'=>(int)$f['employee_id'],'company_name'=>$f['company_name'],'store_name'=>$f['store_name'],'employee_name'=>$f['employee_name'],'project_count_micros'=>0,'service_visit_tenths'=>0,'service_people_tenths'=>0,'consumption_cents'=>0,'cash_cents'=>0,'labor_fee_cents'=>0];
            // 工资项目数是人员服务份额，不是销售数量；用百万分位整数累加，保留入库 DECIMAL(20,6) 的精度。
            $rows[$key]['project_count_micros']+=$this->projectCountMicros((string)$f['project_count']);
            $rows[$key]['service_visit_tenths']+=(int)($f['service_visit_tenths'] ?? 0);
            $rows[$key]['consumption_cents']+=(int)$f['consumption_cents'];
            $rows[$key]['cash_cents']+=(int)($f['cash_cents'] ?? 0);
            // Sales performance belongs to the employee and sale line; card
            // amounts have already been split by their contained projects.
            $categoryAmounts=(array)($f['sales_category_cents']??[]);
            $unclassified=(int)($f['cash_cents']??0)-array_sum($categoryAmounts);
            if($unclassified!==0)$categoryAmounts[0]=($categoryAmounts[0]??0)+$unclassified;
            foreach($categoryAmounts as $categoryId=>$cents){
                $rows[$key]['sales_category_cents'][$categoryId]=($rows[$key]['sales_category_cents'][$categoryId]??0)+(int)$cents;
                $observedCategories[$categoryId]=(string)($f['sales_category_paths'][$categoryId]??'');
            }
            $rows[$key]['labor_fee_cents']+=(int)$f['labor_fee_cents'];
        }
        $categories=$this->salaryCategoryDefinitions($observedCategories);
        // Service people is a range-level deduplicated metric.  It must come
        // from the common reader once per store/employee, rather than by
        // adding detail rows (which would turn a multi-day member into many
        // people).
        $summaryMetrics = $this->serviceCustomerMetrics($stores, $range)['summary_by_store_employee'];
        foreach($rows as $key=>&$r){
            $r['service_people_tenths']=(int)($summaryMetrics[$key]['people_tenths'] ?? 0);
            $r['project_count']=$this->projectCountText((int)$r['project_count_micros']);
            $r['service_visit_count']=$this->tenth((int)$r['service_visit_tenths']);
            $r['service_people_count']=$this->tenth((int)$r['service_people_tenths']);
            $r['consumption_amount']=$this->money($r['consumption_cents']);$r['labor_fee']=$this->money($r['labor_fee_cents']);
            foreach($categories['columns'] as $categoryColumn)$r[$categoryColumn['key']]='0';
            $projectedCents=$this->salaryCategoryAmountsByColumn($r,$categories['targets']);
            foreach($projectedCents as $columnKey=>$cents)$r[$columnKey]=$this->money($cents);
            unset($r['project_count_micros'],$r['service_visit_tenths'],$r['service_people_tenths'],$r['consumption_cents'],$r['cash_cents'],$r['labor_fee_cents'],$r['sales_category_cents']);
        }unset($r);
        $columns=[
            $this->column('company_name','分公司','该员工业务发生门店所属的分公司。',false,true),
            $this->column('store_name','门店','该员工业务实际发生的门店。',false,true),
            $this->column('employee_name','员工','该行汇总的员工，按业务结账时记录的姓名显示；页面可输入姓名中的任意文字进行筛选。',false,true),
            $this->column('project_count','项目数','该员工在明细表中的项目数合计；多人服务和人工调整可产生小数。',true),
            $this->column('service_visit_count','服务人次','该员工在所选日期内的有效服务人次合计；计算规则与明细表一致。',true),
            $this->column('service_people_count','服务人数','该员工在所选日期内服务的顾客数；本人服务的会员跨日期只计一人，朋友算和游客每条有效服务记录分别计算，多人服务按实际分配计算。',true),
            $this->column('consumption_amount','消耗','该员工在所选日期内，服务完成或卡项核销后计入的消耗金额；作废后不再统计。',true),
            $this->column('labor_fee','手工','该员工在所选日期内被分配的手工费合计；作废后不再统计。',true),
        ];
        foreach($categories['columns'] as $categoryColumn){
            // 分类列自己声明精确下钻条件；页面仅传递 ID 与列键，不推断名称或重算金额。
            $categoryColumn['drilldown']=[
                'report'=>'phase_six_salary_detail',
                'params'=>['employee_name'=>'','salary_category_key'=>$categoryColumn['key']],
                'param_map'=>['salary_employee_id'=>'employee_id','salary_store_id'=>'store_id'],
            ];
            $columns[]=$categoryColumn;
        }
        return $this->salaryResult('员工薪资汇总月报表',$columns,array_values($rows),$range,$employeeName);
    }

    /** Employee-name filtering always uses the immutable fact snapshot shown in the result. */
    private function salaryEmployeeName(array $input): string
    {
        $name=trim((string)($input['employee_name']??''));
        if(mb_strlen($name)>64)throw new \InvalidArgumentException('员工姓名最多输入64个字');
        return $name;
    }

    /** Validate stable drilldown IDs before applying them within the already authorized store scope. */
    private function salaryDrilldownId(array $input,string $key): int
    {
        $raw=trim((string)($input[$key]??''));
        if($raw==='' || $raw==='0')return 0;
        if(!ctype_digit($raw) || (int)$raw<=0)throw new \InvalidArgumentException('员工或门店筛选条件不正确');
        return (int)$raw;
    }

    /** Keep the same observed category mapping for summary columns and category-specific detail. */
    private function observedSalaryCategories(array $facts): array
    {
        $observed=[];
        foreach($facts as $fact){
            $amounts=(array)($fact['sales_category_cents']??[]);
            $unclassified=(int)($fact['cash_cents']??0)-array_sum($amounts);
            if($unclassified!==0)$amounts[0]=($amounts[0]??0)+$unclassified;
            foreach($amounts as $id=>$cents)$observed[$id]=(string)($fact['sales_category_paths'][$id]??'');
        }
        return $observed;
    }

    /** Project one salary fact by the same current/historical category targets used in the summary. */
    private function salaryCategoryAmountsByColumn(array $fact,array $targets): array
    {
        $amounts=(array)($fact['sales_category_cents']??[]);
        $unclassified=(int)($fact['cash_cents']??0)-array_sum($amounts);
        if($unclassified!==0)$amounts[0]=($amounts[0]??0)+$unclassified;
        $projected=[];
        foreach($amounts as $id=>$cents){
            $key=$targets[$id]??'salary_category_cash_unclassified';
            $projected[$key]=($projected[$key]??0)+(int)$cents;
        }
        return $projected;
    }

    /**
     * Apply the same partial-name match before either salary projection is
     * aggregated, so detail rows, summary rows, totals and exports stay equal.
     */
    private function filterSalaryFactsByEmployeeName(array $facts,string $employeeName): array
    {
        if($employeeName==='')return $facts;
        return array_values(array_filter($facts,static fn(array $fact):bool=>
            mb_stripos((string)($fact['employee_name']??''),$employeeName)!==false
        ));
    }

    /** Add the shared text filter contract without making the frontend infer it from report names. */
    private function salaryResult(string $title,array $columns,array $records,array $range,string $employeeName,bool $categoryDrilldown=false): array
    {
        $result=$this->result($title,$columns,$records,$range);
        if($categoryDrilldown){
            // Money is rounded only for display; the clicked category's total stays cent-accurate internally.
            $result['summary_row']['cash_amount']=$this->money(array_sum(array_map(
                static fn(array $row):int=>(int)($row['cash_amount_cents']??0),$records
            )));
        }
        $result['filter_schema']=[[
            // 页面隐藏重复的“员工”可见标题，但保留语义标题供读屏和键盘操作识别。
            'key'=>'employee_name','label'=>'员工','show_label'=>false,'aria_label'=>'员工姓名',
            'type'=>'text','placeholder'=>'输入员工姓名',
        ]];
        $result['filters']['employee_name']=$employeeName;
        return $result;
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

    /**
     * Read the immutable component-category facts only for card sale lines in
     * the already permission-scoped salary facts. The card's outer category is
     * never a substitute for its contained-project allocation.
     */
    private function salaryCardCategoryAllocations(array $salesRows): array
    {
        $saleFactIds=[];
        foreach($salesRows as $sale){
            if((string)($sale['source_type']??'')==='card' && trim((string)($sale['sale_fact_id']??''))!=='')
                $saleFactIds[(string)$sale['sale_fact_id']]=true;
        }
        if($saleFactIds===[])return [];
        $facts=Db::name('cashier_v3_card_sale_category_allocation_fact')
            ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sale_fact_id',array_keys($saleFactIds))->where('status','effective')
            ->field('sale_fact_id,category_id_snapshot,category_path_snapshot,cash_performance_amount_cents,sale_amount_cents,configured_amount_cents')
            ->select()->toArray();
        $bySale=[];
        foreach($facts as $fact)$bySale[(string)$fact['sale_fact_id']][]=$fact;
        return $bySale;
    }

    /**
     * Allocate one employee's sales-performance cents to sale categories.
     * The final stable category receives the integer-cent remainder; a card
     * lacking component facts is reported as unclassified, not as 卡项.
     */
    private function salarySaleCategoryAmounts(array $sale,array $cardFacts): array
    {
        $amount=(int)($sale['amount_cents']??0);
        if((string)($sale['source_type']??'')!=='card'){
            $id=(int)($sale['category_id']??0);
            return [$id=>['cents'=>$amount,'path'=>(string)($sale['category_path']??'')]];
        }
        if($cardFacts===[])return [0=>['cents'=>$amount,'path'=>'未分类']];
        $weights=[];$paths=[];
        foreach(['cash_performance_amount_cents','sale_amount_cents','configured_amount_cents'] as $weightField){
            $weights=[];
            foreach($cardFacts as $fact){
                $id=(int)($fact['category_id_snapshot']??0);
                $weights[$id]=($weights[$id]??0)+abs((int)($fact[$weightField]??0));
                $paths[$id]=(string)($fact['category_path_snapshot']??'');
            }
            if(array_sum($weights)>0)break;
        }
        ksort($weights,SORT_NUMERIC);
        $total=array_sum($weights);
        if($total===0){foreach($weights as &$weight)$weight=1;unset($weight);$total=count($weights);}
        $remaining=abs($amount);$sign=$amount<0?-1:1;$lastId=array_key_last($weights);$result=[];
        foreach($weights as $id=>$weight){
            $part=$id===$lastId?$remaining:(int)bcdiv(bcmul((string)abs($amount),(string)$weight,0),(string)$total,0);
            $remaining-=$part;
            $result[$id]=['cents'=>$sign*$part,'path'=>$paths[$id]??''];
        }
        return $result;
    }

    /**
     * Current visible product categories define zero-sales columns. Facts
     * outside the current hierarchy retain a separate historical column so
     * their amount is never silently moved into a different configured name.
     *
     * @return array{columns:array<int,array>,targets:array<int|string,string>}
     */
    private function salaryCategoryDefinitions(array $observed): array
    {
        $configured=Db::name('store_product_category')->where('type',0)->where('relation_id',0)
            ->field('id,pid,cate_name,is_show')->select()->toArray();
        return $this->buildSalaryCategoryDefinitions($configured,$observed);
    }

    /** Pure category projection shared by the query and its regression test. */
    private function buildSalaryCategoryDefinitions(array $configured,array $observed): array
    {
        $byId=[];$visible=[];$visibleChildren=[];
        foreach($configured as $category){
            $id=(int)($category['id']??0);if($id<=0)continue;
            $byId[$id]=$category;
            if((int)($category['is_show']??0)===1)$visible[$id]=true;
        }
        foreach(array_keys($visible) as $id){
            $parent=(int)($byId[$id]['pid']??0);
            if($parent>0 && (int)($byId[$parent]['pid']??-1)===0)$visibleChildren[$parent]=true;
        }
        $definitions=[];$targets=[];
        foreach(array_keys($visible) as $id){
            $chain=$this->salaryCategoryChain($id,$byId);
            if($chain===[])continue;
            $root=(int)$chain[0]['id'];$second=(int)($chain[1]['id']??0);
            if($second===0 && isset($visibleChildren[$root]))continue;
            $effective=$second>0 && isset($visible[$second])?$second:$root;
            if(!isset($visible[$effective]) || isset($definitions[$effective]))continue;
            $label=$this->salaryCategoryLabel($chain,$effective);
            $definitions[$effective]=['key'=>'salary_category_cash_'.$effective,'label'=>$label,'id'=>$effective];
        }
        foreach($observed as $rawId=>$path){
            $id=(int)$rawId;$chain=$id>0?$this->salaryCategoryChain($id,$byId):[];
            $root=(int)($chain[0]['id']??0);$second=(int)($chain[1]['id']??0);
            $effective=$second>0 && isset($visible[$second])?$second:$root;
            if($id>0 && $effective>0 && isset($definitions[$effective])
                && !($second===0 && isset($visibleChildren[$root]))){
                $targets[$rawId]=$definitions[$effective]['key'];continue;
            }
            $label=$id===0?'未分类':$this->salaryCategoryLabel($chain,$id);
            if($label==='')$label=$this->salaryTwoLevelPath((string)$path);
            if($label==='')$label='未分类';
            if($second===0 && $root>0 && isset($visibleChildren[$root]))$label.='/未细分';
            $key=$id>0?'salary_category_cash_historical_'.$id:'salary_category_cash_unclassified';
            $definitions[$key]=['key'=>$key,'label'=>$label,'id'=>$id];$targets[$rawId]=$key;
        }
        uasort($definitions,static fn(array $a,array $b):int=>strcmp($a['label'],$b['label'])?:strcmp($a['key'],$b['key']));
        $columns=[];
        foreach($definitions as $definition){
            $explanation=$definition['key']==='salary_category_cash_unclassified'
                ? '已分配给该员工，但项目或产品未设置可用分类的现金业绩；不会按卡项名称自动猜测分类。'
                : '该分类下成功收款并分配给该员工的现金业绩；卡项金额按卡内项目的分类和金额比例拆分，作废后不再统计。';
            $columns[]=$this->column($definition['key'],$definition['label'],$explanation,true,false,140,[],'商品分类业绩');
        }
        return ['columns'=>$columns,'targets'=>$targets];
    }

    /** Build a bounded ancestry path; invalid or cyclic configuration falls back to the fact snapshot. */
    private function salaryCategoryChain(int $id,array $byId): array
    {
        $chain=[];$seen=[];
        while($id>0 && isset($byId[$id]) && !isset($seen[$id]) && count($chain)<12){
            $seen[$id]=true;$chain[]=$byId[$id];$id=(int)($byId[$id]['pid']??0);
        }
        return $id===0?array_reverse($chain):[];
    }

    private function salaryCategoryLabel(array $chain,int $effective): string
    {
        if($chain===[])return '';
        $root=trim((string)($chain[0]['cate_name']??''));
        if($effective===(int)($chain[0]['id']??0))return $root;
        $second=$chain[1]??null;
        return $second && $effective===(int)$second['id']?$root.'/'.trim((string)$second['cate_name']):'';
    }

    private function salaryTwoLevelPath(string $path): string
    {
        $parts=array_values(array_filter(array_map('trim',preg_split('/\s*\/\s*/u',$path)?:[]),static fn(string $part):bool=>$part!==''));
        return implode('/',array_slice($parts,0,2));
    }

    private function salaryFacts(array $stores,array $range):array
    {
        $rows=Db::name('cashier_v3_performance_fact')->alias('pf')
            ->leftJoin('cashier_v3_entitlement_service_fact sv','sv.tenant_id=pf.tenant_id AND sv.checkout_request_id=pf.checkout_request_id AND sv.source_line_id=pf.source_line_id AND sv.service_status=\'completed\'')
            ->where('pf.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('pf.store_id',$stores)->whereBetween('pf.business_date',[$range['start'],$range['end']])
            ->where('pf.performance_type','labor_performance_allocated')->where('pf.status','effective')
            ->field('pf.id,pf.fact_id,pf.reversal_of,pf.fact_id row_key,pf.store_id,pf.employee_id,pf.employee_name_snapshot employee_name,pf.business_date,pf.checkout_request_id,pf.source_line_id,pf.amount_cents,pf.labor_fee_amount_cents,pf.project_count_half_units,pf.project_count_decimal,pf.rule_code_snapshot,pf.fact_direction,sv.service_fact_id,sv.member_id,sv.member_name_snapshot member_name,sv.store_name_snapshot store_name,sv.organization_name_snapshot company_name,sv.quantity,sv.project_count,sv.project_name_snapshot item_name,sv.project_category_name_snapshot category_path,sv.source_document_type source_type')
            ->order('pf.id','asc')
            ->select()->toArray();
        $reversed=$this->reversedPerformanceFactIds($rows);
        $grouped=[];
        foreach($rows as $row){
            if((string)$row['fact_direction']!=='forward'||isset($reversed[(string)$row['fact_id']]))continue;
            $key=(int)$row['store_id'].'|'.(int)$row['employee_id'].'|'.(string)$row['checkout_request_id'].'|'.(string)$row['source_line_id'];
            if(!isset($grouped[$key])){
                $grouped[$key]=$row;
                $grouped[$key]['amount_cents']=0;
                $grouped[$key]['labor_fee_amount_cents']=0;
                $grouped[$key]['project_count_half_units']=0;
                $grouped[$key]['project_count_decimal_micros']=0;
                $grouped[$key]['legacy_project_count_half_units']=0;
                $grouped[$key]['has_project_count_decimal']=false;
                $grouped[$key]['has_explicit_project_count']=false;
            }
            $grouped[$key]['amount_cents']+=(int)$row['amount_cents'];
            $grouped[$key]['labor_fee_amount_cents']+=(int)$row['labor_fee_amount_cents'];
            $grouped[$key]['project_count_half_units']+=(int)$row['project_count_half_units'];
            if($row['project_count_decimal']!==null&&(string)$row['project_count_decimal']!==''){
                // 新事实以员工服务项目数小数快照为准；即使明确保存 0，也不能退回旧半项目数或销售数量。
                $grouped[$key]['project_count_decimal_micros']+=$this->projectCountMicros((string)$row['project_count_decimal']);
                $grouped[$key]['has_project_count_decimal']=true;
            }else{
                // 同一服务行混有新旧事实时，仅无小数快照的旧事实使用半项目数，避免覆盖或重复计算。
                $grouped[$key]['legacy_project_count_half_units']+=(int)$row['project_count_half_units'];
            }
            $grouped[$key]['has_explicit_project_count']=$grouped[$key]['has_explicit_project_count']
                ||(string)$row['rule_code_snapshot']==='SERVICE-RECORD-CRAFTSMAN-ADJUST-V1'
                ||(int)$row['project_count_half_units']!==0
                ||($row['project_count_decimal']!==null&&(string)$row['project_count_decimal']!=='');
            foreach(['row_key','service_fact_id','employee_name','member_id','member_name','store_name','company_name','item_name','category_path','source_type'] as $field)$grouped[$key][$field]=$row[$field]??$grouped[$key][$field]??'';
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
            ->field('pf.id,pf.fact_id,pf.reversal_of,pf.fact_direction,pf.store_id,pf.employee_id,pf.employee_name_snapshot employee_name,pf.business_date,pf.checkout_request_id,pf.source_line_id,pf.amount_cents,s.fact_id sale_fact_id,s.member_id,s.member_name_snapshot member_name,s.store_name_snapshot store_name,s.organization_name_snapshot company_name,s.source_type,d.item_name_snapshot item_name,d.category_id_snapshot category_id,d.category_path_snapshot category_path')
            ->order('pf.id','asc')->select()->toArray();
        $cardAllocations=$this->salaryCardCategoryAllocations($salesRows);
        $salesReversed=$this->reversedPerformanceFactIds($salesRows);
        $salesGrouped=[];
        foreach($salesRows as $sale){
            if((string)$sale['fact_direction']!=='forward'||isset($salesReversed[(string)$sale['fact_id']]))continue;
            $allocation=$this->salarySaleCategoryAmounts($sale,$cardAllocations[(string)($sale['sale_fact_id']??'')]??[]);
            $salesKey=(int)$sale['store_id'].'|'.(int)$sale['employee_id'].'|'.(string)$sale['checkout_request_id'].'|'.(string)$sale['source_line_id'];
            if(!isset($salesGrouped[$salesKey])){$salesGrouped[$salesKey]=$sale;$salesGrouped[$salesKey]['amount_cents']=0;$salesGrouped[$salesKey]['sales_category_cents']=[];$salesGrouped[$salesKey]['sales_category_paths']=[];}
            $salesGrouped[$salesKey]['amount_cents']+=(int)$sale['amount_cents'];
            foreach($allocation as $categoryId=>$part){
                $salesGrouped[$salesKey]['sales_category_cents'][$categoryId]=($salesGrouped[$salesKey]['sales_category_cents'][$categoryId]??0)+(int)$part['cents'];
                $salesGrouped[$salesKey]['sales_category_paths'][$categoryId]=(string)$part['path'];
            }
        }
        foreach($salesGrouped as $salesKey=>$sale){
            if(isset($grouped[$salesKey])){
                $grouped[$salesKey]['cash_cents']=($grouped[$salesKey]['cash_cents']??0)+(int)$sale['amount_cents'];
                $grouped[$salesKey]['sales_category_cents']=$sale['sales_category_cents'];
                $grouped[$salesKey]['sales_category_paths']=$sale['sales_category_paths'];
                continue;
            }
            $sale['row_key']=(string)($sale['fact_id']??'');
            $sale['labor_fee_amount_cents']=0;$sale['project_count_half_units']=0;$sale['project_count_decimal_micros']=0;$sale['has_project_count_decimal']=false;$sale['has_explicit_project_count']=true;
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
            $row['project_count']=$this->projectCountText(!empty($row['has_project_count_decimal'])
                ? (int)$row['project_count_decimal_micros']+(int)$row['legacy_project_count_half_units']*500000
                : $halfUnits*500000);
            $row['consumption_cents']=array_key_exists('consumption_cents',$row)
                ? (int)$row['consumption_cents']
                : (int)$row['amount_cents'];
            $row['labor_fee_cents']=(int)$row['labor_fee_amount_cents'];
            $row['cash_cents']=(int)($row['cash_cents']??0);
            $row['card_type']='';$row['item_name']=(string)($row['item_name']??'');$row['category_path']=(string)($row['category_path']??'');
            // A completed service with a zero amount still contributes to
            // service visits/people.  Pure empty sales rows remain hidden.
            if((int)$row['consumption_cents']===0&&(int)$row['labor_fee_cents']===0&&(float)$row['project_count']===0.0&&(int)$row['cash_cents']===0&&trim((string)($row['service_fact_id'] ?? ''))==='')continue;
            $out[]=$row;
        }
        return $out;
    }

    /**
     * Resolve terminal reversals independently of the selected report dates.
     * A salary query for the original sales day must not revive performance
     * from an order that was successfully voided on a later day.  Candidate
     * fact IDs are tenant-scoped first, so the unrestricted reversal date
     * lookup cannot cross customer instances or widen report permissions.
     *
     * @return array<string,bool> original fact ID => reversed
     */
    private function reversedPerformanceFactIds(array $candidateRows): array
    {
        $forwardIds=[];
        foreach($candidateRows as $row){
            $factId=trim((string)($row['fact_id']??''));
            if((string)($row['fact_direction']??'')==='forward'&&$factId!=='')$forwardIds[$factId]=true;
        }
        if($forwardIds===[])return [];
        $reversed=[];
        foreach(Db::name('cashier_v3_performance_fact')
            ->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('fact_direction','reversal')->where('status','effective')
            ->whereIn('reversal_of',array_keys($forwardIds))->field('reversal_of')->select()->toArray() as $row){
            $original=trim((string)($row['reversal_of']??''));
            if($original!=='')$reversed[$original]=true;
        }
        return $reversed;
    }

    /**
     * Attach the centrally calculated customer-service metrics to the
     * employee-level salary rows.  This class deliberately does not recalculate
     * service-object rules: the shared reader is the only owner of member,
     * guest, friend-counting and employee-split semantics.
     */
    private function salaryFactsWithServiceCustomerMetrics(array $stores, array $range): array
    {
        $metrics = $this->serviceCustomerMetrics($stores, $range);
        $byLaborKey = $metrics['detail_by_labor_key'];
        $facts = $this->salaryFacts($stores, $range);
        $present = [];
        foreach ($facts as &$fact) {
            $laborKey = $this->laborMetricKey($fact);
            $present[$laborKey] = true;
            $metric = $byLaborKey[$laborKey] ?? 0;
            // The detail map uses a scalar because each stable labor line can
            // receive at most one accumulated tenth-unit visit share.
            $fact['service_visit_tenths'] = is_array($metric)
                ? (int)($metric['visit_tenths'] ?? 0)
                : (int)$metric;
        }
        unset($fact);
        // Zero-price completed services intentionally have no performance
        // amount/fact.  The shared reader supplies their immutable service
        // context, so they still appear as a zero-amount salary-detail row
        // carrying the service-person share.
        foreach ($metrics['detail_context_by_labor_key'] as $laborKey => $context) {
            if (isset($present[$laborKey])) continue;
            $service = (array)($context['service'] ?? []);
            $facts[] = [
                'row_key' => 'service-customer:' . (string)($service['service_fact_id'] ?? $laborKey) . ':' . (int)($context['employee_id'] ?? 0),
                'store_id' => (int)($context['store_id'] ?? 0),
                'employee_id' => (int)($context['employee_id'] ?? 0),
                'employee_name' => (string)($context['employee_name'] ?? ''),
                'business_date' => (string)($context['business_date'] ?? ''),
                'checkout_request_id' => (string)($service['request_id'] ?? ''),
                'source_line_id' => (string)($service['source_line_id'] ?? ''),
                'service_fact_id' => (string)($service['service_fact_id'] ?? ''),
                'member_id' => (int)($service['member_id'] ?? 0), 'member_name' => (string)($service['member_name'] ?? ''),
                'store_name' => (string)($service['store_name'] ?? ''), 'company_name' => (string)($service['company_name'] ?? ''),
                'item_name' => (string)($service['item_name'] ?? ''), 'category_path' => (string)($service['category_path'] ?? ''),
                'source_type' => (string)($service['source_type'] ?? ''), 'card_type' => '',
                'project_count' => 0.0, 'consumption_cents' => 0, 'cash_cents' => 0, 'labor_fee_cents' => 0,
                'service_visit_tenths' => (int)($byLaborKey[$laborKey] ?? 0),
            ];
        }
        return $facts;
    }

    /**
     * @return array{
     *   detail_by_labor_key:array<string,int>,
     *   detail_context_by_labor_key:array<string,array<string,mixed>>,
     *   summary_by_store_employee:array<string,array{visit_tenths:int,people_tenths:int}>
     * }
     */
    private function serviceCustomerMetrics(array $stores, array $range): array
    {
        /** @var \app\services\query\metric\ServiceCustomerMetricReadServices $reader */
        $reader = app()->make(\app\services\query\metric\ServiceCustomerMetricReadServices::class);
        $metrics = $reader->employeeMetrics(CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range);
        if (!is_array($metrics)) throw new \LogicException('服务客数读取器返回结果无效');
        return [
            'detail_by_labor_key' => is_array($metrics['detail_by_labor_key'] ?? null) ? $metrics['detail_by_labor_key'] : [],
            'detail_context_by_labor_key' => is_array($metrics['detail_context_by_labor_key'] ?? null) ? $metrics['detail_context_by_labor_key'] : [],
            'summary_by_store_employee' => is_array($metrics['summary_by_store_employee'] ?? null) ? $metrics['summary_by_store_employee'] : [],
        ];
    }

    /** Keep the report key identical to ServiceCustomerMetricReadServices. */
    private function laborMetricKey(array $fact): string
    {
        return (int)($fact['store_id'] ?? 0) . '|'
            . (int)($fact['employee_id'] ?? 0) . '|'
            . (string)($fact['checkout_request_id'] ?? '') . '|'
            . (string)($fact['source_line_id'] ?? '');
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
    /** 工资项目数按入库 DECIMAL(20,6) 转为百万分位整数，不能沿用旧 0.5 步长或浮点合计。 */
    private function projectCountMicros(string $value): int
    {
        $value=trim($value);
        if(!preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/D',$value,$match))throw new \InvalidArgumentException('工资项目数精度不正确');
        $whole=(int)$match[2];
        $fraction=(int)str_pad($match[3]??'',6,'0');
        $micros=$whole*1000000+$fraction;
        return $match[1]==='-'?-$micros:$micros;
    }

    /** 项目数保留实际小数位，旧半项目数、明确的 0 和冲销值使用同一输出口径。 */
    private function projectCountText(int $micros): string
    {
        $sign=$micros<0?'-':'';
        $absolute=abs($micros);
        $fraction=rtrim(str_pad((string)($absolute%1000000),6,'0',STR_PAD_LEFT),'0');
        return $sign.intdiv($absolute,1000000).($fraction!==''?'.'.$fraction:'');
    }

    private function result(string $title,array $columns,array $records,array $range):array
    {
        $summary=[];
        foreach($columns as $c){
            $key=(string)$c['key'];$summary[$key]='-';
            if(empty($c['summable']))continue;
            $sum=0;$has=false;
            $moneyKey=(bool)preg_match('/amount|cash|fee|labor|consumption|performance|target|unit_output|average_order|sales_efficiency/i',$key);
            $projectCountKey=$key==='project_count';
            $tenthUnitKey=in_array($key,['service_visit_count','service_people_count'],true);
            foreach($records as $r){
                $v=$r[$key]??null;
                if($moneyKey&&is_string($v)&&preg_match('/^-?\d+(?:\.\d{1,2})?$/',$v)){$sum+=$this->cents($v);$has=true;}
                elseif($projectCountKey&&is_numeric($v)){
                    // 页面合计必须与员工汇总同精度；逐行四舍五入到 0.5 会把 0.3 + 0.4 算成 1。
                    $sum+=$this->projectCountMicros((string)$v);$has=true;
                }
                elseif($tenthUnitKey&&is_numeric($v)){$sum+=(int)round((float)$v*10);$has=true;}
                elseif(!$moneyKey&&is_numeric($v)){$sum+=(int)$v;$has=true;}
            }
            $summary[$key]=$has?($moneyKey?$this->money($sum):($projectCountKey?$this->projectCountText($sum):($tenthUnitKey?$this->tenth($sum):(string)$sum))):($tenthUnitKey?'0.0':'0');
        }
        return ['title'=>$title,'columns'=>$columns,'records'=>array_values($records),'summary_row'=>$summary,'column_groups'=>[],'metric_version'=>self::METRIC_VERSION,'data_as_of'=>date('c'),'aggregation_status'=>'caught_up','filters'=>['start_date'=>$range['start'],'end_date'=>$range['end']],'table_layout'=>['fixed'=>true,'summary_fixed'=>true]];
    }
    private function column(string $key,string $label,string $explanation,bool $summable=false,bool $fixed=false,int $width=120,array $drilldown=[],string $group=''):array{$c=['key'=>$key,'label'=>$label,'source_explanation'=>$explanation,'summable'=>$summable,'width'=>$width];if($fixed)$c['fixed']='left';if($group!=='')$c['group_label']=$group;if($drilldown)$c['drilldown']=$drilldown;return$c;}
    /** 声明与保存接口一致的字段单位，金额由页面元转分，非金额保持原类型。 */
    private function manualColumn(string $key,string $label,string $explanation,string $group=''):array
    {
        $types = ['target_amount' => 'integer_cents', 'unit_price' => 'integer_cents',
            '领取日期' => 'date', '领取数量' => 'nonnegative_integer'];
        $column = $this->column($key,$label,$explanation,false,false,120,[],$group);
        $column['manual_input'] = ['subject_type'=>'phase_six_row','field_key'=>$key,'value_type'=>$types[$key] ?? 'text'];
        return $column;
    }

    /** 按门店和明细键读回独立补充记录；空串也覆盖默认值，且不改写业务事实。 */
    private function withManualAnnotations(string $report, array $result, array $stores, bool $export): array
    {
        $manualColumns = [];
        foreach ((array)($result['columns'] ?? []) as $column) {
            if (!empty($column['manual_input'])) $manualColumns[(string)$column['key']] = $column['manual_input'];
        }
        if (!$manualColumns || empty($result['records'])) return $result;
        $keys = [];
        foreach ($result['records'] as $row) {
            $key = (string)($row['annotation_subject_key'] ?? '');
            if ($key !== '') $keys[$key] = true;
        }
        if (!$keys) return $result;
        $saved = Db::name(StoreOperationsReportAnnotationServices::ANNOTATION_TABLE)
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('report_code', $report)->where('subject_type', 'phase_six_row')
            ->whereIn('store_id', $stores)->whereIn('subject_key', array_keys($keys))
            ->field('store_id,subject_key,field_key,field_value,version')->select()->toArray();
        return $this->projectManualRows($result, $manualColumns, $saved, $export);
    }

    /** 仅用同门店同明细的白名单值覆盖，版本随行读回供并发写入校验。 */
    private function projectManualRows(array $result, array $manualColumns, array $saved, bool $export = false): array
    {
        $byRow = [];
        foreach ($saved as $item) {
            $key = (int)$item['store_id'] . '|' . (string)$item['subject_key'];
            $field = (string)$item['field_key'];
            if (isset($manualColumns[$field])) $byRow[$key][$field] = $item;
        }
        foreach ($result['records'] as &$row) {
            $key = (int)($row['store_id'] ?? 0) . '|' . (string)($row['annotation_subject_key'] ?? '');
            foreach ($manualColumns as $field => $definition) {
                $item = $byRow[$key][$field] ?? null;
                if (!array_key_exists($field, $row)) $row[$field] = '';
                $row[$field . '_version'] = $item === null ? 0 : (int)$item['version'];
                if ($item !== null) {
                    $value = (string)$item['field_value'];
                    $row[$field] = $this->manualDisplayValue($definition, $value, $export);
                }
            }
        }
        unset($row);
        return $result;
    }

    /** 金额仅在报表投影按整数元展示；无效历史文本保持原样以便排查，不能伪造 0。 */
    private function manualDisplayValue(array $definition, string $value, bool $export = false): string
    {
        if ($value === '' || ($definition['value_type'] ?? '') !== 'integer_cents'
            || !preg_match('/^-?\d+$/D', $value)) return $value;
        return $export
            ? \app\services\query\metric\MetricMoneyFormatter::exactYuan((int)$value)
            : \app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$value);
    }
    private function money(int $cents):string{$negative=$cents<0;$cents=abs($cents);$value=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);$value=rtrim(rtrim($value,'0'),'.');return($negative?'-':'').($value===''?'0':$value);}
    private function tenth(int $tenths): string { return number_format($tenths / 10, 1, '.', ''); }
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
