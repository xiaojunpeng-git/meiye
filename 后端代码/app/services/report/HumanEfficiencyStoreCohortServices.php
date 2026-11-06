<?php

declare(strict_types=1);
namespace app\services\report;

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\query\metric\StoreCustomerVisitEligibility;

/**
 * 店长/顾问院店分级：使用整店事实，不累计店长、顾问的个人分配。
 * 仅接收上游已授权门店；只读投影，查询和导出共用，不写人员或历史业绩。
 */
final class HumanEfficiencyStoreCohortServices
{
    public const VERSION = 'human-store-cohort-v1';

    /** 每个覆盖自然月计1个月，最后一个月的人数只看查询截止日，不读取未来任职。 */
    public static function monthEnds(array $range): array
    {
        $ends = [];
        for ($month = new \DateTimeImmutable(substr($range['start'], 0, 7) . '-01');
             $month->format('Y-m') <= substr($range['end'], 0, 7); $month = $month->modify('+1 month')) {
            $ends[] = min($month->format('Y-m-t'), $range['end']);
        }
        return $ends;
    }

    /**
     * 按有效任职和岗位区间计算人员集合；有历史区间时不拿当前状态覆盖历史。
     * 无区间的旧档沿用现有人效名册的入职日期/有效岗位补证，不把已结束岗位算在职。
     */
    public static function workforce(array $data, array $stores, string $day): array
    {
        $at = strtotime($day . ' 23:59:59');
        $tenures = []; $jobs = []; $out = [];
        foreach ($stores as $id) $out[$id] = ['leader'=>[], 'advisor'=>[], 'beauty'=>[]];
        foreach ($data['tenures'] as $period) $tenures[(int)$period['staff_id']][] = $period;
        foreach ($data['jobs'] as $job) $jobs[(int)$job['staff_id']][] = $job;
        foreach ($data['staff'] as $person) {
            $store = (int)$person['store_id']; $employee = (int)$person['employee_id']; $id = (int)$person['staff_id'];
            if (!isset($out[$store]) || $employee <= 0) continue;
            $validJobs = array_values(array_filter($jobs[$id] ?? [], static fn(array $j): bool => HumanEfficiencyRules::employedAt($j, $at)));
            usort($validJobs, static fn(array $a, array $b): int => [(int)$b['start_time'], (int)($b['id'] ?? 0)] <=> [(int)$a['start_time'], (int)($a['id'] ?? 0)]);
            $active = false;
            if (isset($tenures[$id])) {
                foreach ($tenures[$id] as $period) if (HumanEfficiencyRules::employedAt($period, $at)) $active = true;
            } elseif ((int)$person['status'] === 1 && (int)$person['is_del'] === 0) {
                $joined = is_numeric($person['join_date']) ? (int)$person['join_date'] : strtotime((string)$person['join_date']);
                $active = ($joined > 0 && $joined <= $at) || count($validJobs) > 0;
            }
            if (!$active) continue;
            $role = HumanEfficiencyRules::role($validJobs[0] ?? null, $person);
            if (isset($out[$store][$role])) $out[$store][$role][$employee] = true;
        }
        return $out;
    }

