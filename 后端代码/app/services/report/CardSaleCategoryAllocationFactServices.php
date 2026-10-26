<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use think\facade\Db;

/**
 * Immutable category allocations for a sold card.
 *
 * The outer card is still one sale line. This fact only projects its sale and
 * cash-performance amounts to the projects configured inside that card. It is
 * written from the issued-card receipt in the successful checkout transaction,
 * never from the mutable catalog during report querying.
 */
final class CardSaleCategoryAllocationFactServices
{
    public const TABLE = 'cashier_v3_card_sale_category_allocation_fact';
    public const CONTRACT_VERSION = 'cashier-v3-card-sale-category-allocation-v1';

    /** @return array{inserted:int,replayed:int} */
    public function persistInTx(CashierV3CheckoutFactPlanV1 $plan, array $cardPurchaseResult): array
    {
        CashierV3TransactionGuard::assertInTransaction('cardSaleCategoryAllocation.persistInTx');
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
            $amount = (int)($sale['sale_amount_cents'] ?? -1);
            if ($sourceLineId === '' || $amount < 0) {
                throw new \LogicException('card_category_sale_fact_invalid');
            }
            $sales[$sourceLineId] = $sale;
        }
        if ($sales === []) {
            throw new \LogicException('card_category_sale_fact_missing');
        }

        // Cash performance is first allocated across every sale line in the
        // order. A mixed card/project checkout must not allocate all cash to
        // its card rows before card components are split further.
        $cashBySaleFact = StoreReportPartnerCategorySnapshotServices::cashPerformanceBySaleFact($plan);
        $partnerSnapshots = new StoreReportPartnerCategorySnapshotServices();

