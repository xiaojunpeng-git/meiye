<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use think\facade\Db;

/**
 * Immutable project-level allocations for sold cards.
 *
 * The legacy category projection remains unchanged. This projection preserves
 * every contained project's name, configured count and allocated sale/cash so
 * Phase 3 reports can group by the actual project rather than the card shell.
 */
final class CardSaleItemAllocationFactServices
{
    public const TABLE = 'cashier_v3_card_sale_item_allocation_fact';
    public const CONTRACT_VERSION = 'cashier-v3-card-sale-item-allocation-v1';

    /** @return array{inserted:int,replayed:int} */
    public function persistInTx(CashierV3CheckoutFactPlanV1 $plan, array $cardPurchaseResult): array
    {
        CashierV3TransactionGuard::assertInTransaction('cardSaleItemAllocation.persistInTx');
        $receipts = array_values((array)($cardPurchaseResult['receipts'] ?? []));
        if ($receipts === []) {
            return ['inserted' => 0, 'replayed' => 0];
        }

        $context = $plan->context();
        $sales = [];
        foreach ((array)($plan->rows()['sale'] ?? []) as $sale) {
            if ((string)($sale['source_type'] ?? '') !== 'card') {
                continue;
            }
            $sourceLineId = trim((string)($sale['source_line_id'] ?? ''));
            if ($sourceLineId === '' || (int)($sale['sale_amount_cents'] ?? -1) < 0) {
                throw new \LogicException('card_item_sale_fact_invalid');
            }
            $sales[$sourceLineId] = $sale;
        }
        if ($sales === []) {
            throw new \LogicException('card_item_sale_fact_missing');
        }

        $cashBySaleFact = StoreReportPartnerCategorySnapshotServices::cashPerformanceBySaleFact($plan);
        $receiptsByLine = [];
        $receiptLines = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                throw new \LogicException('card_item_receipt_invalid');
            }
            $lineId = trim((string)($receipt['salesOrderLineId'] ?? ''));
            $receiptId = trim((string)($receipt['receiptId'] ?? ''));
            $issueNo = (int)($receipt['issueNo'] ?? 0);
            $components = $receipt['reportCategoryComponents'] ?? null;
            if (!isset($sales[$lineId]) || $receiptId === '' || $issueNo <= 0 || !is_array($components)) {
                throw new \LogicException('card_item_receipt_snapshot_invalid');
            }
            $receiptLines[$lineId] = true;
            if ($components !== []) {
                $receiptsByLine[$lineId][] = $receipt;
            }
        }
        if (count($receiptLines) !== count($sales)) {
            throw new \LogicException('card_item_receipt_line_missing');
        }

        $inserted = 0;
        $replayed = 0;
        foreach ($receiptsByLine as $lineId => $lineReceipts) {
            usort($lineReceipts, static fn(array $a, array $b): int => (int)$a['issueNo'] <=> (int)$b['issueNo']);
            $receiptWeights = [];
            foreach ($lineReceipts as $receipt) {
                $receiptWeights[(string)$receipt['receiptId']] = ['amount_cents' => 1];
            }
            $saleByReceipt = $this->allocateByAmounts((int)$sales[$lineId]['sale_amount_cents'], $receiptWeights, 'amount_cents');
            $cashByReceipt = $this->allocateByAmounts(
                (int)($cashBySaleFact[(string)$sales[$lineId]['fact_id']] ?? 0),
                $receiptWeights,
                'amount_cents'
            );
            foreach ($lineReceipts as $receipt) {
                $items = $this->itemsFromReceipt((array)$receipt['reportCategoryComponents']);
                $saleByItem = $this->allocateByAmounts(
                    (int)$saleByReceipt[(string)$receipt['receiptId']],
                    $items,
                    'amountWeightCents'
                );
                $cashByItem = $this->allocateByAmounts(
                    (int)$cashByReceipt[(string)$receipt['receiptId']],
                    $items,
                    'amountWeightCents'
                );
                foreach ($items as $stableKey => $item) {
                    $this->persistItem(
                        $context,
                        (array)$sales[$lineId],
                        $receipt,
                        $item,
                        (int)$saleByItem[$stableKey],
                        (int)$cashByItem[$stableKey],
                        $inserted,
                        $replayed
                    );
                }
            }
        }
        return ['inserted' => $inserted, 'replayed' => $replayed];
    }

    /** @return array<string,array<string,mixed>> */
    private function itemsFromReceipt(array $components): array
    {
        $items = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                throw new \LogicException('card_item_component_invalid');
            }
            $productId = (int)($component['productId'] ?? 0);
            $itemName = trim((string)($component['projectNameSnapshot'] ?? ''));
            $componentCount = (int)($component['componentCount'] ?? -1);
            $categoryId = (int)($component['categoryIdSnapshot'] ?? 0);
            $categoryName = trim((string)($component['categoryNameSnapshot'] ?? ''));
            $weight = (int)($component['allocationWeightCents'] ?? -1);
            if ($productId <= 0 || $itemName === '' || $componentCount < 0
                || $categoryId <= 0 || $categoryName === '' || $weight < 0) {
                throw new \LogicException('card_item_component_snapshot_invalid');
            }
            $stableKey = 'project:' . $productId;
            if (!isset($items[$stableKey])) {
                $items[$stableKey] = [
                    'stableKey' => $stableKey,
                    'productId' => $productId,
                    'itemNameSnapshot' => $itemName,
                    'componentCount' => 0,
                    'categoryIdSnapshot' => $categoryId,
                    'categoryNameSnapshot' => $categoryName,
                    'amountWeightCents' => 0,
                ];
            } elseif ((string)$items[$stableKey]['itemNameSnapshot'] !== $itemName
                || (int)$items[$stableKey]['categoryIdSnapshot'] !== $categoryId
                || (string)$items[$stableKey]['categoryNameSnapshot'] !== $categoryName) {
                throw new \LogicException('card_item_component_snapshot_conflict');
            }
            $items[$stableKey]['componentCount'] += $componentCount;
            $items[$stableKey]['amountWeightCents'] += $weight;
        }
        if ($items === [] || array_sum(array_column($items, 'amountWeightCents')) <= 0) {
            throw new \LogicException('card_item_component_weight_invalid');
        }
        ksort($items, SORT_STRING);
        return $items;
    }

    private function persistItem(
        array $context,
        array $sale,
        array $receipt,
        array $item,
        int $saleAmount,
        int $cashAmount,
        int &$inserted,
        int &$replayed
    ): void {
        $categoryId = (int)$item['categoryIdSnapshot'];
        $category = Db::name('store_product_category')->where('id', $categoryId)->lock(true)->find();
        $categoryPath = $category ? $this->categoryPath($category) : trim((string)$item['categoryNameSnapshot']);
        if ($categoryPath === '') {
            throw new \LogicException('card_item_category_path_invalid');
        }
        $planKey = trim((string)($sale['command_idempotency_key'] ?? ''));
        if ($planKey === '') {
            throw new \LogicException('card_item_command_key_missing');
        }
        $naturalKey = 'card-sale-item:' . (string)$sale['fact_id'] . ':'
            . (int)$receipt['issueNo'] . ':' . (int)$item['productId'];
        $row = [
            'allocation_fact_id' => 'CSIA-' . strtoupper(substr(hash('sha256', $naturalKey), 0, 40)),
            'natural_key' => $naturalKey,
            'contract_version' => self::CONTRACT_VERSION,
            'fact_version' => 1,
            'status' => 'effective',
            'reversal_of' => '',
            'tenant_id' => (string)$context['tenant_id'],
            'organization_id' => (string)$context['organization_id'],
            'store_id' => (int)$context['store_id'],
            'member_id' => (int)$context['member_id'],
            'order_id' => (string)$context['order_id'],
            'sale_fact_id' => (string)$sale['fact_id'],
            'source_line_id' => (string)$sale['source_line_id'],
            'card_receipt_id' => (string)$receipt['receiptId'],
            'card_issue_no' => (int)$receipt['issueNo'],
            'component_product_id' => (int)$item['productId'],
            'item_name_snapshot' => (string)$item['itemNameSnapshot'],
            'component_count' => (int)$item['componentCount'],
            'category_id_snapshot' => $categoryId,
            'category_name_snapshot' => (string)$item['categoryNameSnapshot'],
            'category_path_snapshot' => $categoryPath,
            'configured_amount_cents' => (int)$item['amountWeightCents'],
            'sale_amount_cents' => $saleAmount,
            'cash_performance_amount_cents' => $cashAmount,
            'business_date' => (string)$context['business_date'],
            'occurred_at' => (int)$context['occurred_at'],
            'settled_at' => (int)$context['settled_at'],
            'recorded_at' => (int)$context['recorded_at'],
            'business_event_no' => (string)$context['business_event_no'],
            'command_idempotency_key' => $planKey,
        ];
        $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = Db::name(self::TABLE)
            ->where('tenant_id', $row['tenant_id'])->where('natural_key', $naturalKey)->lock(true)->find();
        if ($existing) {
            $this->assertReplay($row, $existing);
            $replayed++;
            return;
        }
        $row['add_time'] = (int)$context['recorded_at'];
        $row['update_time'] = (int)$context['recorded_at'];
        try {
            if ((int)Db::name(self::TABLE)->insert($row) !== 1) {
                throw new \LogicException('card_item_allocation_insert_failed');
            }
            $inserted++;
        } catch (\Throwable $exception) {
            if (strpos(strtolower($exception->getMessage()), 'duplicate') === false
                && strpos($exception->getMessage(), '1062') === false) {
                throw $exception;
            }
            $raced = Db::name(self::TABLE)
                ->where('tenant_id', $row['tenant_id'])->where('natural_key', $naturalKey)->lock(true)->find();
            if (!$raced) {
                throw new \LogicException('card_item_allocation_race_conflict', 0, $exception);
            }
            $this->assertReplay($row, $raced);
            $replayed++;
        }
    }

    private function assertReplay(array $expected, array $actual): void
    {
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $actual) || (string)$actual[$column] !== (string)$value) {
                throw new \LogicException('card_item_allocation_replay_conflict');
            }
        }
    }

    /** @return array<string,int> */
    private function allocateByAmounts(int $amount, array $rows, string $amountField): array
    {
        if ($amount < 0 || $rows === []) {
            throw new \LogicException('card_item_allocation_input_invalid');
        }
        $total = 0;
        foreach ($rows as $row) {
            $weight = (int)($row[$amountField] ?? -1);
            if ($weight < 0) {
                throw new \LogicException('card_item_allocation_weight_invalid');
            }
            $total += $weight;
        }
        if ($amount === 0) {
            return array_fill_keys(array_keys($rows), 0);
        }
        if ($total <= 0) {
            throw new \LogicException('card_item_allocation_total_invalid');
        }
        $result = [];
        $remainders = [];
        $allocated = 0;
        foreach ($rows as $key => $row) {
            $product = bcmul((string)$amount, (string)(int)$row[$amountField], 0);
            $share = (int)bcdiv($product, (string)$total, 0);
            $result[(string)$key] = $share;
            $remainders[] = ['key' => (string)$key, 'remainder' => (int)bcmod($product, (string)$total)];
            $allocated += $share;
        }
        usort($remainders, static fn(array $a, array $b): int => $b['remainder'] <=> $a['remainder'] ?: strcmp($a['key'], $b['key']));
        for ($remaining = $amount - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $result[$remainders[$index]['key']]++;
        }
        return $result;
    }

    private function categoryPath(array $category): string
    {
        $parts = [trim((string)($category['cate_name'] ?? ''))];
        $parentId = (int)($category['pid'] ?? 0);
        for ($guard = 0; $parentId > 0 && $guard < 8; $guard++) {
            $parent = Db::name('store_product_category')->where('id', $parentId)
                ->lock(true)->field('id,pid,cate_name')->find();
            if (!$parent) {
                break;
            }
            array_unshift($parts, trim((string)$parent['cate_name']));
            $parentId = (int)$parent['pid'];
        }
        return implode(' / ', array_filter($parts));
    }
}