    /** 逐店读取现金与服务，释放宽事实；消耗由注册指标按店聚合，避免人员份额重复累计。 */
    public function read(array $stores, array $range, array $staff, array $categories, array $roots, array $knownMetrics = []): array
    {
        $ends = self::monthEnds($range);
        $endPeople = self::workforce($staff, $stores, $range['end']);
        $monthlyBeauty = array_fill_keys($stores, 0);
        foreach ($ends as $end) foreach (self::workforce($staff, $stores, $end) as $id=>$people) $monthlyBeauty[$id] += count($people['beauty']);
        $reader = new HumanEfficiencyFactReader();
        // 同一请求中经理看板已读过的门店事实直接复用；无经理门店仍独立读取，不能漏店。
        // 历史销售可能缺少分类维度关联。整店金额必须读取注册总额，不能用分类明细的内连接结果代替；
        // 已有经理事实只复用分类和服务去重，不让其缺失维度传播到新表的整店现金分档。
        $registered = RegisteredMetricReadServices::withCurrentReportProductCategory();
        $cash = array_column($registered->storeTotals('cash_performance', '0', $stores, $range), 'metric_value', 'store_id');
        $consume = array_column($registered->storeTotals('consume_amount', '0', $stores, $range), 'metric_value', 'store_id');
        $rows = [];
        foreach ($stores as $store) {
            $row = ['store_id'=>$store, 'cash_cents'=>0, 'consume_cents'=>(int)($consume[$store] ?? 0),
                'category_cents'=>[], 'beauty_month_count'=>$monthlyBeauty[$store],
                'leaders'=>$endPeople[$store]['leader'], 'advisors'=>$endPeople[$store]['advisor']];
            if (isset($knownMetrics[$store])) {
                $rows[] = self::withAuthoritativeTotals(array_replace($row, $knownMetrics[$store]), (int)($cash[$store] ?? 0), (int)($consume[$store] ?? 0));
                continue;
            }
            foreach ($reader->cash([$store], $range) as $fact) {
                $cents = (int)$fact['amount_cents']; $root = $roots[(int)($fact['category_id'] ?? 0)] ?? 0;
                $row['cash_cents'] += $cents;
                $row['category_cents'][$root] = ($row['category_cents'][$root] ?? 0) + $cents;
            }
            $active = []; $visits = [];
            foreach ($reader->services([$store], $range) as $fact) {
                $visit = StoreCustomerVisitEligibility::dailyVisitKey($fact);
                if ($visit === null) continue;
                $visits[$visit] = true;
                // 月均活客须先按门店、月份、会员去重；跨月到店在各月各计一次。
                $member = (int)($fact['member_id'] ?? 0);
                if ($member > 0) $active[substr($fact['business_date'], 0, 7) . ':' . $member] = true;
            }
            $row['active_month_count'] = count($active); $row['visits'] = count($visits);
            $rows[] = self::withAuthoritativeTotals($row, (int)($cash[$store] ?? 0), (int)($consume[$store] ?? 0));
        }
        return self::project($rows, count($ends), $categories);
    }

    /**
     * 分类缺证据的真实现金保留在“其他/未分类”，不猜商品归属、不修写历史事实。
     * 注册总额权威决定分档和消耗；分类超过总额属于口径异常，不能用负数补差掩盖重复计算。
     */
    public static function withAuthoritativeTotals(array $row, int $cash, int $consume): array
    {
        $unclassified = $cash - array_sum($row['category_cents']);
        if ($unclassified < 0) throw new \RuntimeException('现金分类金额超过统一现金总额，请核对事实口径。');
        if ($unclassified > 0) $row['category_cents'][0] = ($row['category_cents'][0] ?? 0) + $unclassified;
        $row['cash_cents'] = $cash;
        $row['consume_cents'] = $consume;
        return $row;
    }

    /** 分档用未舍入的整店现金总额比较，30/20/10万边界不受前端整元展示影响。 */
    public static function grade(int $cents, int $months): string
    {
        if ($months < 1) throw new \InvalidArgumentException('统计月份数必须大于0');
        if ($cents >= 30000000 * $months) return 'A';
        if ($cents >= 20000000 * $months) return 'B';
        if ($cents >= 10000000 * $months) return 'C';
        return 'D';
    }

    /**
     * 纯聚合，供查询/导出/边界测试共用。合计重新以全范围院店数计算，不能相加分类均值。
     * 分类外现金仍保留总额；仅在有金额时补“其他/未分类”，避免展示分类和总额对不上。
     */
    public static function project(array $stores, int $months, array $categories): array
    {
        if ($months < 1) throw new \InvalidArgumentException('统计月份数必须大于0');
        $labels = ['A'=>'A类院店', 'B'=>'B类院店', 'C'=>'C类院店', 'D'=>'D类院店'];
        $rules = ['A'=>'月均业绩 ≥ 30万', 'B'=>'20万 ≤ 月均业绩 < 30万', 'C'=>'10万 ≤ 月均业绩 < 20万', 'D'=>'月均业绩 < 10万'];
        $groups = array_fill_keys(array_keys($labels), []); $hasLeader = 0; $advisors = []; $other = false;
        foreach ($stores as $store) {
            $groups[self::grade($store['cash_cents'], $months)][] = $store;
            if ($store['leaders']) ++$hasLeader;
            $advisors += $store['advisors'];
            if (($store['category_cents'][0] ?? 0) !== 0) $other = true;
        }
        if ($other) $categories[] = ['id'=>0, 'name'=>'其他/未分类'];
        $rows = [];
        foreach ($groups as $id=>$members) $rows[] = self::aggregate($members, $months, $categories) + ['id'=>$id, 'label'=>$labels[$id], 'rule'=>$rules[$id]];
        return ['available'=>true, 'metric_version'=>self::VERSION, 'months'=>$months,
            'summary'=>['with_leader'=>$hasLeader, 'without_leader'=>count($stores)-$hasLeader, 'advisors'=>count($advisors)],
            'rows'=>$rows, 'total'=>self::aggregate($stores, $months, $categories) + ['id'=>'total', 'label'=>'合计', 'rule'=>'按全部院店重新计算均值'],
            'layout'=>self::layout($categories), 'source_explanations'=>self::explanations()];
    }

