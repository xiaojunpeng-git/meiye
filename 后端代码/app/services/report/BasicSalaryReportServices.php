<?php
declare(strict_types=1);

namespace app\services\report;

use app\services\query\metric\MetricReadTransaction;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;
use app\services\payroll\PayrollAlgorithmPolicy;
use app\services\payroll\ProjectLaborCommissionReadServices;
use app\services\payroll\PositionSalaryRuleResolver;

/**
 * R51 月度实时工资只读投影。Host 注入门店及本人权限，Provider 负责结果筛选、合计和分页。
 * 统一 Reader 是业绩唯一出口；旧工资 JSON 只白名单读取人工值，绝不作为业绩或公式结果。
 * 缺失映射以 null + gaps 交付，不能把未算出的工资当作零工资，也不提供锁薪、发薪或编辑。
 */
final class BasicSalaryReportServices
{
    private const CODE = 'basic_salary_realtime';
    private const FIRST_COURSE_SOURCE_LIMIT = 10000;
    private const MANUAL = [
        'haoqingshu'=>'好评数', 'canbu'=>'餐补', 'jiaban'=>'加班', 'jiangweibutie'=>'岗位补贴',
        'qitatiaozheng'=>'其他调整', 'kaoqin'=>'考勤扣款', 'gongjijin'=>'公积金', 'geshui'=>'个税',
        'qitakoukuan'=>'其他扣款', 'yuzhigongzi'=>'预支工资', 'yishebaokoukuan'=>'医社保扣款', 'beizhu'=>'备注',
    ];
    private const FORMULAS = ['jixiao'=>'绩效工资', 'jiangjin'=>'新客奖金', 'yingfagongzi'=>'应发工资', 'shifagongzi'=>'实发工资'];
    private const COMMISSIONS = [
        'xiao_money'=>'项目提成', 'product_money'=>'产品提成', 'work_get'=>'劳动提成',
        'mendianxianjinticheng'=>'门店现金提成', 'mendiankoukaticheng'=>'门店余额扣款提成',
        'mendianlaodongticheng'=>'门店消耗提成', 'mendianshijikoukaticheng'=>'门店排除当日充值扣款提成',
    ];
    private const BASES = [
        'xiao_yeji'=>'项目规则业绩', 'product_yeji'=>'产品规则业绩', 'work_yeji'=>'劳动规则业绩',
        'mendianxianjinyeji'=>'门店现金规则业绩', 'mendiankoukayeji'=>'门店余额扣款规则业绩',
        'mendianlaodongyeji'=>'门店消耗业绩', 'mendianshijikoukayeji'=>'门店排除当日充值扣款规则业绩',
    ];
    private const PAIRS = [
        'xiao_money'=>'xiao_yeji', 'product_money'=>'product_yeji', 'work_get'=>'work_yeji',
        'mendianxianjinticheng'=>'mendianxianjinyeji', 'mendiankoukaticheng'=>'mendiankoukayeji',
        'mendianlaodongticheng'=>'mendianlaodongyeji', 'mendianshijikoukaticheng'=>'mendianshijikoukayeji',
    ];
    private $reader;
    private $transaction;
    private $cache = [];
    private $positionResolver;
    private $projectCommissions = [];
    private $firstCourseSourceCounts = ['candidates'=>0, 'history'=>0];

    /** 可注入同契约 Reader/一致读取器用于隔离验证，不接受浏览器提供的计算器或数据。 */
    public function __construct($reader = null, $transaction = null)
    {
        $this->reader = $reader ?? new RegisteredMetricReadServices();
        $this->transaction = $transaction ?? new MetricReadTransaction();
    }

    /** 仅发布已经实现的服务代码；不触碰共享目录或路由。 */
    public static function codes(): array { return [self::CODE]; }

    /** 静态列契约在开始读取前即可使用，不查字段表、不依赖 DB。 */
    public function definitions(string $code): array
    {
        $this->assertCode($code);
        $out = [];
        foreach (['month'=>'工资月份', 'store_name'=>'门店', 'employee_name'=>'员工', 'position_name'=>'岗位',
            'position_level_name'=>'职级', 'calculation_status'=>'计算状态'] as $key=>$label) {
            $out[] = $this->field($key, $label, 'text', false, false, in_array($key, ['store_name','employee_name'], true));
        }
        if (PayrollAlgorithmPolicy::independentPositions()) {
            $out=array_values(array_filter($out,static function($field){return $field['key']!=='position_level_name';}));
            foreach ($out as &$field) if ($field['key']==='position_name') $field['label']='职位';
            unset($field);
        }
        foreach (['sales_performance'=>'个人现金分配业绩', 'labor_performance'=>'个人劳动业绩', 'labor_fee'=>'个人手工费']
            + self::BASES + self::COMMISSIONS + self::FORMULAS as $key=>$label) {
            $out[] = $this->field($key, $label, 'number', true, true);
        }
        foreach (['xiangmushu'=>'工资项目数', 'keci'=>'服务客次', 'zhidingke'=>'指定客次', 'xinkeshu'=>'新客数'] as $key=>$label)
            $out[] = $this->field($key, $label, 'number', false, true);
        foreach (self::MANUAL as $key=>$label)
            $out[] = $this->field($key, $label, $key === 'beizhu' ? 'text' : 'number', !in_array($key, ['beizhu','haoqingshu'], true), $key !== 'beizhu');
        return $out;
    }

    private function field(string $key, string $label, string $type, bool $money, bool $sum, bool $fixed = false): array
    {
        return ['key'=>$key, 'label'=>$label, 'type'=>$type, 'defaultVisible'=>true,
            'defaultQuick'=>in_array($key, ['month','store_name','employee_name','calculation_status'], true),
            'width'=>$money ? 150 : 130, 'fixed'=>$fixed ? 'left' : '', 'money'=>$money, 'summable'=>$sum, 'editable'=>false];
    }

    /**
     * 日期限定同一自然月，as_of_date 仅为业务日截止，不伪装成历史数据库快照。
     * $stores 和 _report_scope 必须由 Host 从认证范围产生，普通请求字段不得覆盖。
     */
    public function read(string $code, array $stores, array $input): array
    {
        $fields = $this->definitions($code);
        [$stores, $range, $employeeId] = $this->scope($stores, $input);
        $this->cache = [];
        $this->firstCourseSourceCounts = ['candidates'=>0, 'history'=>0];
        return $this->transaction->run(function () use ($stores, $range, $employeeId, $fields): array {
            $this->assertSupplementalReadEngines();
            $staff = $this->staff($stores, $range, $employeeId);
            $this->positionResolver=new PositionSalaryRuleResolver();
            $this->projectCommissions=PayrollAlgorithmPolicy::calculated()
                ? (new ProjectLaborCommissionReadServices($this->reader))->read($stores,$range,$employeeId>0 ? [$employeeId] : [])['totals'] : [];
            $positions = array_values(array_unique(array_column($staff, 'position_id')));
            // 独立职位需要只读来源规则；旧模型保持原筛选与结果。
            $rules = PayrollAlgorithmPolicy::independentPositions() ? PositionSalaryRuleResolver::allRules()
                : ($positions ? Db::name('position_yeji')->whereIn('position_id', $positions)->where('status', 1)->order('id')->select()->toArray() : []);
            // table_ids=1 是旧工资专用字段关联，不是任何客户的商品分类 ID。
            $config = Db::name('salary_field')->whereFindInSet('table_ids', 1)->field('key,name,type,info')->select()->toArray();
            $rows = [];
            foreach ($staff as $person) $rows[] = $this->project($person, $range, $rules, $config);
            $sumFields = [];
            foreach ($fields as &$field) {
                $key = $field['key'];
                if ($field['summable']) {
                    // 不完整列不能被框架忽略 null 后合计成貌似完整的零工资。
                    foreach ($rows as $row) if ($row[$key] === null) { $field['summable'] = false; break; }
                    if ($field['summable']) $sumFields[] = $key;
                }
            }
            unset($field);
            $gaps = [];
            foreach ($rows as $row) foreach ($row['gaps'] as $gap) $gaps[] = ['row_id'=>$row['row_id']] + $gap;
            return ['rows'=>$rows, 'fields'=>$fields, 'field_sources'=>$this->sources($fields), 'sum_fields'=>$sumFields,
                'complete'=>$gaps === [], 'gaps'=>$gaps, 'editable'=>false,
                'date_range'=>$range, 'data_as_of'=>date('c'), 'metric_version'=>'r51-salary-reader-v1',
                'coverage'=>'仅统计已进入统一事实层的业务；旧流水未回填部分不冒充已覆盖，不读取旧工资业绩结果',
                'limitations'=>['只读实时试算，不代表正式锁薪或发薪', '仅覆盖统一事实层；不推定旧流水已回填',
                    '月份截止是业务日筛选，不是恢复当时的历史数据库状态', '原业务行、冲销链或首次全款成交人员记录缺失时返回具体缺口',
                    '同日充值排除按本店业务范围匹配会员及业务日，区别于旧实现跨所有门店查充值'],
                'as_of_semantics'=>'业务日截止，按查询时可见的统一事实及当前规则重算；不是历史锁薪快照'];
        });
    }

