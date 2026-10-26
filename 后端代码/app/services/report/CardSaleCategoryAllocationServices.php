<?php

declare(strict_types=1);

namespace app\services\report;

/** Pure, deterministic cent allocation for one issued card receipt. */
final class CardSaleCategoryAllocationServices
{
    /**
     * @param array<int,array{stableKey:string,projectId:int,categoryIdSnapshot:int,categoryNameSnapshot:string,amountWeightCents:int}> $components
     * @return array<int,array>
     */
    public function allocate(int $amountCents, array $components): array
    {
        if ($amountCents < 0 || $components === []) {
            throw new \InvalidArgumentException('card_category_allocation_input_invalid');
        }
        $normalized = [];
        $weightTotal = 0;
        foreach ($components as $component) {
            $key = trim((string)($component['stableKey'] ?? ''));
            $projectId = (int)($component['projectId'] ?? 0);
            $categoryId = (int)($component['categoryIdSnapshot'] ?? 0);
            $categoryName = trim((string)($component['categoryNameSnapshot'] ?? ''));
            $weight = (int)($component['amountWeightCents'] ?? -1);
            if ($key === '' || $projectId <= 0 || $categoryId <= 0 || $categoryName === '' || $weight < 0) {
                throw new \InvalidArgumentException('card_category_allocation_component_invalid');
            }
            if (isset($normalized[$key])) {
                throw new \InvalidArgumentException('card_category_allocation_component_duplicate');
            }
            $normalized[$key] = compact('key', 'projectId', 'categoryId', 'categoryName', 'weight');
            $weightTotal += $weight;
        }
        if ($weightTotal <= 0) {
            throw new \InvalidArgumentException('card_category_allocation_weight_invalid');
        }
        ksort($normalized, SORT_STRING);
        $allocated = 0;
        $remainders = [];
        foreach ($normalized as $key => $component) {
            $product = bcmul((string)$amountCents, (string)$component['weight'], 0);
            $share = (int)bcdiv($product, (string)$weightTotal, 0);
            $normalized[$key]['allocatedAmountCents'] = $share;
            $remainders[] = ['key' => $key, 'remainder' => (int)bcmod($product, (string)$weightTotal)];
            $allocated += $share;
        }
        usort($remainders, static fn(array $a, array $b): int => $b['remainder'] <=> $a['remainder'] ?: strcmp($a['key'], $b['key']));
        for ($remaining = $amountCents - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $normalized[$remainders[$index]['key']]['allocatedAmountCents']++;
        }
        $count = count($normalized);
        $rows = [];
        foreach (array_values($normalized) as $index => $component) {
            $rows[] = [
                'stableKey' => $component['key'],
                'projectId' => $component['projectId'],
                'categoryIdSnapshot' => $component['categoryId'],
                'categoryNameSnapshot' => $component['categoryName'],
                'amountWeightCents' => $component['weight'],
                'allocatedAmountCents' => $component['allocatedAmountCents'],
                'allocationIndex' => $index + 1,
                'allocationCount' => $count,
            ];
        }
        return $rows;
    }
}