    private static function aggregate(array $stores, int $months, array $categories): array
    {
        $out = ['stores'=>count($stores), 'cash_cents'=>0, 'consume_cents'=>0, 'beauty_month_count'=>0, 'active_month_count'=>0, 'visits'=>0];
        $leaders = []; $advisors = []; $cash = [];
        foreach ($stores as $store) {
            foreach (['cash_cents','consume_cents','beauty_month_count','active_month_count','visits'] as $key) $out[$key] += $store[$key];
            $leaders += $store['leaders']; $advisors += $store['advisors'];
            foreach ($store['category_cents'] as $id=>$value) $cash[$id] = ($cash[$id] ?? 0) + $value;
        }
        $out['leaders'] = count($leaders); $out['advisors'] = count($advisors);
        $divisor = count($stores) * $months;
        foreach (['beauty_month_count'=>'average_beauty', 'active_month_count'=>'average_active', 'visits'=>'average_visits'] as $key=>$metric) $out[$metric] = HumanEfficiencyRules::ratio($out[$key], $divisor);
        $out['per_beauty_active'] = HumanEfficiencyRules::ratio($out['active_month_count'], $out['beauty_month_count']);
        $out['per_beauty_visits'] = HumanEfficiencyRules::ratio($out['visits'], $out['beauty_month_count']);
        $out['average_consume'] = HumanEfficiencyRules::ratio($out['consume_cents'], $divisor * 100);
        $out['average_cash'] = HumanEfficiencyRules::ratio($out['cash_cents'], $divisor * 100);
        foreach ($categories as $category) $out['category_values'][$category['id']] = HumanEfficiencyRules::ratio($cash[$category['id']] ?? 0, $divisor * 100);
        return $out;
    }

    /** 列顺序、宽度、固定行为和非加性均值全部由后端声明。 */
    private static function layout(array $categories): array
    {
        $column = static fn(string $key, string $label, string $group, string $format='number', int $width=130): array =>
            ['key'=>$key, 'label'=>$label, 'path'=>[$key], 'group'=>$group, 'format'=>$format, 'width'=>$width, 'fixed'=>$key==='label', 'additive'=>$key==='stores', 'drilldown'=>false];
        $columns = [$column('label','院店分类','identity','text',210), $column('stores','院店数','people','number',85),
            $column('leaders','店长人数','people','number',95), $column('advisors','顾问人数','people','number',95),
            $column('average_beauty','月店均美容师人数','people'), $column('average_active','月店均活客数','service'),
            $column('average_visits','月店均售后人次','service'), $column('per_beauty_active','月店均人均活客数','average'),
            $column('per_beauty_visits','月店均人均售后人次','average'), $column('average_consume','月店均消耗业绩','money','money')];
        foreach ($categories as $category) {
            $c = $column('category:'.$category['id'], '月店均'.$category['name'].'销售业绩', 'money', 'money', 145);
            $c['path'] = ['category_values', $category['id']]; $columns[] = $c;
        }
        $columns[] = $column('average_cash','月店均总销售业绩','money','money',150);
        return ['fixed'=>true, 'columns'=>$columns];
    }

    private static function explanations(): array
    {
        return [
            '院店分级：每家门店以所选日期内的整店现金业绩÷覆盖月份数分档，含有效销售及后续补款，不扣现金退款；按未经展示舍入的金额判断30万、20万、10万边界。',
            '月份数：所选日期覆盖的每个自然月计1个月，不足一个月按1个月；仅统计选中日期的业务，不自动补齐整月。',
            '月店均：本类门店对应指标合计÷覆盖月份数÷本类院店数。月店均总销售业绩读取统一现金总额；未进入已启用分类或历史分类关联缺失的金额列入其他/未分类。',
            '人员：有/无在职店长按查询截止日的有效任职及岗位判断，一店多位店长仍只计1家；店长和顾问人数按员工身份去重。月店均美容师人数为各店各月末在岗人数之和÷月份数÷院店数，最后一个月取月末与查询截止日中较早的一天。',
            '服务：月活客按每店每月完成非体验服务的会员去重，跨月分别计入；售后人次沿用同店同会员同业务日去重口径。月店均人均活客/售后人次分别除以月店均美容师人数，零分母显示—。',
            '消耗业绩：读取实际完成服务的统一消耗业绩，不累计员工个人劳动分配，不把售卡金额或余额扣款当消耗。',
            '合计：院店数汇总，人员重新去重，各项月店均和人均按完整查询范围重算，不相加A/B/C/D行的均值。',
        ];
    }
}