    private function assertCode(string $code): void
    {
        if ($code !== self::CODE) throw new \InvalidArgumentException('不支持的实时工资报表');
    }

    /** 工资规则、人工值及人员关系也必须支持同一连接的一致快照，不能只检查经营事实表。 */
    private function assertSupplementalReadEngines(): void
    {
        $tables = ['employee','system_store_staff','position','position_level','staff_job_position','position_yeji',
            'salary_info','salary_field','salary_agent','cashier_v3_service_customer_group_fact',
            'cashier_v3_card_sale_item_allocation_fact','cashier_v3_card_sale_category_allocation_fact',
            'store_product','store_product_category','cashier_v3_refund_adjustment','system_business_product','cashier_v3_report_sale_dimension_fact','cashier_v3_sales_order_line',
            'cashier_v3_order_center_void_operation','cashier_v3_service_record_void_operation',
            'cashier_v3_sales_order','cashier_v3_checkout_payment_draft','cashier_v3_entitlement_writeoff_fact',
            'cashier_v3_card_purchase_receipt','cashier_v3_card_operation_settlement'];
        if (PayrollAlgorithmPolicy::independentPositions()) $tables[]='position_salary_rule';
        if (PayrollAlgorithmPolicy::calculated()) $tables[]='yeji_commission';
        $prefix = (string)Db::connect()->getConfig('prefix');
        $names = array_map(static function ($name) use ($prefix) { return $prefix.$name; }, $tables);
        $engines = Db::query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            .implode(',', array_fill(0, count($names), '?')).')', $names);
        if (count($engines) !== count($names)) throw new \RuntimeException('工资一致读取所需的人员、规则或人工补充表缺失');
        foreach ($engines as $engine) if (strtoupper((string)$engine['ENGINE']) !== 'INNODB')
            throw new \RuntimeException('工资一致读取要求 InnoDB：'.$engine['TABLE_NAME']);
    }

    private function scope(array $stores, array $input): array
    {
        foreach ($stores as $id) if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('门店范围无效');
        $stores = array_values(array_unique($stores));
        $scope = $input['_report_scope'] ?? [];
        $mode = $scope['mode'] ?? '';
        if (!$stores || !in_array($mode, ['stores','all','self_participant'], true)) throw new \InvalidArgumentException('缺少可信工资数据权限');
        $employee = $mode === 'self_participant' ? (int)($scope['employee_id'] ?? 0) : 0;
        if ($mode === 'self_participant' && $employee < 1) throw new \InvalidArgumentException('本人权限缺少员工身份');
        foreach (['start_date','end_date','as_of_date'] as $key) {
            $value = $input[$key] ?? '';
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai')) : false;
            if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('工资日期必须为有效 YYYY-MM-DD');
        }
        $start = $input['start_date']; $end = $input['end_date']; $cutoff = $input['as_of_date'];
        if (substr($start, 0, 7) !== substr($end, 0, 7) || substr($start, 0, 7) !== substr($cutoff, 0, 7)
            || substr($start, 8) !== '01' || $start > $end || $cutoff < $start)
            throw new \InvalidArgumentException('工资按同一自然月从月初统计，不支持跨月或截去月初');
        return [$stores, ['start'=>$start, 'end'=>min($end, $cutoff)], $employee];
    }

    /** 月内任职与事实参与者取并集，避免离职/调店后漏人；身份只能用 employee_id。 */
    private function staff(array $stores, array $range, int $employee): array
    {
        $q = Db::name('staff_job_position')->alias('j')->join('employee e', 'e.id=j.employee_id')
            ->join('position p', 'p.id=j.position_id')->leftJoin('system_store_staff ss', 'ss.id=j.staff_id')
            ->leftJoin('position_level l', 'l.id=ss.position_level')->join('system_store s', 's.id=j.store_id')
            ->whereIn('j.store_id', $stores)->where('j.is_del', 0)
            ->where('j.start_time', '<=', strtotime($range['end'].' 23:59:59 Asia/Shanghai'))
            ->where(function ($q) use ($range) { $q->where('j.end_time', 0)->whereOr('j.end_time', '>=', strtotime($range['start'].' 00:00:00 Asia/Shanghai')); });
        if ($employee > 0) $q->where('j.employee_id', $employee);
        $jobs = $q->field('j.id job_id,j.employee_id,j.store_id,j.staff_id,j.position_id,j.start_time,j.end_time,ss.position legacy_position,e.name employee_name,p.name position_name,ss.position_level,l.name position_level_name,s.name store_name')->order('j.id')->select()->toArray();
        $rows = [];
        foreach ($jobs as $job) {
            $key = $job['store_id'].':'.$job['employee_id'];
            if (PayrollAlgorithmPolicy::independentPositions()) {
                // 保留全部任职时段；等价转换在规则适配器中合并，不能只取第一条旧职位。
                $segments=$rows[$key]['job_segments'] ?? []; $segments[]=$job;
                $rows[$key]=$job; $rows[$key]['job_segments']=$segments;
            } else {
                if (isset($rows[$key])) { $rows[$key]['job_ambiguous'] = true; continue; }
                $rows[$key] = $job;
            }
        }
        // 当前员工即使未绑岗位也要呈现缺口；旧数据没有稳定身份时阻止伪空报表。
        $roster = Db::name('system_store_staff')->alias('ss')->leftJoin('employee e', 'e.id=ss.employee_id')
            ->join('system_store s', 's.id=ss.store_id')->whereIn('ss.store_id', $stores)
            ->where('ss.status', 1)->where('ss.is_del', 0);
        if ($employee > 0) $roster->where('ss.employee_id', $employee);
        foreach ($roster->field('ss.id staff_id,ss.employee_id,ss.store_id,e.name employee_name,s.name store_name')->select()->toArray() as $person) {
            if ((int)$person['employee_id'] < 1 || $person['employee_name'] === null)
                throw new \RuntimeException('员工任职 '.$person['staff_id'].' 尚未映射稳定员工身份，不能生成工资行');
            $key = $person['store_id'].':'.$person['employee_id'];
            if (!isset($rows[$key])) $rows[$key] = $person + ['position_id'=>0,'position_name'=>null,'position_level'=>0,'position_level_name'=>null];
        }
        // 即使月内岗位缺失也必须保留已发生业绩，并明确工资规则缺口。
        foreach (['staff_sales_yeji','staff_labor_yeji'] as $metric) {
            foreach ($this->reader->personnelDailyTotals($metric, '0', $stores, $range, $employee > 0 ? [$employee] : []) as $fact) {
                $key = $fact['store_id'].':'.$fact['employee_id'];
                if (!isset($rows[$key])) $rows[$key] = ['employee_id'=>$fact['employee_id'], 'store_id'=>$fact['store_id'],
                    'employee_name'=>$fact['employee_name'], 'store_name'=>$fact['store_name'], 'staff_id'=>0, 'position_id'=>0,
                    'position_name'=>null, 'position_level'=>0, 'position_level_name'=>null];
            }
        }
        // 欠款在本月达成首次全款时，原销售人员可能已无本月销售金额，仍须保留其新客归属行。
        foreach ($stores as $store) {
            $cohort = $this->firstCourseOwners($store, $range);
            foreach ($cohort['owners'] as $id=>$owner) {
                if ($employee > 0 && $employee !== (int)$id) continue;
                $key = $store.':'.$id;
                if (!isset($rows[$key])) $rows[$key] = ['employee_id'=>(int)$id,'store_id'=>$store,'employee_name'=>$owner['name'],
                    'store_name'=>(string)Db::name('system_store')->where('id',$store)->value('name'),'staff_id'=>0,'position_id'=>0,
                    'position_name'=>null,'position_level'=>0,'position_level_name'=>null];
            }
        }
        ksort($rows, SORT_NATURAL);
        return array_values($rows);
    }

    /** 一行工资不覆盖任何源记录；公式、提成与人工输入保持可独立解释。 */
    private function project(array $person, array $range, array $rules, array $config): array
    {
        $row = array_fill_keys(array_column($this->definitions(self::CODE), 'key'), null);
        $month = substr($range['start'], 0, 7);
        $row = array_replace($row, ['row_id'=>'salary:'.$month.':'.$person['store_id'].':'.$person['employee_id'],
            'month'=>$month, 'store_id'=>(int)$person['store_id'], 'employee_id'=>(int)$person['employee_id'],
            'employee_name'=>$person['employee_name'], 'store_name'=>$person['store_name'],
            'position_name'=>$person['position_name'], 'position_level_name'=>$person['position_level_name'], 'gaps'=>[], 'rule_details'=>[],
            'editable'=>false, 'manual_values'=>[]]);
        $values = [];
        foreach (['sales_performance'=>'staff_sales_yeji', 'labor_performance'=>'staff_labor_yeji', 'labor_fee'=>'staff_labor_fee',
            'xiangmushu'=>'staff_project_num', 'keci'=>'staff_service_num', 'zhidingke'=>'staff_point_visit_num'] as $key=>$metric) {
            $raw = $this->metric($metric, $person, $range, false, null);
            $divisor = $key === 'xiangmushu' ? '1000000' : (in_array($key, ['keci','zhidingke'], true) ? '10' : '100');
            $values[$key] = bcdiv((string)$raw, $divisor, 6);
            $row[$key] = (float)$values[$key];
        }
        $cohort = $this->firstCourseOwners((int)$person['store_id'], $range);
        if ($cohort['gaps']) {
            $values['xinkeshu'] = null;
            $this->gap($row, 'xinkeshu', 'NEW_CUSTOMER_SOURCE_MISSING', implode('；',$cohort['gaps']));
        } else {
            $count = count($cohort['owners'][(int)$person['employee_id']]['members'] ?? []);
            $values['xinkeshu'] = (string)$count; $row['xinkeshu'] = $count;
        }
        $manual = $this->manual($person, $month);
        foreach (self::MANUAL as $key=>$label) {
            $value = $manual[$key] ?? null;
            $row['manual_values'][$key] = $value;
            if ($key === 'beizhu') { $row[$key] = $value; continue; }
            if ($value === null || $value === '') {
                // 延续原工资公式的人工缺省 0；raw 值单独保留，绝不回写或覆盖人工记录。
                $values[$key] = '0'; $row[$key] = 0.0;
            } elseif ((!is_int($value) && !is_float($value) && !is_string($value))
                || !preg_match('/^-?\d+(?:\.\d{1,2})?$/D', (string)$value)) {
                $values[$key] = null;
                $this->gap($row, $key, 'MANUAL_VALUE_INVALID', $label.'不是有效数字');
            } else { $values[$key] = (string)$value; $row[$key] = (float)$value; }
        }
        $applicable = array_values(array_filter($rules, static function ($r) use ($person): bool {
            return (int)$r['position_id'] === (int)$person['position_id'] && ((int)$r['position_level_id'] === 0
                || (int)$r['position_level_id'] === (int)$person['position_level']);
        }));
        if (PayrollAlgorithmPolicy::independentPositions()) {
            try { $applicable=$this->positionResolver->forJobs($person,$range,$rules); }
            catch (\DomainException $error) { $applicable=[]; $this->gap($row,'position_name','JOB_RULE_CONFLICT',$error->getMessage()); }
        }
        $this->commissions($row, $values, $person, $range, $applicable);
        if (PayrollAlgorithmPolicy::calculated()) $this->projectLaborCommission($row,$values,$person);
        $formulas = [];
        foreach ($config as $field) {
            $key = (string)$field['key'];
            if ((int)$field['type'] === 2 && isset(self::FORMULAS[$key])) {
                if (isset($formulas[$key])) throw new \RuntimeException('工资公式重复配置：'.$key);
                $formulas[$key] = (string)$field['info'];
            }
            if ((int)$field['type'] === 2 && !isset(self::FORMULAS[$key]))
                $this->gap($row, $key, 'FORMULA_FIELD_UNMAPPED', '工资公式字段未纳入静态白名单：'.$key);
        }
        // 依赖图递归求值，允许配置顺序变化，拒绝循环、未知字段及空值。
        $visiting = [];
        $resolve = function (string $key) use (&$resolve, &$values, &$visiting, $formulas): string {
            if (array_key_exists($key, $values)) {
                if ($values[$key] === null) throw new \DomainException('依赖字段未具备权威值：'.$key);
                return $values[$key];
            }
            if (!isset($formulas[$key])) throw new \DomainException('未配置或不允许的字段：'.$key);
            if (isset($visiting[$key])) throw new \DomainException('工资公式循环依赖：'.$key);
            $visiting[$key] = true;
            try { return $values[$key] = self::roundDecimal(self::formula($formulas[$key], $resolve), 2); }
            finally { unset($visiting[$key]); }
        };
        foreach (self::FORMULAS as $key=>$label) {
            try { $row[$key] = (float)self::roundDecimal($resolve($key), 2); }
            catch (\DomainException $e) { $this->gap($row, $key, 'FORMULA_UNAVAILABLE', $e->getMessage()); }
        }
        $row['calculation_status'] = $row['gaps'] ? '存在取数或规则缺口' : '实时试算完成';
        return $row;
    }

    /** 白名单人工字段独立读取；旧 JSON 的其他键（业绩/提成/公式）全部忽略。 */
    private function manual(array $person, string $month): array
    {
        if ((int)$person['staff_id'] < 1) return [];
        $records = Db::name('salary_info')->where('store_id', (int)$person['store_id'])->where('staff_id', (int)$person['staff_id'])
            ->where('date', strtotime($month.'-01 00:00:00 Asia/Shanghai'))->field('salary_info')->select()->toArray();
        if (count($records) > 1) throw new \RuntimeException('同员工门店月份存在多份人工工资记录，不能任取一份');
        if (!$records) return [];
        $json = json_decode((string)$records[0]['salary_info'], true);
        if (!is_array($json)) throw new \RuntimeException('人工工资补充记录无法解析');
        return array_intersect_key($json, self::MANUAL);
    }

    /**
     * 新客资格复用统一首次全款疗程事实，先查会员全历史有效首笔，再按本店/本月筛选。
     * 原销售归属与全款达成时间分开：欠款后续补清只改变达成日，不把新客转给补交操作人或补交销售人。
     * 普通退款不撤销生命周期资格或原成交人员归属；金额冲销只影响业绩，不重新计算历史新客。
     */
    private function firstCourseOwners(int $store, array $range): array
    {
        $key = 'first-course-owners:'.json_encode([$store,$range]);
        if (isset($this->cache[$key])) return $this->cache[$key];
        $candidates = $this->firstCourseQuery($range)->where('f.store_id',$store)
            ->field('f.id,f.member_id,f.related_order_id')->order('f.id')->limit(self::FIRST_COURSE_SOURCE_LIMIT + 1)->select()->toArray();
        $this->consumeFirstCourseSourceBudget('candidates', count($candidates));
        $result = ['owners'=>[], 'gaps'=>[]];
        if (!$candidates) return $this->cache[$key] = $result;
        $members = array_values(array_unique(array_map('intval',array_column($candidates,'member_id'))));
        $first = [];
        foreach (array_chunk($members,300) as $chunk) {
            // 全历史和跨店查询只作首笔资格判断；各批最多读取哨兵行，跨批/跨店累计超限立即失败，不能沿用部分 first。
            $history = $this->firstCourseQuery(['start'=>'1000-01-01','end'=>'9999-12-31'])->whereIn('f.member_id',$chunk)
                ->field('f.id,f.member_id,f.occurred_at')->order('f.occurred_at')->order('f.id')
                ->limit(self::FIRST_COURSE_SOURCE_LIMIT + 1)->select()->toArray();
            $this->consumeFirstCourseSourceBudget('history', count($history));
            foreach ($history as $event) if (!isset($first[(int)$event['member_id']])) $first[(int)$event['member_id']] = (int)$event['id'];
        }
        foreach ($candidates as $event) {
            $member = (int)$event['member_id'];
            if (($first[$member] ?? 0) !== (int)$event['id']) continue;
            $order = (string)$event['related_order_id'];
            $sales = Db::name('cashier_v3_sale_fact')->where('tenant_id','0')->where('store_id',$store)->where('member_id',$member)
                ->where('order_id',$order)->where('source_type','card')->where('status','effective')->where('fact_direction','forward')
                ->field('source_line_id,checkout_request_id')->select()->toArray();
            if (!$sales) { $result['gaps'][] = '首次全款事件缺少同会员的原疗程卡成交事实：'.$order; continue; }
            $lineIds = array_column($sales,'source_line_id');
            $peopleQuery = Db::name('cashier_v3_performance_fact')->alias('p')->where('p.tenant_id','0')->where('p.store_id',$store)
                ->where('p.member_id',$member)->where('p.order_id',$order)->whereIn('p.source_line_id',$lineIds)
                ->where('p.performance_type','sales_performance_allocated')->where('p.status','effective')->where('p.fact_direction','forward')
                ->where('p.employee_id','>',0);
            $recordedQuery = clone $peopleQuery;
            // 仅成功原单人员更正可替换归属；退款反向金额不能删除新客。命令键须连同租户/门店/原单证明，不能单凭 key 匹配。
            $people = $peopleQuery->whereNotExists(function ($q) {
                $q->name('cashier_v3_performance_fact')->alias('r51_owner_reverse')
                    ->join('cashier_v3_order_lifecycle_operation r51_owner_operation',
                        'r51_owner_operation.tenant_id=r51_owner_reverse.tenant_id AND r51_owner_operation.store_id=r51_owner_reverse.store_id AND r51_owner_operation.command_idempotency_key=r51_owner_reverse.command_idempotency_key AND r51_owner_operation.source_order_id=r51_owner_reverse.order_id')
                    ->whereRaw('r51_owner_reverse.tenant_id=p.tenant_id AND r51_owner_reverse.store_id=p.store_id AND r51_owner_reverse.order_id=p.order_id AND r51_owner_reverse.reversal_of=p.fact_id')
                    ->where('r51_owner_reverse.status','effective')->where('r51_owner_reverse.fact_direction','reversal')
                    ->where('r51_owner_reverse.command_idempotency_key','<>','')
                    ->where('r51_owner_operation.source_type','sales')->where('r51_owner_operation.operation_type','personnel_adjustment')
                    ->where('r51_owner_operation.status','succeeded');
            })->field('p.employee_id,p.employee_name_snapshot')->select()->toArray();
            if (!$people) {
                // 原来有归属而全部被上述权威更正替换，明确为零人员；不能再读取未更新的原行快照复活旧人员或报缺失。
                if ($recordedQuery->count() > 0) continue;
                // 明确保存的“无销售人”不属于映射缺失；源记录不存在或快照不能解释时不能伪装零新客。
                $lines = Db::name('cashier_v3_sales_order_line')->where('tenant_id','0')->where('store_id',$store)->where('member_id',$member)
                    ->where('order_id',$order)->where(function ($q) use ($lineIds) {
                        $q->whereIn('order_line_id',$lineIds)->whereOr('checkout_line_id','in',$lineIds);
                    })->field('salespeople_snapshot_json')->select()->toArray();
                $explicitEmpty = $lines !== [];
                foreach ($lines as $line) if (json_decode((string)$line['salespeople_snapshot_json'],true) !== []) $explicitEmpty = false;
                if (!$explicitEmpty) $result['gaps'][] = '首次全款疗程卡缺少有效原成交销售人员记录：'.$order;
                continue;
            }
            foreach ($people as $person) {
                $id = (int)$person['employee_id'];
                if (!isset($result['owners'][$id])) $result['owners'][$id] = ['name'=>(string)$person['employee_name_snapshot'],'members'=>[]];
                $result['owners'][$id]['members'][$member] = true;
            }
        }
        $result['gaps'] = array_values(array_unique($result['gaps']));
        return $this->cache[$key] = $result;
    }

    /** 候选与历史分别执行每次 read 的一万源预算；缓存命中不重复消费，超限不能降级成零新客或部分工资。 */
    private function consumeFirstCourseSourceBudget(string $source, int $count): void
    {
        $this->firstCourseSourceCounts[$source] += $count;
        if ($this->firstCourseSourceCounts[$source] > self::FIRST_COURSE_SOURCE_LIMIT)
            throw new \DomainException('工资新客'.($source === 'candidates' ? '候选' : '全历史').'源记录累计超过10000行，已拒绝返回部分结果');
    }

    /** 完全复用统一首次全款资格：作废排除，普通退款（包括全退）不取消；不另加退款或来源/项目集合谓词。 */
    private function firstCourseQuery(array $range)
    {
        $query = Db::name('cashier_v3_customer_lifecycle_fact')->alias('f')->where('f.tenant_id','0');
        (new FirstCourseMemberCohortServices())->apply($query,'f',$range);
        return $query;
    }

    /** 每组阶梯只应用唯一命中档；未知条件使整组金额不可用，避免漏算或重算。 */
    private function commissions(array &$row, array &$values, array $person, array $range, array $rules): void
    {
        $totals = array_fill_keys(array_keys(self::COMMISSIONS), 0);
        $bases = array_fill_keys(array_keys(self::BASES), 0);
        $broken = []; $matched = [];
        if (!$rules || !empty($person['job_ambiguous']) || (int)$person['position_id'] < 1) {
            foreach (self::COMMISSIONS as $key=>$label) $broken[$key] = '月内岗位/职级规则缺失或存在多段任职，不能推定为零提成';
        } else foreach ($rules as $rule) {
            $type = (int)$rule['type']; $store = (int)$rule['range_type'] === 1;
            $target = $store ? ([1=>'mendianxianjinticheng',2=>'mendianlaodongticheng',3=>'mendiankoukaticheng',4=>'mendianshijikoukaticheng'][$type] ?? '')
                : ([1=>'xiao_money',2=>'work_get'][$type] ?? '');
            // 计算法只替换个人劳动提成，其他职位工资项和现有算法继续使用原路径。
            if (PayrollAlgorithmPolicy::calculated() && !$store && $type===2) continue;
            // 旧个人扣卡规则被明确排除；不把它变成个人现金提成。
            if (!$store && $type === 3) continue;
            if ($target === '') { foreach (self::COMMISSIONS as $key=>$label) $broken[$key] = '未知提成类型或范围，规则 '.$rule['id']; continue; }
            $categories = null;
            try {
                if (!in_array((int)$rule['range_type'], [1,2], true)) throw new \DomainException('提成范围未映射');
                if (!in_array((int)$rule['has_recharge'], [0,1,2], true)) throw new \DomainException('充值包含条件未映射');
                $categories = $this->ruleCategories((string)$rule['cate_ids']);
                if (!$store && $type === 1 && $categories !== []) {
                    // 延续旧规则“所选分类存在产品则属于产品提成”的归组，不根据当期成交猜测规则类型。
                    $target = $this->hasProductCategory($categories) ? 'product_money' : 'xiao_money';
                }
                $days = $this->agentDays($rule, $person, $range);
                if (isset($rule['_effective_days'])) $days=$days===null ? $rule['_effective_days'] : array_values(array_intersect($days,$rule['_effective_days']));
                // 产品已确认：门店 type2 是项目消耗，个人 type2 是劳动分配；充值标记不改变两者基数。
                $metric = $store ? ([1=>'cash_performance',2=>'consume_amount',3=>'balance_deduction_amount',4=>'balance_deduction_amount'][$type]) : ($type === 2 ? 'staff_labor_yeji' : 'staff_sales_yeji');
                if ($store && $type === 1)
                    $amount = $this->storeCashRuleMetric($person, $range, $days, $categories, (int)$rule['has_recharge'] === 1);
                elseif ($store && ($type === 4 || ($type === 3 && $categories !== [])))
                    $amount = $this->storeBalanceRuleMetric($person, $range, $days, $categories, $type === 4);
                elseif (!$store && $type === 1 && ($categories !== [] || (int)$rule['has_recharge'] !== 1))
                    $amount = $this->personalRuleMetric('staff_sales_yeji', $person, $range, $days, $categories, (int)$rule['has_recharge'] === 1);
                elseif (!$store && $type === 2 && $categories !== [])
                    $amount = $this->personalRuleMetric('staff_labor_yeji', $person, $range, $days, $categories, false);
                elseif ($store && $type === 2 && $categories !== [])
                    $amount = $this->consumptionCategoryMetric($person, $range, $days, $categories);
                elseif ($categories !== []) throw new \DomainException('该门店规则尚缺对应分类指标，不能用全店总额代替');
                else $amount = $this->metric($metric, $person, $range, $store, $days);
                // 劳动档位沿用销售业绩（包含充值）判断，实际提成仍乘劳动业绩。
                $threshold = $amount;
                if ($type === 2) {
                    $threshold = $store ? $this->storeCashRuleMetric($person, $range, $days, $categories, true)
                        : ($categories === [] ? $this->metric('staff_sales_yeji', $person, $range, false, $days)
                            : $this->personalRuleMetric('staff_sales_yeji', $person, $range, $days, $categories, true));
                }
                if (!preg_match('/^(\d+(?:\.\d{1,2})?)-(\d+(?:\.\d{1,2})?)$/D', trim((string)$rule['range']), $m))
                    throw new \DomainException('提成区间缺失或格式无效');
                $min = self::decimalCents($m[1]); $max = self::decimalCents($m[2]);
                if ($min > $max) throw new \DomainException('提成区间上下限颠倒');
                $rate = (string)$rule['commission'];
                if (!preg_match('/^\d+(?:\.\d{1,4})?$/D', $rate) || bccomp($rate, '100', 4) > 0) throw new \DomainException('提点必须在 0 至 100%');
                if ($threshold < $min || $threshold > $max) continue;
                $signature = $target.':'.$rule['time_type'].':'.($type === 2 ? 'labor' : $rule['has_recharge']).':'.implode(',', $categories);
                if (isset($rule['_effective_days'])) $signature.=':'.implode(',',$rule['_effective_days']);
                if (isset($matched[$signature])) throw new \DomainException('阶梯边界重叠，同时命中规则 '.$matched[$signature].' 与 '.$rule['id']);
                $matched[$signature] = $rule['id'];
                $cents = (int)self::roundDecimal(bcdiv(bcmul((string)$amount, $rate, 8), '100', 8), 0);
                $totals[$target] += $cents; $bases[self::PAIRS[$target]] += $amount;
                $row['rule_details'][] = ['rule_id'=>(int)$rule['id'], 'field'=>$target, 'metric_code'=>$metric,
                    'threshold_metric_code'=>$type === 2 ? ($store ? 'cash_performance' : 'staff_sales_yeji') : $metric,
                    'basis_cents'=>$amount, 'threshold_cents'=>$threshold, 'rate_percent'=>(float)$rate, 'commission_cents'=>$cents];
            } catch (\DomainException $e) {
                $broken[$target] = '规则 '.$rule['id'].'：'.$e->getMessage();
                if (!$store && $type === 1 && trim((string)$rule['cate_ids']) !== '' && $categories === null)
                    $broken['product_money'] = $broken[$target];
            }
        }
        foreach (self::COMMISSIONS as $key=>$label) {
            $base = self::PAIRS[$key];
            if (isset($broken[$key])) {
                $values[$key] = $values[$base] = null;
                $this->gap($row, $key, 'RULE_MAPPING_UNAVAILABLE', $broken[$key]);
                $this->gap($row, $base, 'RULE_MAPPING_UNAVAILABLE', $broken[$key]);
            } else {
                $values[$key] = bcdiv((string)$totals[$key], '100', 2); $row[$key] = (float)$values[$key];
                $values[$base] = bcdiv((string)$bases[$base], '100', 2); $row[$base] = (float)$values[$base];
            }
        }
    }

    /** 项目提成独立替换个人劳动结果；不修改手工费，不叠加旧职位劳动提成。 */
    private function projectLaborCommission(array &$row,array &$values,array $person): void
    {
        $total=$this->projectCommissions[$person['store_id'].':'.$person['employee_id']]
            ?? ['amount_cents'=>0,'basis_cents'=>0,'complete'=>true,'details'=>[],'gaps'=>[]];
        $row['gaps']=array_values(array_filter($row['gaps'],static function($gap){return !in_array($gap['field'],['work_get','work_yeji'],true);}));
        $row['commission_details']=$total['details'];
        foreach (['work_get'=>'amount_cents','work_yeji'=>'basis_cents'] as $field=>$source) {
            $values[$field]=$total['complete'] ? bcdiv((string)$total[$source],'100',2) : null;
            $row[$field]=$values[$field]===null ? null : (float)$values[$field];
            if (!$total['complete']) $this->gap($row,$field,'PROJECT_COMMISSION_UNAVAILABLE',implode('；',array_unique($total['gaps'])));
        }
    }

    /** 配置分类先校验身份并展开下级；不按名称、客户固定编号或发生时分类猜测当前归属。 */
    private function ruleCategories(string $csv): array
    {
        if (trim($csv) === '') return [];
        $key = 'categories:'.$csv;
        if (isset($this->cache[$key])) return $this->cache[$key];
        $selected = [];
        foreach (explode(',', $csv) as $id) {
            $id = trim($id);
            if (!preg_match('/^[1-9][0-9]*$/D', $id)) throw new \DomainException('工资分类配置含无效身份');
            $selected[(int)$id] = true;
        }
        $tree = $this->cache['category-tree'] ?? ($this->cache['category-tree'] = Db::name('store_product_category')
            ->where('type', 0)->where('relation_id', 0)->field('id,pid,cate_name')->select()->toArray());
        $known = array_column($tree, null, 'id');
        foreach ($selected as $id=>$unused) if (!isset($known[$id])) throw new \DomainException('工资分类配置指向不存在的平台分类：'.$id);
        do {
            $changed = false;
            foreach ($tree as $node) if (isset($selected[(int)$node['pid']]) && !isset($selected[(int)$node['id']])) {
                $selected[(int)$node['id']] = true; $changed = true;
            }
        } while ($changed);
        $ids = array_keys($selected); sort($ids, SORT_NUMERIC);
        return $this->cache[$key] = $ids;
    }

    private function hasProductCategory(array $ids): bool
    {
        $key = 'product-categories:'.implode(',', $ids);
        if (array_key_exists($key, $this->cache)) return $this->cache[$key];
        $query = Db::name('store_product')->where('type', 0)->where('relation_id', 0)->where('product_type', 0);
        $query->where(function ($q) use ($ids) { foreach ($ids as $id) $q->whereFindInSet('cate_id', $id, 'OR'); });
        return $this->cache[$key] = (bool)$query->count();
    }

    /**
     * 金额只来自统一人员 Reader；额外读取的事实列只证明来源和冲销链，不重新选择金额或状态口径。
     * 原事实可以在上月，按租户/门店/员工验证链路后沿用原业务身份，统计日仍属于当前分配事实。
     */
    private function personnelDetails(string $metric, array $person, array $range): array
    {
        $key = 'details:'.json_encode([$metric,$person['store_id'],$person['employee_id'],$range]);
        if (isset($this->cache[$key])) return $this->cache[$key];
        $rows = $this->reader->personnelDetailResult($metric, '0', [(int)$person['store_id']], $range, [(int)$person['employee_id']])['rows'];
        $meta = []; $pending = array_column($rows, 'fact_id');
        for ($depth = 0; $pending && $depth < 32; ++$depth) {
            $next = [];
            foreach (array_chunk(array_values(array_unique($pending)), 300) as $chunk) {
                foreach (Db::name('cashier_v3_performance_fact')->where('tenant_id', '0')->where('store_id', (int)$person['store_id'])
                    ->where('employee_id', (int)$person['employee_id'])->where('performance_type', $metric === 'staff_sales_yeji' ? 'sales_performance_allocated' : 'labor_performance_allocated')
                    ->where('status', 'effective')->whereIn('fact_id', $chunk)
                    ->field('fact_id,reversal_of,source_document_type,checkout_request_id,source_line_id,order_id,business_date')->select()->toArray() as $fact) {
                    $meta[(string)$fact['fact_id']] = $fact;
                    $origin = (string)$fact['reversal_of'];
                    if ($origin !== '' && !isset($meta[$origin])) $next[$origin] = true;
                }
            }
            $pending = array_values(array_diff(array_keys($next), array_keys($meta)));
        }
        foreach ($rows as &$row) {
            $id = (string)$row['fact_id']; $seen = [];
            while (isset($meta[$id]) && (string)$meta[$id]['reversal_of'] !== '') {
                if (isset($seen[$id])) break;
                $seen[$id] = true; $id = (string)$meta[$id]['reversal_of'];
            }
            $row['_origin'] = isset($meta[$id]) && (string)$meta[$id]['reversal_of'] === '' ? $meta[$id] : null;
        }
        unset($row);
        return $this->cache[$key] = $rows;
    }

    /** 先按完整组件池分摊个人已分配金额，再筛分类；不同筛选不得各自重新分摊同一金额。 */
    private function personalRuleMetric(string $metric, array $person, array $range, ?array $days, array $categories, bool $includeRecharge): int
    {
        $sum = '0';
        foreach ($this->personnelDetails($metric, $person, $range) as $fact) {
            if ($days !== null && !in_array($fact['business_date'], $days, true)) continue;
            $amount = (int)$fact['metric_value'];
            if ($amount === 0) continue;
            $origin = $fact['_origin'];
            if (!$origin) throw new \DomainException('人员分配事实缺少可验证的原事实或冲销链：'.$fact['fact_id']);
            $type = (string)$origin['source_document_type'];
            $recharge = in_array($type, ['recharge','recharge_debt_repayment'], true);
            if ($metric === 'staff_sales_yeji' && !$includeRecharge) {
                if (!in_array($type, ['recharge','recharge_debt_repayment','sales_order','sales','checkout','debt_repayment'], true))
                    throw new \DomainException('个人排除充值所需的原业务来源类型缺失或未映射：'.$fact['fact_id']);
                if ($recharge) continue;
            }
            // 与旧规则一致：包含充值的销售档位/提成中，充值不受商品分类筛选，但只取本人的分配。
            if ($categories === [] || ($metric === 'staff_sales_yeji' && $includeRecharge && $recharge)) {
                $sum = bcadd($sum, (string)$amount, 0); continue;
            }
            $parts = $metric === 'staff_sales_yeji' ? $this->saleParts($origin, $person) : $this->laborParts($origin, $person);
            $weights = [];
            foreach ($parts as $index=>$part) {
                if ((int)$part['id'] < 1) throw new \DomainException('人员业务行的项目当前分类未能验证：'.$fact['fact_id']);
                $weights[$index] = (string)$part['weight'];
            }
            foreach (self::allocatePersonalCents($amount, $weights) as $index=>$partAmount)
                if (in_array((int)$parts[$index]['id'], $categories, true)) $sum = bcadd($sum, (string)$partAmount, 0);
        }
        return $this->integerAmount($sum);
    }

    /** 普通项目按业务行精确定位，卡项复用已有购买组件权重与当前平台分类，绝不按整单参与收款分配。 */
    private function saleParts(array $origin, array $person): array
    {
        $key = 'sale-parts:'.json_encode([$person['store_id'],$origin['order_id'],$origin['checkout_request_id'],$origin['source_line_id']]);
        if (isset($this->cache[$key])) return $this->cache[$key];
        $sales = Db::name('cashier_v3_sale_fact')->where('tenant_id', '0')->where('store_id', (int)$person['store_id'])
            ->where('checkout_request_id', (string)$origin['checkout_request_id'])->where('source_line_id', (string)$origin['source_line_id'])
            ->where('status', 'effective')->where('fact_direction', 'forward')
            ->field('fact_id sale_fact_id,store_id,source_type,item_id product_id')->select()->toArray();
        if (!$sales && (string)$origin['source_document_type'] === 'debt_repayment')
            return $this->cache[$key] = $this->repaymentParts($origin, $person);
        if (count($sales) !== 1) throw new \DomainException('人员分配尚未唯一关联原销售业务行；独立退款需补充原行证明');
        $parts = (new StoreReportSaleCategoryServices())->resolve($sales)[0] ?? [];
        if ($parts === []) throw new \DomainException('原销售缺少商品或卡内项目身份');
        $weightField = null;
        if ($sales[0]['source_type'] === 'card') {
            foreach (['cash_performance_amount_cents','sale_amount_cents','configured_amount_cents'] as $candidate) {
                $total = '0';
                foreach ($parts as $part) $total = bcadd($total, (string)abs((int)($part[$candidate] ?? 0)), 0);
                if (bccomp($total, '0') > 0) { $weightField = $candidate; break; }
            }
            if ($weightField === null) throw new \DomainException('卡内项目缺少可守恒的权威分摊权重');
        }
        $grouped = [];
        foreach ($parts as $part) {
            $partKey = (string)($part['card_receipt_id'] ?? '').':'.sprintf('%020d', (int)$part['product_id']).':'.sprintf('%020d', (int)$part['id']);
            if (!isset($grouped[$partKey])) $grouped[$partKey] = ['id'=>(int)$part['id'], 'weight'=>'0'];
            $grouped[$partKey]['weight'] = bcadd($grouped[$partKey]['weight'], $weightField === null ? '1' : (string)abs((int)($part[$weightField] ?? 0)), 0);
        }
        ksort($grouped, SORT_STRING);
        return $this->cache[$key] = array_values($grouped);
    }

    /**
     * 补交没有虚构销售行：由本人分配事实证明补交单身份，再按该单收款到原销售行的已落账分摊定位组件。
     * 分类 Reader 返回的整笔金额只作权重，最终工资金额始终是人员 Reader 给出的本人份额。
     */
    private function repaymentParts(array $origin, array $person): array
    {
        $receipts = Db::name('cashier_v3_payment_fact')->where('tenant_id', '0')->where('store_id', (int)$person['store_id'])
            ->where('order_id', (string)$origin['order_id'])->where('source_document_type', 'debt_repayment')
            ->where('status', 'effective')->column('fact_id');
        if (!$receipts) throw new \DomainException('销售补交缺少同单成功收款身份');
        $allocations = Db::name('cashier_v3_payment_sale_allocation_fact')->where('tenant_id', '0')->where('store_id', (int)$person['store_id'])
            ->whereIn('payment_fact_id', $receipts)->where('status', 'effective')->column('id');
        if (!$allocations) throw new \DomainException('销售补交缺少到原销售行的分摊证明，历史欠款需补项目来源');
        $allowed = array_fill_keys(array_map('strval', $allocations), true);
        // 此处不能按原销售参与人过滤：本笔补交可重新指定销售人。范围由本人补交事实+收款分摊身份限定。
        $rows = RegisteredMetricReadServices::withCurrentReportProductCategory()->categoryRows('actual_performance', '0',
            [(int)$person['store_id']], ['start'=>(string)$origin['business_date'],'end'=>(string)$origin['business_date']]);
        $parts = []; $seen = [];
        foreach ($rows as $row) {
            if (!isset($allowed[(string)$row['id']])) continue;
            $seen[(string)$row['id']] = true;
            $categoryId = (int)$row['category_id'];
            if ($categoryId < 1 || !empty($row['classification_coverage'])) throw new \DomainException('补交原销售项目的当前分类或卡内项目未能验证');
            $key = (string)$row['id'].':'.sprintf('%020d',(int)$row['item_id']).':'.sprintf('%020d',$categoryId);
            if (!isset($parts[$key])) $parts[$key] = ['id'=>$categoryId,'weight'=>'0'];
            $parts[$key]['weight'] = bcadd($parts[$key]['weight'], (string)abs((int)$row['amount_cents']), 0);
        }
        if (!$parts || array_diff_key($allowed, $seen)) throw new \DomainException('补交收款尚无完整可验证的统一分类投影');
        ksort($parts, SORT_STRING);
        return array_values($parts);
    }

    /** 劳动分配对应一次完成服务的一个项目，不以项目消耗金额代替个人劳动金额。 */
    private function laborParts(array $origin, array $person): array
    {
        $services = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')->where('store_id', (int)$person['store_id'])
            ->where('checkout_request_id', (string)$origin['checkout_request_id'])->where('source_line_id', (string)$origin['source_line_id'])
            ->where('service_status', 'completed')->field('project_id product_id,store_id')->select()->toArray();
        if (count($services) !== 1) throw new \DomainException('劳动分配未唯一关联完成服务项目');
        $category = (new StoreReportCurrentProductCategoryServices())->resolveVerifiedFactItems($services)[0] ?? [];
        return [['id'=>(int)($category['id'] ?? 0), 'weight'=>'1']];
    }

    /** 门店现金不再扣合作人员份额；排除充值只剔除同口径正向充值收款，不能减充值本金或净充值。 */
    private function storeCashRuleMetric(array $person, array $range, ?array $days, array $categories, bool $includeRecharge): int
    {
        $recharges = $this->rechargeReceipts($person, $range);
        if ($categories === []) {
            $amount = (string)$this->metric('cash_performance', $person, $range, true, $days);
            if (!$includeRecharge) foreach ($recharges as $fact) {
                if ((int)$fact['amount_cents'] <= 0 || ($days !== null && !in_array($fact['business_date'], $days, true))) continue;
                $amount = bcsub($amount, (string)$fact['amount_cents'], 0);
            }
            return $this->integerAmount($amount);
        }
        $key = 'store-cash-category:'.json_encode([$person['store_id'],$range]);
        if (!isset($this->cache[$key])) $this->cache[$key] = RegisteredMetricReadServices::withCurrentReportProductCategory()
            ->categoryRows('cash_performance', '0', [(int)$person['store_id']], $range);
        $rechargeIds = array_fill_keys(array_column($recharges, 'fact_id'), true); $sum = '0';
        foreach ($this->cache[$key] as $row) {
            if ($days !== null && !in_array($row['business_date'], $days, true)) continue;
            if (!$includeRecharge && isset($rechargeIds[(string)($row['fact_id'] ?? '')])) continue;
            if ((int)$row['amount_cents'] !== 0 && ((int)$row['category_id'] < 1 || !empty($row['classification_coverage'])))
                throw new \DomainException('门店现金分类中存在未验证的商品、充值项目或卡内项目身份');
            if (in_array((int)$row['category_id'], $categories, true)) $sum = bcadd($sum, (string)$row['amount_cents'], 0);
        }
        return $this->integerAmount($sum);
    }

    private function rechargeReceipts(array $person, array $range): array
    {
        $key = 'recharge-receipts:'.json_encode([$person['store_id'],$range]);
        if (!isset($this->cache[$key])) $this->cache[$key] = $this->reader->rechargeCashFacts('0', [(int)$person['store_id']], $range);
        return $this->cache[$key];
    }

    /** 内部完整读取统一明细，只用于领域筛选；不改变报表输出分页，超上限显式失败。 */
    private function registeredFactRows(string $metric, array $person, array $range): array
    {
        $key = 'registered-facts:'.json_encode([$metric,$person['store_id'],$range]);
        if (isset($this->cache[$key])) return $this->cache[$key];
        $rows = []; $total = null;
        for ($page = 1; $total === null || count($rows) < $total; ++$page) {
            $part = $this->reader->detailPage($metric, '0', [(int)$person['store_id']], $range, $page, 100);
            $count = (int)$part['total'];
            if ($count > 10000 || ($total !== null && $count !== $total)) throw new \DomainException('工资事实明细超限或不满足一致读取');
            $total = $count;
            if (!$part['rows'] && count($rows) < $total) throw new \DomainException('工资事实明细未完整返回');
            $rows = array_merge($rows, $part['rows']);
        }
        if (count($rows) !== $total) throw new \DomainException('工资事实数量与统一指标不一致');
        return $this->cache[$key] = $rows;
    }

    /**
     * type4 沿旧 theDayYeji：会员在该业务日有成功充值，则该日整笔余额扣款不计，不扣充值金额、不追资金批次。
     * 新报表明确采用本店授权业务范围，避免旧代码跨任意门店查充值；散客没有可匹配会员，不作同日排除。
     * 充值成功以余额入账事实为证，包含零现金赠金和欠款充值权益，不能仅用现金收款 > 0 判断。
     */
    private function storeBalanceRuleMetric(array $person, array $range, ?array $days, array $categories, bool $excludeRechargeDay): int
    {
        $excluded = $excludeRechargeDay ? $this->successfulRechargeDays($person, $range) : [];
        $sum = '0';
        foreach ($this->registeredFactRows('balance_deduction_amount', $person, $range) as $row) {
            if ($days !== null && !in_array($row['business_date'], $days, true)) continue;
            $member = (int)$row['member_id'];
            if ($member > 0 && isset($excluded[$member.'|'.$row['business_date']])) continue;
            $amount = (int)$row['metric_value'];
            if ($categories === [] || $amount === 0) { $sum = bcadd($sum, (string)$amount, 0); continue; }
            $origin = $this->balanceOrigin($row, $person);
            $sales = Db::name('cashier_v3_sale_fact')->where('tenant_id', '0')->where('store_id', (int)$person['store_id'])
                ->where('member_id', (int)$origin['member_id'])->where('order_id', (string)$origin['order_id'])
                ->where('checkout_request_id', (string)$origin['checkout_request_id'])->where('status', 'effective')->where('fact_direction', 'forward')
                ->field('fact_id,order_id,checkout_request_id,source_line_id,sale_amount_cents,debt_amount_cents,coupon_payment_cents')->order('fact_id')->select()->toArray();
            if (!$sales) throw new \DomainException('分类余额扣款缺少原单销售明细，不能按整单重复计入各分类');
            // 采用统一销售分摊的“成交额减该行欠款”权重，保留本项目尾差归稳定末行的最高规则。
            // 冲销金额仍带负号，原正向销售只提供权重，不能把它改造成新的负向销售事实。
            $saleWeights = []; $weightTotal = '0';
            foreach ($sales as $sale) {
                $price = (int)$sale['sale_amount_cents']; $debt = (int)$sale['debt_amount_cents'];
                if ($price < 0 || $debt < 0 || $debt > $price) throw new \DomainException('原销售分摊基数无效');
                $saleWeights[(string)$sale['fact_id']] = (string)($price - $debt);
                $weightTotal = bcadd($weightTotal, (string)($price - $debt), 0);
            }
            if (bccomp((string)abs($amount), $weightTotal) > 0) throw new \DomainException('余额扣款超过原销售可结算金额');
            $allocations = self::allocatePersonalCents($amount, $saleWeights);
            foreach ($sales as $sale) {
                $parts = $this->saleParts($sale + ['source_document_type'=>'sales_order'], $person);
                $weights = [];
                foreach ($parts as $index=>$part) {
                    if ((int)$part['id'] < 1) throw new \DomainException('余额扣款原项目当前分类未能验证');
                    $weights[$index] = $part['weight'];
                }
                foreach (self::allocatePersonalCents((int)$allocations[(string)$sale['fact_id']], $weights) as $index=>$partAmount)
                    if (in_array((int)$parts[$index]['id'], $categories, true)) $sum = bcadd($sum, (string)$partAmount, 0);
            }
        }
        return $this->integerAmount($sum);
    }

    /** 只读取充值事件身份，不重新统计充值金额；有效反向事实或成功作废使原充值不再成为排除依据。 */
    private function successfulRechargeDays(array $person, array $range): array
    {
        $key = 'successful-recharge-days:'.json_encode([$person['store_id'],$range]);
        if (isset($this->cache[$key])) return $this->cache[$key];
        $query = Db::name('cashier_v3_balance_fact')->alias('b')->where('b.tenant_id', '0')->where('b.store_id', (int)$person['store_id'])
            ->whereBetween('b.business_date', [$range['start'],$range['end']])->where('b.member_id', '>', 0)
            ->where('b.status', 'effective')->where('b.fact_direction', 'forward')
            ->whereIn('b.balance_change_type', ['recharge_credit','recharge_debt_repayment_credit'])
            ->whereNotExists(function ($q) { $q->name('cashier_v3_balance_fact')->alias('r51_recharge_reversal')
                ->whereRaw('r51_recharge_reversal.tenant_id=b.tenant_id AND r51_recharge_reversal.store_id=b.store_id AND r51_recharge_reversal.reversal_of=b.fact_id')
                ->where('r51_recharge_reversal.status', 'effective')->where('r51_recharge_reversal.fact_direction', 'reversal'); });
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query, 'b.tenant_id', 'b.order_id');
        $normal->excludeVoidedRechargeOrderFacts($query, 'b.tenant_id', 'b.order_id');
        $normal->excludeVoidedSupplementPaymentFacts($query, 'b.tenant_id', 'b.store_id', 'b.order_id');
        $out = [];
        foreach ($query->field('b.member_id,b.business_date')->group('b.member_id,b.business_date')->select()->toArray() as $row) $out[(int)$row['member_id'].'|'.$row['business_date']] = true;
        return $this->cache[$key] = $out;
    }

    /** 余额冲销沿原事实定位购买明细，金额与统计日仍使用当前净余额事实。 */
    private function balanceOrigin(array $row, array $person): array
    {
        $seen = [];
        for ($depth = 0; (string)$row['reversal_of'] !== '' && $depth < 32; ++$depth) {
            $id = (string)$row['reversal_of'];
            if (isset($seen[$id])) throw new \DomainException('余额事实存在循环冲销链');
            $seen[$id] = true;
            $origin = Db::name('cashier_v3_balance_fact')->where('tenant_id', '0')->where('store_id', (int)$person['store_id'])
                ->where('member_id', (int)$row['member_id'])->where('fact_id', $id)->where('status', 'effective')->find();
            if (!$origin) throw new \DomainException('余额冲销缺少同会员原事实');
            $row = $origin;
        }
        if ((string)$row['reversal_of'] !== '') throw new \DomainException('余额冲销链超限');
        return $row;
    }

    /** 门店消耗仍从统一指标读金额，只以经核实的服务项目身份解析当前分类。 */
    private function consumptionCategoryMetric(array $person, array $range, ?array $days, array $categories): int
    {
        $key = 'consume-categories:'.json_encode([$person['store_id'],$range]);
        if (!isset($this->cache[$key])) {
            $rows = $this->reader->categoryRows('consume_amount', '0', [(int)$person['store_id']], $range);
            $links = [];
            foreach ($rows as $row) $links[] = ['store_id'=>(int)$row['store_id'],'product_id'=>(int)$row['item_id']];
            $resolved = (new StoreReportCurrentProductCategoryServices())->resolveVerifiedFactItems($links);
            $total = '0';
            foreach ($rows as $row) $total = bcadd($total, (string)$row['amount_cents'], 0);
            if (bccomp($total, (string)$this->metric('consume_amount', $person, $range, true, null)) !== 0)
                throw new \DomainException('分类消耗投影与统一消耗总额不守恒，不能重复或遗漏服务行');
            foreach ($rows as $index=>&$row) $row['_category'] = (int)($resolved[$index]['id'] ?? 0);
            unset($row); $this->cache[$key] = $rows;
        }
        $sum = '0';
        foreach ($this->cache[$key] as $row) {
            if ($days !== null && !in_array($row['business_date'], $days, true)) continue;
            if ((int)$row['amount_cents'] !== 0 && $row['_category'] === 0) throw new \DomainException('消耗事实的当前项目分类无法验证');
            if (in_array($row['_category'], $categories, true)) $sum = bcadd($sum, (string)$row['amount_cents'], 0);
        }
        return $this->integerAmount($sum);
    }

    /** 个人金额按稳定组件顺序向零取整，全部分余数归最后一个正权重组件，正负金额均守恒。 */
    private static function allocatePersonalCents(int $amount, array $weights): array
    {
        $total = '0'; $last = null;
        foreach ($weights as $key=>$weight) {
            if (!preg_match('/^\d+$/D', $weight)) throw new \DomainException('组件分摊权重无效');
            $total = bcadd($total, $weight, 0);
            if (bccomp($weight, '0') > 0) $last = $key;
        }
        if ($last === null) throw new \DomainException('组件分摊权重总额为零');
        $remaining = (string)$amount; $out = [];
        foreach ($weights as $key=>$weight) {
            $part = $key === $last ? $remaining : bcdiv(bcmul((string)$amount, $weight, 0), $total, 0);
            $remaining = bcsub($remaining, $part, 0); $out[$key] = (int)$part;
        }
        return $out;
    }

    private function integerAmount(string $value): int
    {
        if (bccomp($value, (string)PHP_INT_MAX) > 0 || bccomp($value, (string)PHP_INT_MIN) < 0) throw new \DomainException('工资金额超出精确计算范围');
        return (int)$value;
    }

    /** time_type=2 只取本员工、本门店、当月已登记代班日期；空登记表示明确的空日期集合。 */
    private function agentDays(array $rule, array $person, array $range): ?array
    {
        if ((int)$rule['time_type'] === 1) return null;
        if ((int)$rule['time_type'] !== 2 || (int)$person['staff_id'] < 1) throw new \DomainException('代班日期类型或任职身份缺失');
        $dates = Db::name('salary_agent')->where('staff_id', (int)$person['staff_id'])->where('store_id', (int)$person['store_id'])->column('date');
        $days = [];
        foreach ($dates as $csv) foreach (explode(',', (string)$csv) as $date) {
            $date = trim($date);
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new \DomainException('代班日期包含无效日期');
            if ($date >= $range['start'] && $date <= $range['end']) $days[$date] = true;
        }
        return array_keys($days);
    }

    /** 缓存仅限一次一致读取，按门店/员工/指标/日期集合隔离，重查总是重新读取事实。 */
    private function metric(string $code, array $person, array $range, bool $store, ?array $days): int
    {
        $key = json_encode([$code,$person['store_id'],$store ? 0 : $person['employee_id'],$range,$days]);
        if (array_key_exists($key, $this->cache)) return $this->cache[$key];
        $ranges = $days === null ? [$range] : array_map(static function ($day) { return ['start'=>$day,'end'=>$day]; }, $days);
        $sum = '0';
        foreach ($ranges as $part) {
            if ($store) $value = $this->reader->summary($code, '0', [(int)$person['store_id']], $part);
            elseif (in_array($code, ['staff_service_num','staff_point_visit_num'], true))
                $value = $this->reader->personnelTotal($code, '0', [(int)$person['store_id']], $part,
                    [['store_id'=>(int)$person['store_id'], 'employee_id'=>(int)$person['employee_id']]]);
            else {
                // 日维度 Reader 同时应用销售、充值、补交及服务作废过滤；不能绕过该完整出口。
                $value = '0';
                foreach ($this->reader->personnelDailyTotals($code, '0', [(int)$person['store_id']], $part, [(int)$person['employee_id']]) as $point)
                    $value = bcadd($value, (string)$point['metric_value'], 0);
            }
            $sum = bcadd($sum, (string)$value, 0);
        }
        if (bccomp($sum, (string)PHP_INT_MAX) > 0 || bccomp($sum, (string)PHP_INT_MIN) < 0) throw new \RuntimeException('工资指标超出精确计算范围');
        return $this->cache[$key] = (int)$sum;
    }

    private function gap(array &$row, string $field, string $code, string $message): void
    {
        $row['gaps'][] = ['field'=>$field,'code'=>$code,'message'=>$message];
    }

    private function sources(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $text = '按员工稳定身份、业务门店和自然月查询；未完成、失败业务不计入，退款和作废沿统一指标冲销口径。';
            if (isset(self::MANUAL[$key])) $text = '独立读取该员工在该门店、该月原先人工填写的补充数值；未填写的人工数值沿用原工资缺省零参与试算，原空值另行保留，不回写，当前只读；格式异常不当作零。';
            elseif ($key === 'mendianxianjinyeji' || $key === 'mendianxianjinticheng') $text = '统一现金业绩作为门店现金提成基数，不扣合作人员分配款；排除充值时只剔除成功正向充值收款，分类按原销售卡内项目及当前平台分类查询，不减充值本金或净充值。';
            elseif ($key === 'mendianlaodongyeji' || $key === 'mendianlaodongticheng') $text = '门店项目成功完成服务后计入统一消耗业绩，按岗位职级和销售档位计算门店消耗提成；不是余额扣款，也不是个人劳动分配，充值不加入消耗基数。';
            elseif ($key === 'work_yeji' || $key === 'work_get') $text = PayrollAlgorithmPolicy::calculated()
                ? '成功服务分配给本人的劳动业绩，按本员工本门店自然月净销售业绩匹配项目提成档位；预计结果，不代表已锁薪。手工费单独保留。'
                : '成功服务后分配给本人的劳动业绩，按岗位职级和销售档位计算劳动提成；即使旧配置勾选包含充值，也不把充值加入劳动基数。';
            elseif ($key === 'mendianshijikoukayeji' || $key === 'mendianshijikoukaticheng') $text = '从净余额订单扣款中，整笔排除该会员在本店同一业务日有成功余额充值入账的扣款；零现金赠金或欠款充值也算充值事件，已作废或已冲销充值不算；散客不排除，不减充值额、不追资金批次，分类仍按原销售项目守恒分摊。';
            elseif (isset(self::FORMULAS[$key])) $text = '按已配置工资公式的白名单字段与四则运算实时计算；任一依赖缺失、循环或除零时保持空值并说明原因。';
            elseif (isset(self::COMMISSIONS[$key]) || isset(self::BASES[$key])) $text = '读取月内岗位及职级匹配的提成规则，按规则区间和提点试算；代班只统计已登记日期，个人金额先按权威原业务行及卡内项目守恒分配再筛分类，原行或冲销链缺失时明确报告原因。';
            elseif ($key === 'sales_performance') $text = '统一指标中本人最终分配到的现金业绩；包括有效充值、欠款后续补交及其反向事实，不以整单参与金额代替个人份额。';
            elseif ($key === 'labor_performance') $text = '成功服务后分配给本人的劳动业绩及反向事实；不等同于余额扣款或项目消耗业绩。';
            elseif ($key === 'labor_fee') $text = '成功服务时独立保存、分配给本人的手工费及其反向事实，不按劳动业绩推算。';
            elseif ($key === 'xiangmushu') $text = '本人已完成服务的工资项目份额，保留百万分位精度；不等于销售数量。';
            elseif ($key === 'xinkeshu') $text = '从会员全历史有效首次全款疗程卡成交中确定首笔，再按全款达成业务日计入本月、本店，归原成交记录的销售人员；同一员工同一会员只计一次，定金及作废不计，普通退款（含部分、全额、同单退产品）及其业绩反向不取消新客或原人员归属；仅同租户/门店/原销售单、命令键关联成功人员更正的反向可替换旧归属，不依赖来源或项目固定编号。';
            $out[$key] = $text;
        }
        return $out;
    }

    private static function decimalCents(string $value): int
    {
        $cents = bcmul($value, '100', 0);
        if (bccomp($cents, (string)PHP_INT_MAX) > 0) throw new \DomainException('工资区间超出精确金额范围');
        return (int)$cents;
    }

    /** 对称四舍五入，金额计算始终在十进制定点域，不用浮点乘提点。 */
    private static function roundDecimal(string $value, int $scale): string
    {
        $half = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        return bcadd($value, bccomp($value, '0', 12) < 0 ? '-'.$half : $half, $scale);
    }

    /**
     * 将旧四则公式解析为有界表达式树，只允许十进制常量/白名单变量/+ - * //括号。
     * 不执行 PHP、SQL、函数、属性、数组或字符串，不清洗非法字符后继续执行。
     */
    private static function formula(string $expression, callable $resolve): string
    {
        if ($expression === '' || strlen($expression) > 2048) throw new \DomainException('工资公式为空或过长');
        preg_match_all('/\s+|[A-Za-z_][A-Za-z_0-9]*|\d+(?:\.\d{1,6})?|[()+*\/\-]/', $expression, $matches);
        if (implode('', $matches[0]) !== $expression) throw new \DomainException('工资公式含非法字符或精度');
        $tokens = array_values(array_filter($matches[0], static function ($t) { return trim($t) !== ''; }));
        if (count($tokens) > 256) throw new \DomainException('工资公式过于复杂');
        $index = 0; $depth = 0; $parse = null; $atom = null;
        $atom = function () use (&$atom, &$parse, &$index, &$depth, $tokens, $resolve): string {
            if (++$depth > 32) throw new \DomainException('工资公式嵌套过深');
            try {
                $token = $tokens[$index++] ?? '';
                if ($token === '+' || $token === '-') {
                    $value = $atom(); return $token === '-' ? bcmul($value, '-1', 12) : $value;
                }
                if ($token === '(') {
                    $value = $parse(0);
                    if (($tokens[$index++] ?? '') !== ')') throw new \DomainException('工资公式括号不匹配');
                    return $value;
                }
                if (preg_match('/^\d+(?:\.\d{1,6})?$/D', $token)) return $token;
                if (preg_match('/^[A-Za-z_][A-Za-z_0-9]*$/D', $token)) return $resolve($token);
                throw new \DomainException('工资公式缺少合法操作数');
            } finally { --$depth; }
        };
        $parse = function (int $min) use (&$parse, &$atom, &$index, $tokens): string {
            $value = $atom();
            $precedence = ['+'=>1,'-'=>1,'*'=>2,'/'=>2];
            while (isset($tokens[$index], $precedence[$tokens[$index]]) && $precedence[$tokens[$index]] >= $min) {
                $op = $tokens[$index++]; $right = $parse($precedence[$op] + 1);
                if ($op === '/' && bccomp($right, '0', 12) === 0) throw new \DomainException('工资公式除数为零');
                $value = $op === '+' ? bcadd($value,$right,12) : ($op === '-' ? bcsub($value,$right,12)
                    : ($op === '*' ? bcmul($value,$right,12) : bcdiv($value,$right,12)));
                if (bccomp($value, '90071992547409', 12) > 0 || bccomp($value, '-90071992547409', 12) < 0)
                    throw new \DomainException('工资公式结果超出金额精确范围');
            }
            return $value;
        };
        $value = $parse(0);
        if ($index !== count($tokens)) throw new \DomainException('工资公式含未支持的语法');
        return $value;
    }
}
