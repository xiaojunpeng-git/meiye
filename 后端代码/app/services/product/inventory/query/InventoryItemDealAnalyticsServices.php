<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

/**
 * Aggregates item conversion from effective service and formal-sale facts.
 *
 * Callers must inject DataScope and the reporting date range before this
 * service. A reversal uses the same logical fact id as the fact it reverses;
 * the net-zero pair is excluded instead of mutating historical facts.
 */
final class InventoryItemDealAnalyticsServices
{
    public function analyze(array $serviceFacts, array $saleFacts, int $attributionWindowDays): array
    {
        if ($attributionWindowDays < 0 || $attributionWindowDays > 3650) {
            throw new \InvalidArgumentException('inventory_deal_attribution_window_invalid');
        }

        $experiences = $this->effectiveFacts($serviceFacts, 'service');
        $sales = $this->effectiveFacts($saleFacts, 'sale');
        $items = [];
        foreach ($experiences as $fact) {
            if (!$fact['experience_tag_snapshot']) {
                continue;
            }
            $projectId = $fact['project_id'];
            if (!isset($items[$projectId])) {
                $items[$projectId] = $this->emptyItem($fact);
            }
            $memberId = $fact['member_id'];
            $occurredAt = $this->time($fact['occurred_at']);
            if (!isset($items[$projectId]['experience_members'][$memberId])
                || $occurredAt < $items[$projectId]['experience_members'][$memberId]) {
                $items[$projectId]['experience_members'][$memberId] = $occurredAt;
            }
        }

        foreach ($sales as $fact) {
            $projectId = $fact['project_id'];
            if (!isset($items[$projectId])) {
                continue;
            }
            $memberId = $fact['member_id'];
            if (!isset($items[$projectId]['experience_members'][$memberId])) {
                continue;
            }
            if ($items[$projectId]['quantity_scale'] !== $fact['quantity_scale']) {
                throw new \InvalidArgumentException('inventory_deal_quantity_scale_mismatch');
            }
            if (!$this->isAttributed(
                $items[$projectId]['experience_members'][$memberId],
                $this->time($fact['occurred_at']),
                $attributionWindowDays
            )) {
                continue;
            }
            $items[$projectId]['deal_members'][$memberId] = true;
            $items[$projectId]['purchase_count']++;
            $items[$projectId]['purchase_quantity_units'] += $fact['quantity_units'];
            $items[$projectId]['purchase_amount_cents'] += $fact['sale_amount_cents'];
        }

        $rows = [];
        foreach ($items as $item) {
            $experienceCount = count($item['experience_members']);
            $dealCount = count($item['deal_members']);
            $rows[] = [
                'project_id' => $item['project_id'],
                'project_name' => $item['project_name'],
                'experience_member_count' => $experienceCount,
                'deal_member_count' => $dealCount,
                'conversion_rate_percent' => $experienceCount === 0 ? null : round($dealCount * 10000 / $experienceCount) / 100,
                'purchase_count' => $item['purchase_count'],
                'purchase_quantity' => $this->quantity($item['purchase_quantity_units'], $item['quantity_scale']),
                'purchase_amount' => $this->amount($item['purchase_amount_cents']),
                'average_selling_price' => $item['purchase_quantity_units'] === 0
                    ? null
                    : $this->amount(intdiv($item['purchase_amount_cents'] * (10 ** $item['quantity_scale']), $item['purchase_quantity_units'])),
                'average_unit_output' => $dealCount === 0
                    ? null
                    : $this->amount(intdiv($item['purchase_amount_cents'], $dealCount)),
                '_purchase_amount_cents' => $item['purchase_amount_cents'],
            ];
        }
        usort($rows, static function (array $left, array $right): int {
            $amount = $right['_purchase_amount_cents'] <=> $left['_purchase_amount_cents'];
            return $amount !== 0 ? $amount : $left['project_id'] <=> $right['project_id'];
        });
        foreach ($rows as &$row) {
            unset($row['_purchase_amount_cents']);
        }
        unset($row);
        return $rows;
    }

