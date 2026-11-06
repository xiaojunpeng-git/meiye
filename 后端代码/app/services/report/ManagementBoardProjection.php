<?php
declare(strict_types=1);
namespace app\services\report;

/** 门店管理看板的只读转置：输入仅为已授权的人效实体与预约集合，不按姓名猜岗位或分类。 */
final class ManagementBoardProjection
{
    public static function project(array $entities, array $categories, array $appointments, array $input = []): array
    {
        $groups = ['leader'=>[], 'advisor'=>[], 'beauty'=>[]];
        $options = []; $unknown = 0;
        $selected = array_map('strval', (array)($input['boardEmployeeIds'] ?? []));
        foreach ($entities as $entity) {
            if (!$entity['employed'] && !$entity['_has_facts']) continue;
            $role = $entity['role_code'];
            if (!isset($groups[$role]) && $entity['_has_facts']) $unknown++;
            if (!isset($groups[$role]) || !empty($entity['placeholder'])) continue;
            if ($role === 'beauty') $options[] = ['key'=>$entity['id'],'label'=>$entity['name'],'store'=>$entity['store']];
            if ($role === 'beauty' && $selected && !in_array($entity['id'], $selected, true)) continue;
            $entity['_appointments'] = $appointments[$entity['id']]['booked'] ?? [];
            $entity['_arrivals'] = $appointments[$entity['id']]['arrived'] ?? [];
            $groups[$role][] = $entity;
        }
        usort($groups['beauty'], static fn(array $a, array $b): int => [$a['store_id'], $a['employee_id']] <=> [$b['store_id'], $b['employee_id']]);
        $columns = [['key'=>'leader','label'=>'店长'], ['key'=>'advisor','label'=>'顾问'], ['key'=>'total','label'=>'小计']];
        $roleMembers = ['leader'=>$groups['leader'], 'advisor'=>$groups['advisor'], 'total'=>array_merge($groups['leader'], $groups['advisor'])];
        $definitions = [];
        foreach ($categories as $category) $definitions[] = ['key'=>'category:'.$category['id'], 'label'=>$category['name'].'业绩', 'metric'=>'cash', 'category_id'=>(int)$category['id'], 'format'=>'money'];
        // 未映射到启用分类的金额保留为独立行，防止分类明细小于现金业绩而被静默漏掉。
        $unclassified = false;
        foreach (array_merge(...array_values($groups)) as $entity) {
            $classified = 0;
            foreach ($categories as $category) $classified += (int)($entity['category_values'][$category['id']]['cash_cents'] ?? 0);
            if ($classified !== (int)$entity['cash_cents']) $unclassified = true;
        }
        if ($unclassified) $definitions[] = ['key'=>'unclassified','label'=>'未归类业绩','metric'=>'unclassified','category_id'=>0,'format'=>'money'];
        $cash = ['key'=>'cash','label'=>'现金业绩合计','metric'=>'cash','category_id'=>0,'format'=>'money','emphasis'=>true];
        $consume = ['key'=>'consume','label'=>'消耗业绩','metric'=>'consume','category_id'=>0,'format'=>'money'];
        $roleRows = self::rows(array_merge($definitions, [$cash, $consume]), $roleMembers, $categories);
        $beautyColumns = []; $beautyMembers = [];
        foreach ($groups['beauty'] as $entity) {
            $beautyColumns[] = ['key'=>$entity['id'], 'label'=>$entity['name'], 'store'=>$entity['store']];
            $beautyMembers[$entity['id']] = [$entity];
        }
        $beautyColumns[] = ['key'=>'total','label'=>'合计']; $beautyMembers['total'] = $groups['beauty'];
        $serviceDefinitions = [];
        foreach (['booked'=>'预约顾客数','arrived'=>'预约到店服务顾客数','active'=>'活客数','visits'=>'售后人次','items'=>'项目数'] as $key=>$label) {
            $serviceDefinitions[] = ['key'=>$key,'label'=>$label,'metric'=>$key,'category_id'=>0,'format'=>'number'];
        }
        $beautyRows = self::rows(array_merge($serviceDefinitions, [$consume, $cash], $definitions), $beautyMembers, $categories);
        return ['employeeOptions'=>$options,'warnings'=>$unknown ? [$unknown.'条人员归属缺少可核实岗位，未猜测分入店长、顾问或美容师；原始业绩保留。'] : [],
            'roleTable'=>['title'=>'店长／顾问业绩','columns'=>$columns,'rows'=>$roleRows],
            'beautyTable'=>['title'=>'美容师经营明细','columns'=>$beautyColumns,'rows'=>$beautyRows],
            'table_layout'=>['fixed'=>true,'fixed_identity_width'=>160,'value_width'=>120],
            'metric_version'=>'management-board-human-efficiency-v1',
            'source_explanations'=>[
                '岗位按人效报表归属：店长读取岗位标记，顾问及副店长沿用人效岗位归属，美容师读取岗位或手艺人能力；保留期间已离职人员贡献。',
                '现金业绩、分类业绩、消耗业绩、售后人次和项目数复用人效报表；分类读取平台已开启统计的一级分类。金额按整元展示，导出保留原精度。',
                '预约顾客按所选预约日期内有效预约的排班美容师统计；取消、拒绝、待确认及未绑定顾客的预约不计入。',
                '预约到店只计已关联成功完成服务的顾客，归实际服务美容师；已作废服务不计入。预约日期决定统计范围。',
                '活客、预约顾客和预约到店顾客按顾客去重；合计重新去重，同一顾客由多人服务时合计可能小于各员工相加。',
                '消耗业绩展示全部有效分类的人员消耗分配，不把全部分类金额误写成生美金额。',
            ]];
    }

