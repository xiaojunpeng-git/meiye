<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

/** Aggregates completed-service and actual batch-consumption facts by item. */
final class InventoryItemMarginAnalyticsServices
{
    public function analyze(array $facts, bool $canViewCost): array
    {
        $items = [];
        foreach ($facts as $fact) {
            $fact = $this->normalize($fact);
            $key = $fact['project_id'];
            if (!isset($items[$key])) {
                $items[$key] = ['project_id' => $key, 'project_name' => $fact['project_name'], 'consumption_count' => 0, 'consumption_amount_cents' => 0, 'actual_cost_cents' => 0, 'estimated_shortage_cost_cents' => 0, 'cost_complete' => true];
            }
            $item = &$items[$key];
            $item['consumption_count'] += $fact['direction'];
            $item['consumption_amount_cents'] += $fact['direction'] * $fact['consumption_amount_cents'];
            $item['actual_cost_cents'] += $fact['direction'] * $fact['actual_cost_cents'];
            $item['estimated_shortage_cost_cents'] += $fact['direction'] * $fact['estimated_shortage_cost_cents'];
            $item['cost_complete'] = $item['cost_complete'] && $fact['cost_complete'];
            unset($item);
        }
        $result = [];
        foreach ($items as $item) {
            if ($item['consumption_count'] <= 0) continue;
            $grossProfitCents = $item['consumption_amount_cents'] - $item['actual_cost_cents'];
            $result[] = [
                'project_id' => $item['project_id'],
                'project_name' => $item['project_name'],
                'consumption_count' => $item['consumption_count'],
                'consumption_amount' => $this->amount($item['consumption_amount_cents']),
                'actual_consumable_cost' => $canViewCost ? $this->amount($item['actual_cost_cents']) : null,
                'estimated_shortage_cost' => $canViewCost ? $this->amount($item['estimated_shortage_cost_cents']) : null,
                'cost_complete' => $item['cost_complete'],
                'gross_profit' => $canViewCost ? $this->amount($grossProfitCents) : null,
                'single_gross_profit' => $canViewCost ? $this->amount(intdiv($grossProfitCents, $item['consumption_count'])) : null,
                'gross_margin' => $canViewCost && $item['consumption_amount_cents'] !== 0 && $item['cost_complete']
                    ? round($grossProfitCents * 10000 / $item['consumption_amount_cents']) / 100 : null,
            ];
        }
        usort($result, static function (array $left, array $right): int { return strcmp($right['consumption_amount'], $left['consumption_amount']); });
        return $result;
    }

    private function normalize(array $fact): array
    {
        $keys = ['project_id', 'project_name', 'direction', 'consumption_amount_cents', 'actual_cost_cents', 'estimated_shortage_cost_cents', 'cost_complete'];
        if (array_keys($fact) !== $keys || !is_int($fact['project_id']) || $fact['project_id'] <= 0 || !is_string($fact['project_name']) || trim($fact['project_name']) === '' || !in_array($fact['direction'], [1, -1], true) || !is_bool($fact['cost_complete'])) throw new \InvalidArgumentException('inventory_margin_fact_invalid');
        foreach (['consumption_amount_cents', 'actual_cost_cents', 'estimated_shortage_cost_cents'] as $key) if (!is_int($fact[$key]) || $fact[$key] < 0) throw new \InvalidArgumentException('inventory_margin_fact_invalid');
        return $fact;
    }

    private function amount(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