    private function emptyItem(array $fact): array
    {
        return [
            'project_id' => $fact['project_id'],
            'project_name' => $fact['project_name'],
            'quantity_scale' => $fact['quantity_scale'],
            'experience_members' => [],
            'deal_members' => [],
            'purchase_count' => 0,
            'purchase_quantity_units' => 0,
            'purchase_amount_cents' => 0,
        ];
    }

    private function effectiveFacts(array $facts, string $kind): array
    {
        $ledger = [];
        foreach ($facts as $fact) {
            $fact = $this->normalize($fact, $kind);
            $idKey = $kind . '_fact_id';
            $id = $fact[$idKey];
            if (!isset($ledger[$id])) {
                $ledger[$id] = ['fact' => $fact, 'direction' => 0];
            } elseif ($this->withoutDirection($ledger[$id]['fact']) !== $this->withoutDirection($fact)) {
                throw new \InvalidArgumentException('inventory_deal_reversal_fact_mismatch');
            }
            $ledger[$id]['direction'] += $fact['direction'];
        }
        $effective = [];
        foreach ($ledger as $entry) {
            if (!in_array($entry['direction'], [0, 1], true)) {
                throw new \InvalidArgumentException('inventory_deal_fact_direction_invalid');
            }
            if ($entry['direction'] === 1) {
                $effective[] = $entry['fact'];
            }
        }
        return $effective;
    }

    private function normalize(array $fact, string $kind): array
    {
        $idKey = $kind . '_fact_id';
        $keys = [$idKey, 'project_id', 'project_name', 'member_id', 'occurred_at', 'direction', 'quantity_units', 'quantity_scale'];
        if ($kind === 'service') {
            $keys[] = 'experience_tag_snapshot';
        } else {
            $keys[] = 'sale_amount_cents';
        }
        if (array_keys($fact) !== $keys
            || !is_string($fact[$idKey]) || trim($fact[$idKey]) === ''
            || !is_int($fact['project_id']) || $fact['project_id'] <= 0
            || !is_string($fact['project_name']) || trim($fact['project_name']) === ''
            || !is_int($fact['member_id']) || $fact['member_id'] <= 0
            || !in_array($fact['direction'], [1, -1], true)
            || !is_int($fact['quantity_units']) || $fact['quantity_units'] < 0
            || !is_int($fact['quantity_scale']) || $fact['quantity_scale'] < 0 || $fact['quantity_scale'] > 4
        ) {
            throw new \InvalidArgumentException('inventory_deal_fact_invalid');
        }
        $this->time($fact['occurred_at']);
        if ($kind === 'service' && !is_bool($fact['experience_tag_snapshot'])) {
            throw new \InvalidArgumentException('inventory_deal_fact_invalid');
        }
        if ($kind === 'sale' && (!is_int($fact['sale_amount_cents']) || $fact['sale_amount_cents'] < 0)) {
            throw new \InvalidArgumentException('inventory_deal_fact_invalid');
        }
        return $fact;
    }

    private function withoutDirection(array $fact): array
    {
        unset($fact['direction']);
        return $fact;
    }

    private function isAttributed(\DateTimeImmutable $experienceAt, \DateTimeImmutable $saleAt, int $windowDays): bool
    {
        return $saleAt >= $experienceAt && $saleAt <= $experienceAt->modify('+' . $windowDays . ' days');
    }

    private function time($value): \DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            throw new \InvalidArgumentException('inventory_deal_fact_time_invalid');
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('inventory_deal_fact_time_invalid');
        }
        return $time;
    }

    private function quantity(int $units, int $scale): string
    {
        if ($scale === 0) {
            return (string)$units;
        }
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        $divisor = 10 ** $scale;
        return $sign . intdiv($units, $divisor) . '.' . str_pad((string)($units % $divisor), $scale, '0', STR_PAD_LEFT);
    }

    private function amount(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
