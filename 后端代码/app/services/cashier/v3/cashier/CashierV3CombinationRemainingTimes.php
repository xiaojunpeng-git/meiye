<?php

namespace app\services\cashier\v3\cashier;

/**
 * 组合卡父行当前最多还能使用的次数。仅用于展示；核销仍由逐组权威状态原子扣减。
 * 任选种数组计入已选项目余次，以及剩余选择名额内余次最多的候选项目；
 * 共享次数组只计一次组余额，不能把每个候选项目各算一份。
 */
final class CashierV3CombinationRemainingTimes
{
    public static function fromAuthorities(array $authorities): int
    {
        $groups = [];
        foreach ($authorities as $authority) {
            if (!is_array($authority) || ($authority['cardRuleType'] ?? '') !== 'combination') continue;
            $no = (int)($authority['combinationGroupNo'] ?? 0);
            if ($no <= 0) continue;
            $rule = (string)($authority['ruleType'] ?? '');
            $remaining = max(0, (int)($authority['remainingTimes'] ?? 0));
            if ($rule === 'choice_count') {
                // 同组每个项目引用同一物理池，重复读取只保留一份。
                $groups[$no] = ['rule' => $rule, 'remaining' => $remaining];
                continue;
            }
            if ($rule !== 'choice_kind') continue;
            if (!isset($groups[$no])) {
                $groups[$no] = [
                    'rule' => $rule,
                    'limit' => max(0, (int)($authority['combinationChoiceLimit'] ?? 0)),
                    'selectedCount' => max(0, (int)($authority['combinationSelectedKindCount'] ?? 0)),
                    'selectedRemaining' => 0,
                    'candidates' => [],
                ];
            }
            if (($authority['selectionStatus'] ?? '') === 'selected') {
                $groups[$no]['selectedRemaining'] += $remaining;
            } elseif (($authority['selectionStatus'] ?? '') === 'candidate') {
                $groups[$no]['candidates'][] = $remaining;
            }
        }
        $total = 0;
        foreach ($groups as $group) {
            if ($group['rule'] === 'choice_count') {
                $total += $group['remaining'];
                continue;
            }
            rsort($group['candidates'], SORT_NUMERIC);
            $slots = max(0, $group['limit'] - $group['selectedCount']);
            $total += $group['selectedRemaining']
                + array_sum(array_slice($group['candidates'], 0, $slots));
        }
        return $total;
    }
}
