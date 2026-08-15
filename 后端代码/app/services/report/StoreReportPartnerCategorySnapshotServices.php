<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use think\facade\Db;

/**
 * Resolves the partner category that was effective at successful checkout.
 *
 * Configuration remains mutable, while every returned value is written to a
 * fact in the same transaction. Report queries therefore never reapply a
 * current category switch or ratio to historical cash performance.
 */
final class StoreReportPartnerCategorySnapshotServices
{
    /** @var array<string,array<string,array>> */
    private $configsByTenant = [];

    /** @var array<int,array|null> */
    private $categories = [];

    /** @return array<string,int> sale fact id => allocated cash-performance cents */
    public static function cashPerformanceBySaleFact(CashierV3CheckoutFactPlanV1 $plan): array
    {
        $sales = array_values((array)($plan->rows()['sale'] ?? []));
        $cash = 0;
        foreach ((array)($plan->rows()['payment'] ?? []) as $payment) {
            $amount = (int)($payment['amount_cents'] ?? -1);
            if ($amount < 0) {
                throw new \LogicException('partner_share_payment_amount_invalid');
            }
            $cash += $amount;
        }
        return self::allocateAmountBySaleFact($cash, $sales);
    }

    /**
     * Allocates a bookkeeping amount after the debt already assigned to each
     * sale line. The same cent-level basis is shared by payment, category and
     * partner projections, so an unpaid amount is never redistributed to a
     * different item in a mixed checkout.
     *
     * @param array<int,array> $sales
     * @return array<string,int> sale fact id => allocated cents
     */
    public static function allocateAmountBySaleFact(int $amountCents, array $sales): array
    {
        if ($sales === []) {
            return [];
        }
        $sign = $amountCents < 0 ? -1 : 1;
        $amountAbsolute = abs($amountCents);
        $total = 0;
        $normalized = [];
        foreach (array_values($sales) as $index => $sale) {
            $factId = trim((string)($sale['fact_id'] ?? ''));
            $saleAmount = (int)($sale['sale_amount_cents'] ?? 0);
            $debtAmount = (int)($sale['debt_amount_cents'] ?? 0);
            if ($factId === '' || isset($normalized[$factId])
                || ($saleAmount !== 0 && ($saleAmount < 0 ? -1 : 1) !== $sign)
                || ($debtAmount !== 0 && ($debtAmount < 0 ? -1 : 1) !== $sign)
                || abs($debtAmount) > abs($saleAmount)) {
                throw new \LogicException('partner_share_sale_fact_invalid');
            }
            $allocationBase = abs($saleAmount) - abs($debtAmount);
            $normalized[$factId] = ['amount' => $allocationBase, 'index' => (int)$index];
            $total += $allocationBase;
        }
        if ($amountAbsolute === 0) {
            return array_fill_keys(array_keys($normalized), 0);
        }
        if ($total <= 0 || $amountAbsolute > $total) {
            throw new \LogicException('partner_share_cash_allocation_total_invalid');
        }

        $result = [];
        $remainders = [];
        $allocated = 0;
        foreach ($normalized as $factId => $row) {
            $product = bcmul((string)$amountAbsolute, (string)$row['amount'], 0);
            $allocatedAmount = (int)bcdiv($product, (string)$total, 0);
            $result[$factId] = $allocatedAmount;
            $allocated += $allocatedAmount;
            $remainders[] = [
                'factId' => $factId,
                'remainder' => (int)bcmod($product, (string)$total),
                'index' => $row['index'],
            ];
        }
        usort($remainders, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder']
                ?: $left['index'] <=> $right['index'];
        });
        for ($remaining = $amountAbsolute - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $result[$remainders[$index]['factId']]++;
        }
        foreach ($result as $factId => $allocatedAmount) {
            $result[$factId] = $sign * $allocatedAmount;
        }
        return $result;
    }

    /**
     * @return array{partner_category_id_snapshot:int,partner_category_name_snapshot:string,partner_category_path_snapshot:string,partner_default_ratio_snapshot:int,partner_config_version_snapshot:int,partner_share_amount_cents:int}
     */
    public function resolveInTx(string $tenantId, int $categoryId, int $cashPerformanceAmountCents): array
    {
        CashierV3TransactionGuard::assertInTransaction('storeReportPartnerCategorySnapshot');
        if ($categoryId <= 0 || $cashPerformanceAmountCents < 0) {
            throw new \LogicException('partner_share_snapshot_input_invalid');
        }
        $empty = [
            'partner_category_id_snapshot' => 0,
            'partner_category_name_snapshot' => '',
            'partner_category_path_snapshot' => '',
            'partner_default_ratio_snapshot' => 0,
            'partner_config_version_snapshot' => 0,
            'partner_share_amount_cents' => 0,
        ];
        $chain = $this->categoryChain($categoryId);
        if ($chain === []) {
            return $empty;
        }
        $levelOne = $chain[0];
        $levelTwo = $chain[1] ?? null;
        $configs = $this->enabledConfigs($tenantId);

        $effective = null;
        if ($levelTwo !== null && isset($configs[(string)$levelTwo['id']])) {
            $effective = $levelTwo;
        } elseif (isset($configs[(string)$levelOne['id']]) && !$this->hasEnabledSecondLevel($configs, (int)$levelOne['id'])) {
            $effective = $levelOne;
        }
        if ($effective === null) {
            return $empty;
        }

        $config = $configs[(string)$effective['id']];
        $ratio = max(0, min(100, (int)($config['partner_default_ratio'] ?? 0)));
        return [
            'partner_category_id_snapshot' => (int)$effective['id'],
            'partner_category_name_snapshot' => (string)$effective['cate_name'],
            'partner_category_path_snapshot' => $this->pathFromChain($chain, (int)$effective['id']),
            'partner_default_ratio_snapshot' => $ratio,
            'partner_config_version_snapshot' => (int)($config['version'] ?? 0),
            'partner_share_amount_cents' => $this->shareCents($cashPerformanceAmountCents, $ratio),
        ];
    }

    private function hasEnabledSecondLevel(array $configs, int $levelOneId): bool
    {
        foreach ($configs as $categoryId => $config) {
            $category = $this->category((int)$categoryId);
            if ($category !== null
                && (int)($category['is_show'] ?? 0) === 1
                && (int)($category['pid'] ?? 0) === $levelOneId) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,array> */
    private function enabledConfigs(string $tenantId): array
    {
        if (isset($this->configsByTenant[$tenantId])) {
            return $this->configsByTenant[$tenantId];
        }
        $result = [];
        foreach (Db::name('cashier_v3_report_category_config')
            ->where('tenant_id', $tenantId)->where('enabled', 1)->lock(true)
            ->field('category_id,partner_default_ratio,version')->select()->toArray() as $row) {
            $id = (int)($row['category_id'] ?? 0);
            if ($id > 0) {
                $result[(string)$id] = $row;
            }
        }
        $this->configsByTenant[$tenantId] = $result;
        return $result;
    }

    /** @return array<int,array> root first */
    private function categoryChain(int $categoryId): array
    {
        $result = [];
        $seen = [];
        for ($guard = 0; $categoryId > 0 && $guard < 16; $guard++) {
            if (isset($seen[$categoryId])) {
                throw new \LogicException('partner_share_category_cycle');
            }
            $seen[$categoryId] = true;
            $category = $this->category($categoryId);
            if ($category === null || (int)($category['is_show'] ?? 0) !== 1) {
                return [];
            }
            array_unshift($result, $category);
            $categoryId = (int)($category['pid'] ?? 0);
        }
        if ($categoryId > 0) {
            throw new \LogicException('partner_share_category_depth_invalid');
        }
        return $result;
    }

    private function category(int $id): ?array
    {
        if (!array_key_exists($id, $this->categories)) {
            $row = Db::name('store_product_category')->where('id', $id)->lock(true)
                ->field('id,pid,cate_name,is_show')->find();
            $this->categories[$id] = is_array($row) ? $row : null;
        }
        return $this->categories[$id];
    }

    private function pathFromChain(array $chain, int $effectiveCategoryId): string
    {
        $parts = [];
        foreach ($chain as $category) {
            $parts[] = trim((string)$category['cate_name']);
            if ((int)$category['id'] === $effectiveCategoryId) {
                break;
            }
        }
        return implode(' / ', array_filter($parts));
    }

    private function shareCents(int $cashPerformanceAmountCents, int $ratio): int
    {
        if ($cashPerformanceAmountCents === 0 || $ratio === 0) {
            return 0;
        }
        // Half-up rounding at the cent: no floats, and no later report-time rounding.
        return (int)bcdiv(bcadd(bcmul((string)$cashPerformanceAmountCents, (string)$ratio, 0), '50', 0), '100', 0);
    }
}