        $receiptsByLine = [];
        $receiptLines = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                throw new \LogicException('card_category_receipt_invalid');
            }
            $lineId = trim((string)($receipt['salesOrderLineId'] ?? ''));
            $receiptId = trim((string)($receipt['receiptId'] ?? ''));
            $issueNo = (int)($receipt['issueNo'] ?? 0);
            if (!isset($sales[$lineId]) || $receiptId === '' || $issueNo <= 0
                || !is_array($receipt['reportCategoryComponents'] ?? null)) {
                throw new \LogicException('card_category_receipt_snapshot_invalid');
            }
            $receiptLines[$lineId] = true;
            // Count/time cards may have no contained service project. They do
            // not have a project category to project, but must remain saleable.
            if ($receipt['reportCategoryComponents'] === []) {
                continue;
            }
            $receiptsByLine[$lineId][] = $receipt;
        }
        if (count($receiptLines) !== count($sales)) {
            throw new \LogicException('card_category_receipt_line_missing');
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
                $categories = $this->categoriesFromReceipt((array)$receipt['reportCategoryComponents']);
                $saleAllocations = (new CardSaleCategoryAllocationServices())->allocate(
                    (int)$saleByReceipt[(string)$receipt['receiptId']], $categories
                );
                $cashAllocations = (new CardSaleCategoryAllocationServices())->allocate(
                    (int)$cashByReceipt[(string)$receipt['receiptId']], $categories
                );
                $cashByCategory = [];
                foreach ($cashAllocations as $allocation) {
                    $cashByCategory[(string)$allocation['stableKey']] = (int)$allocation['allocatedAmountCents'];
                }
                foreach ($saleAllocations as $allocation) {
                    $this->persistAllocation(
                        $context,
                        (array)$sales[$lineId],
                        $receipt,
                        $allocation,
                        (int)($cashByCategory[(string)$allocation['stableKey']] ?? 0),
                        $partnerSnapshots,
                        $inserted,
                        $replayed
                    );
                }
            }
        }
        return ['inserted' => $inserted, 'replayed' => $replayed];
    }

    /** @return array<int,array{stableKey:string,projectId:int,categoryIdSnapshot:int,categoryNameSnapshot:string,amountWeightCents:int}> */
    private function categoriesFromReceipt(array $components): array
    {
        $grouped = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                throw new \LogicException('card_category_component_invalid');
            }
            $projectId = (int)($component['productId'] ?? 0);
            $categoryId = (int)($component['categoryIdSnapshot'] ?? 0);
            $name = trim((string)($component['categoryNameSnapshot'] ?? ''));
            $weight = (int)($component['allocationWeightCents'] ?? -1);
            if ($projectId <= 0 || $categoryId <= 0 || $name === '' || $weight < 0) {
                throw new \LogicException('card_category_component_snapshot_invalid');
            }
            $key = (string)$categoryId;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'stableKey' => 'category:' . $categoryId,
                    'projectId' => $projectId,
                    'categoryIdSnapshot' => $categoryId,
                    'categoryNameSnapshot' => $name,
                    'amountWeightCents' => 0,
                ];
            }
            $grouped[$key]['amountWeightCents'] += $weight;
        }
        if ($grouped === []) {
            throw new \LogicException('card_category_components_empty');
        }
        $total = array_sum(array_column($grouped, 'amountWeightCents'));
        if ($total <= 0) {
            throw new \LogicException('card_category_component_weight_invalid');
        }
        return array_values($grouped);
    }

    private function persistAllocation(
        array $context,
        array $sale,
        array $receipt,
        array $allocation,
        int $cashPerformanceAmount,
        StoreReportPartnerCategorySnapshotServices $partnerSnapshots,
        int &$inserted,
        int &$replayed
    ): void {
        $categoryId = (int)$allocation['categoryIdSnapshot'];
        $category = Db::name('store_product_category')->where('id', $categoryId)->lock(true)->find();
        // The receipt already carries the category name captured when the card
        // was issued. A later catalog deletion must not make a valid sale fail
        // after the card has been issued. Keep that immutable label as the
        // historical path and only resolve partner configuration when the
        // current category still exists.
        $path = $category
            ? $this->categoryPath($category)
            : trim((string)$allocation['categoryNameSnapshot']);
        if ($path === '') {
            throw new \LogicException('card_category_path_invalid');
        }
        $partnerSnapshot = $category
            ? $partnerSnapshots->resolveInTx(
                (string)$context['tenant_id'],
                $categoryId,
                $cashPerformanceAmount
            )
            : $this->emptyPartnerSnapshot();
        $receiptId = (string)$receipt['receiptId'];
        $planKey = trim((string)($sale['command_idempotency_key'] ?? ''));
        if ($planKey === '') {
            throw new \LogicException('card_category_command_key_missing');
        }
        $naturalKey = 'card-sale-category:' . (string)$sale['fact_id'] . ':'
            . (int)$receipt['issueNo'] . ':' . $categoryId;
        $row = [
            'allocation_fact_id' => 'CSCA-' . strtoupper(substr(hash('sha256', $naturalKey), 0, 40)),
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
            'card_receipt_id' => $receiptId,
            'card_issue_no' => (int)$receipt['issueNo'],
            'component_product_id' => (int)$allocation['projectId'],
            'category_id_snapshot' => $categoryId,
            'category_name_snapshot' => (string)$allocation['categoryNameSnapshot'],
            'category_path_snapshot' => $path,
            'partner_name_snapshot' => (string)$partnerSnapshot['partner_category_path_snapshot'],
            'partner_category_id_snapshot' => (int)$partnerSnapshot['partner_category_id_snapshot'],
            'partner_category_name_snapshot' => (string)$partnerSnapshot['partner_category_name_snapshot'],
            'partner_category_path_snapshot' => (string)$partnerSnapshot['partner_category_path_snapshot'],
            'partner_default_ratio_snapshot' => (int)$partnerSnapshot['partner_default_ratio_snapshot'],
            'partner_config_version_snapshot' => (int)$partnerSnapshot['partner_config_version_snapshot'],
            'partner_share_amount_cents' => (int)$partnerSnapshot['partner_share_amount_cents'],
            'product_type_snapshot' => 'project',
            'component_count' => (int)$allocation['allocationCount'],
            'configured_amount_cents' => (int)$allocation['amountWeightCents'],
            'sale_amount_cents' => (int)$allocation['allocatedAmountCents'],
            'cash_performance_amount_cents' => $cashPerformanceAmount,
            'business_date' => (string)$context['business_date'],
            'occurred_at' => (int)$context['occurred_at'],
            'settled_at' => (int)$context['settled_at'],
            'recorded_at' => (int)$context['recorded_at'],
            'business_event_no' => (string)$context['business_event_no'],
            'command_idempotency_key' => $planKey,
        ];
        $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = Db::name(self::TABLE)->where('tenant_id', $row['tenant_id'])->where('natural_key', $naturalKey)->lock(true)->find();
        if ($existing) {
            $this->assertReplay($row, $existing);
            $replayed++;
            return;
        }
        $row['add_time'] = (int)$context['recorded_at'];
        $row['update_time'] = (int)$context['recorded_at'];
        try {
            if ((int)Db::name(self::TABLE)->insert($row) !== 1) {
                throw new \LogicException('card_category_allocation_insert_failed');
            }
            $inserted++;
        } catch (\Throwable $exception) {
            if (strpos(strtolower($exception->getMessage()), 'duplicate') === false
                && strpos($exception->getMessage(), '1062') === false) {
                throw $exception;
            }
            $raced = Db::name(self::TABLE)
                ->where('tenant_id', $row['tenant_id'])
                ->where('natural_key', $naturalKey)
                ->lock(true)
                ->find();
            if (!$raced) {
                throw new \LogicException('card_category_allocation_race_conflict', 0, $exception);
            }
            $this->assertReplay($row, $raced);
            $replayed++;
        }
    }

    private function assertReplay(array $expected, array $actual): void
    {
        // This migration intentionally does not backfill historical facts. A
        // pre-upgrade successful command may still be replayed by its client;
        // the migration-added fields are zero there, so preserve that original
        // immutable result instead of recomputing it from today's config.
        if ((int)($actual['partner_category_id_snapshot'] ?? 0) === 0
            && (int)($actual['partner_config_version_snapshot'] ?? 0) === 0
            && (int)($actual['partner_share_amount_cents'] ?? 0) === 0
            && ((int)($expected['partner_category_id_snapshot'] ?? 0) !== 0
                || (int)($expected['partner_config_version_snapshot'] ?? 0) !== 0
                || (int)($expected['partner_share_amount_cents'] ?? 0) !== 0)) {
            return;
        }
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $actual) || (string)$actual[$column] !== (string)$value) {
                throw new \LogicException('card_category_allocation_replay_conflict');
            }
        }
    }

    /** @return array<string,int> */
    private function allocateByAmounts(int $amount, array $rows, string $amountField): array
    {
        if ($amount < 0 || $rows === []) {
            throw new \LogicException('card_category_allocation_input_invalid');
        }
        $total = 0;
        foreach ($rows as $row) {
            $weight = (int)($row[$amountField] ?? -1);
            if ($weight < 0) throw new \LogicException('card_category_allocation_weight_invalid');
            $total += $weight;
        }
        if ($amount === 0) return array_fill_keys(array_keys($rows), 0);
        if ($total <= 0 || $amount > $total && $amountField === 'sale_amount_cents') {
            throw new \LogicException('card_category_allocation_total_invalid');
        }
        $result = []; $remainders = []; $allocated = 0;
        foreach ($rows as $key => $row) {
            $weight = (int)$row[$amountField];
            $product = bcmul((string)$amount, (string)$weight, 0);
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
            $parent = Db::name('store_product_category')->where('id', $parentId)->lock(true)->field('id,pid,cate_name')->find();
            if (!$parent) break;
            array_unshift($parts, trim((string)$parent['cate_name']));
            $parentId = (int)$parent['pid'];
        }
        return implode(' / ', array_filter($parts));
    }

    /** @return array{partner_category_id_snapshot:int,partner_category_name_snapshot:string,partner_category_path_snapshot:string,partner_default_ratio_snapshot:int,partner_config_version_snapshot:int,partner_share_amount_cents:int} */
    private function emptyPartnerSnapshot(): array
    {
        return [
            'partner_category_id_snapshot' => 0,
            'partner_category_name_snapshot' => '',
            'partner_category_path_snapshot' => '',
            'partner_default_ratio_snapshot' => 0,
            'partner_config_version_snapshot' => 0,
            'partner_share_amount_cents' => 0,
        ];
    }
}