    /** 合计在后端按完整人员集合形成；顾客集合取并集，金额/服务份额才可加总。 */
    private static function rows(array $definitions, array $members, array $categories): array
    {
        $rows = [];
        foreach ($definitions as $definition) {
            $cells = []; $hasNonzeroAmount = false;
            foreach ($members as $key=>$people) {
                $value = 0; $seen = [];
                foreach ($people as $person) {
                    $metric = $definition['metric']; $category = $definition['category_id'];
                    if (in_array($metric, ['booked','arrived','active'], true)) {
                        $field = ['booked'=>'_appointments','arrived'=>'_arrivals','active'=>'_active'][$metric];
                        $seen += $person[$field] ?? [];
                    } elseif ($category > 0) $value += (int)($person['category_values'][$category]['cash_cents'] ?? 0);
                    elseif ($metric === 'unclassified') {
                        $value += (int)$person['cash_cents'];
                        foreach ($categories as $c) $value -= (int)($person['category_values'][$c['id']]['cash_cents'] ?? 0);
                    } else $value += $person[in_array($metric, ['cash','consume'], true) ? $metric.'_cents' : $metric] ?? 0;
                }
                if (in_array($definition['metric'], ['booked','arrived','active'], true)) $value = count($seen);
                $money = $definition['format'] === 'money';
                if ($money && $value != 0) $hasNonzeroAmount = true;
                $cells[] = ['key'=>$key,'value'=>$money ? \app\services\query\metric\MetricMoneyFormatter::exactYuan((int)$value) : $value,
                    'display'=>$money ? number_format((float)\app\services\query\metric\MetricMoneyFormatter::integerYuan((int)$value), 0, '.', ',') : (string)round($value, 6),
                    'drillable'=>count($people)>0 && $definition['metric']!=='unclassified',
                    'entity_ids'=>array_column($people, 'id')];
            }
            // 仅隐藏当前表所有列都为零的分类行；逐列核对原始分值，不能因正负抵消或整元展示为0而丢掉真实金额。
            // 合计、消耗和服务指标仍保留，两个表分别判断，不改变原事实及其统计范围。
            $categoryRow = $definition['category_id'] > 0 || $definition['metric'] === 'unclassified';
            if ($categoryRow && !$hasNonzeroAmount) continue;
            $rows[] = $definition + ['cells'=>$cells];
        }
        return $rows;
    }
}
