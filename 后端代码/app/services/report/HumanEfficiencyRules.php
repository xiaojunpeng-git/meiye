<?php

declare(strict_types=1);
namespace app\services\report;

/** 人效看板的纯投影规则。只接受已授权事实及后端目录，不从名称猜测分类或扩大范围。 */
final class HumanEfficiencyRules
{
    /** 根分类沿用集团看板的统计开关和稳定顺序；没有配置时返回空列，零业绩不删配置。 */
    public static function categories(array $rows): array
    {
        $ids = array_fill_keys(array_map('intval', array_column($rows, 'id')), true);
        $roots = array_values(array_filter($rows, static function (array $row) use ($ids): bool {
            return (int)$row['id'] > 0 && !isset($ids[(int)$row['pid']]) && (int)$row['statistics_enabled'] === 1;
        }));
        usort($roots, static fn(array $a, array $b): int => [(int)$b['sort'], (int)$b['id']] <=> [(int)$a['sort'], (int)$a['id']]);
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['cate_name']], $roots);
    }

    /** 副店长即顾问，必须先于兼任店长标记判断；保留既有顾问岗位别名。 */
    public static function role(?array $job, array $staff): string
    {
        if (in_array($job['position_name'] ?? '', ['副店长', '顾问'], true)) return 'advisor';
        if ((int)($job['is_store_manager'] ?? 0) === 1) return 'leader';
        if (($job['position_name'] ?? '') === '美容师' || (int)($staff['cashier_craftsman_enabled'] ?? 0) === 1) return 'beauty';
        return 'unknown';
    }

    /** 完整命中榜单决定分类显隐，禁止按当前页或净额抵消判断；人头或分值非零即保留。 */
    public static function visibleCategories(array $categories, array $rows): array
    {
        return array_values(array_filter($categories, static function (array $category) use ($rows): bool {
            foreach ($rows as $row) {
                $value = $row['category_values'][$category['id']] ?? [];
                if ((int)($value['cash_cents'] ?? 0) !== 0 || (int)($value['buyers'] ?? 0) !== 0) return true;
            }
            return false;
        }));
    }

    /** 历史归属只按事实的组织路径和发生日匹配，禁止回退到当前门店组织。有效截止日包含当天。 */
    public static function historicalManager(string $path, string $day, array $dimensions): ?array
    {
        $pathIds = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $id): bool => ctype_digit($id)));
        foreach (array_reverse($pathIds) as $id) {
            $matches = array_values(array_filter($dimensions, static function (array $r) use ($id, $day): bool {
                return (string)$r['organization_id'] === $id && (string)$r['valid_from'] <= $day
                    && (empty($r['valid_to']) || (string)$r['valid_to'] >= $day)
                    && (int)$r['enabled'] === 1 && empty($r['deleted_at']);
            }));
            if (count($matches) > 1) throw new \InvalidArgumentException('城市经理历史管辖期间重叠，请先修正组织统计维度');
            if ($matches) return $matches[0];
        }
        return null;
    }

    /** 半开任职区间；离职后的当前状态不会抹掉过去期末的有效任职。 */
    public static function employedAt(array $period, int $at): bool
    {
        return (int)$period['start_time'] <= $at && (int)$period['start_time'] > 0
            && ((int)$period['end_time'] === 0 || (int)$period['end_time'] > $at);
    }

    /** 工龄以自然月周年划分，入职日期缺失单独展示，不能默认为新员工。 */
    public static function tenureGroup(?string $joined, string $end): string
    {
        $date = $joined ? \DateTimeImmutable::createFromFormat('!Y-m-d', $joined) : false;
        if (!$date || $date->format('Y-m-d') !== $joined || $joined > $end) return 'unknown';
        $cutoff = new \DateTimeImmutable($end);
        if ($date->modify('+3 years') <= $cutoff) return 'three_years';
        if ($date->modify('+1 year') <= $cutoff) return 'one_year';
        if ($date->modify('+6 months') <= $cutoff) return 'six_months';
        return 'new';
    }

    /** 比率与人均由后端计算；未知/零目标不制造0%或无穷大。 */
    public static function ratio($value, $denominator): ?float
    {
        return $denominator !== null && $denominator > 0 ? $value / $denominator : null;
    }

    /** 月目标按实际管辖天数分摊到分；稳定余数保证全月目标守恒，未知归属保留独立桶。 */
    public static function allocateTarget(int $cents, array $daysByManager): array
    {
        if ($cents < 0) throw new \InvalidArgumentException('门店目标不能为负数');
        ksort($daysByManager, SORT_STRING);
        $days = array_sum($daysByManager);
        if ($days <= 0) return [];
        $out = []; $remainders = []; $used = 0;
        foreach ($daysByManager as $key => $count) {
            if (!is_int($count) || $count < 0) throw new \InvalidArgumentException('目标管辖天数不正确');
            $whole = intdiv($cents, $days) * $count;
            $residue = ($cents % $days) * $count;
            $out[$key] = $whole + intdiv($residue, $days);
            $remainders[$key] = $residue % $days;
            $used += $out[$key];
        }
        // 相同余数按稳定组织ID分配；重复查询、分页和导出不能改变目标。
        $keys = array_keys($out);
        usort($keys, static fn($a, $b): int => ($remainders[$b] <=> $remainders[$a]) ?: strcmp((string)$a, (string)$b));
        for ($i = 0; $i < $cents - $used; $i++) $out[$keys[$i]]++;
        return $out;
    }

    /** 全部命中集排序后分页。顾问先按门店组排序再按实际行分页，店长/经理按完成率且空目标置末。 */
    public static function page(array $rows, string $kind, int $page): array
    {
        if (!in_array($kind, ['advisor', 'leader', 'manager'], true) || $page < 1) throw new \InvalidArgumentException('榜单分页参数不正确');
        if ($kind === 'advisor') {
            $groups = [];
            foreach ($rows as $r) {
                $key = (int)$r['store_id'];
                if (!isset($groups[$key])) $groups[$key] = ['store_id' => $key, 'cash' => 0, 'rows' => []];
                if ($r['role_code'] === 'advisor') $groups[$key]['cash'] += $r['cash_cents'];
                $groups[$key]['rows'][] = $r;
            }
            $rows = array_values($groups);
            usort($rows, static fn(array $a, array $b): int => ($b['cash'] <=> $a['cash']) ?: ($a['store_id'] <=> $b['store_id']));
        } else {
            usort($rows, static function (array $a, array $b): int {
                return (($a['completion'] === null) <=> ($b['completion'] === null))
                    ?: (($b['completion'] ?? 0) <=> ($a['completion'] ?? 0))
                    ?: ($b['cash_cents'] <=> $a['cash_cents']) ?: strcmp((string)$a['id'], (string)$b['id']);
            });
        }
        // 先稳定排序并展开同店人员，再按10个实际显示行分页；跨页仍重复门店和院店排名，便于识别续组。
        $ordered=[];
        foreach($rows as $i=>$item){
            if($kind!=='advisor'){$item['rank']=$i+1;$ordered[]=$item;continue;}
            usort($item['rows'],static fn(array $a,array $b):int=>(($a['role_code']!=='advisor')<=>($b['role_code']!=='advisor'))
                ?:($b['cash_cents']<=>$a['cash_cents'])?:strcmp((string)$a['id'],(string)$b['id']));
            foreach($item['rows'] as $j=>$row){$row['rank']=$i+1;$row['group_start']=$j===0;$ordered[]=$row;}
        }
        $total=count($ordered);$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);
        return ['rows'=>array_slice($ordered,($page-1)*10,10),'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>10,'unit'=>'条'];
    }
}
