<?php

declare(strict_types=1);
namespace app\services\report;

/** 门店目标与经理按日分摊的纯投影；调用方明确提供历史或当前归属日历，不能混用。 */
final class HumanEfficiencyTargetProjector
{
    /**
     * $dailyManagers[store_id][Y-m-d] 来自调用方已确定的归属口径；人效看板现使用当前组织映射。
     * 同一门店同一自然日只允许一个经理，未知日历不参与任意经理的完成率。
     * 月目标先按完整自然月分摊，查询部分月份不会把整月目标重复压到所选几天。
     */
    public function project(array $lines, array $range, array $dailyManagers): array
    {
        $stores = []; $managers = []; $unknown = []; $seen = [];
        foreach ($lines as $line) {
            $store = (int)$line['store_id']; $month = (string)$line['month_key'];
            $key = $store . ':' . $month;
            if (isset($seen[$key])) throw new \InvalidArgumentException('同门店同月存在重复现金目标绑定');
            $seen[$key] = true;
            $target = (int)$line['target_value'];
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
            if (!$day || $day->format('Y-m') !== $month || $target < 0) throw new \InvalidArgumentException('门店目标数据不正确');
            $calendar = [];
            while ($day->format('Y-m') === $month) {
                $date = $day->format('Y-m-d');
                // 按日稳定分摊，之后再归集到经理，保证任意时间切片可加总。
                $calendar[$date] = 1;
                $day = $day->modify('+1 day');
            }
            $daily = HumanEfficiencyRules::allocateTarget($target, $calendar);
            foreach ($daily as $date => $amount) {
                if ($date < $range['start'] || $date > $range['end']) continue;
                $stores[$store] = ($stores[$store] ?? 0) + $amount;
                $manager = $dailyManagers[$store][$date] ?? null;
                if ($manager === null || $manager === '') {
                    $unknown[$store]['days'][] = $date;
                    $unknown[$store]['target_cents'] = ($unknown[$store]['target_cents'] ?? 0) + $amount;
                    continue;
                }
                $managers[(string)$manager] = ($managers[(string)$manager] ?? 0) + $amount;
            }
        }
        // 任一已核实管辖门店缺少当月目标时，不能拿其余门店的部分目标计算完成率。
        $missingTargets=[];
        foreach($dailyManagers as $store=>$dates)foreach($dates as $date=>$manager){
            if($date>=$range['start'] && $date<=$range['end'] && !isset($seen[$store.':'.substr($date,0,7)]))$missingTargets[(string)$manager]=true;
        }
        return [
            'missing_targets'=>$missingTargets,
            'stores' => $stores,
            'managers' => $managers,
            'unknown' => $unknown,
            // 未知日的所属经理也未知，因此不能认定其他经理的分母已完整。
            // 保留已核实的目标分片供对账，但所有完成率消费者必须检查此标志。
            'history_complete' => $unknown === [],
            'history_status' => $unknown === [] ? 'complete' : 'pending_history',
        ];
    }

    /**
     * 未知归属不能计算完整完成率；当前归属模式由调用方提供完整日历。
     * 已知部分目标不能作为完整分母；显式零目标与未配置目标仍保留不同状态。
     */
    public function managerCompletion(int $cashCents, string $managerId, array $projection): array
    {
        if (($projection['history_complete'] ?? false) !== true) {
            return ['target_cents' => null, 'completion' => null, 'status' => 'pending_history',
                'message' => '待补齐历史管辖资料'];
        }
        $target = isset($projection['missing_targets'][$managerId]) ? null : ($projection['managers'][$managerId] ?? null);
        if ($target === null) {
            return ['target_cents' => null, 'completion' => null, 'status' => 'missing_target',
                'message' => '未配置门店现金业绩目标'];
        }
        if ($target === 0) {
            return ['target_cents' => 0, 'completion' => null, 'status' => 'zero_target',
                'message' => '门店目标为0，不计算完成率'];
        }
        return ['target_cents' => $target, 'completion' => HumanEfficiencyRules::ratio($cashCents, $target),
            'status' => 'ready', 'message' => '按查询天数分摊当前管辖门店目标'];
    }

}
