<?php

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 从原销售单的有效现金收款生成成交资格；退款和其他销售单不参与凑门槛。
 * 输入须先核实原单身份、门店和来源，并排除不具备人数资格的历史补交。
 */
final class StoreDealPeopleQualificationServices
{
    private const TENANT_ID = CashierV3ScopeResolver::TENANT_SCOPE_ID;
    // V3 checkout writes its original cash receipt as cashier_snapshot; older writers use sales_order.
    private const SALE_PAYMENT_SOURCE_TYPES = ['sales_order', 'cashier_snapshot'];

    /**
     * 先在当前独立实例全部门店完成会员日去重，再裁剪获授权门店。
     * 候选只取查询期内发生收款的原单，累计时仍回看这些原单的全部历史收款。
     * 不读取退款负事实，也不让补交门店替代原销售门店。
     *
     * @return array<string,array<string,mixed>>
     */
    public function qualifiedDays(array $stores, array $range, array $input = [], string $method = '', int $participantEmployeeId = 0): array
    {
        $stores = (new StoreUnifiedReportOrganizationDimensionServices())->narrowStoreIds($stores, $input);
        if ($stores === []) return [];
        $candidateIds = $this->candidateOrderIds($range);
        if ($candidateIds === []) return [];
        $orders = $this->salesOrders($candidateIds);
        if ($orders === []) return [];
        $receipts = $this->receiptRows($orders);
        $selected = array_fill_keys($stores, true);
        $winners = $this->qualify($receipts);
        $participantFacts = null;
        if ($participantEmployeeId > 0 && $winners !== []) {
            $participantFacts = [];
            $groups = ['sales_order' => [], 'debt_repayment' => []];
            foreach ($winners as $winner) {
                $kind = (string)$winner['payment_source_document_type'];
                if (isset($groups[$kind])) $groups[$kind][] = (string)$winner['fact_id'];
            }
            $participantScope = new StoreReportParticipantScopeServices();
            foreach ($groups as $kind => $factIds) {
                foreach (array_chunk($factIds, 500) as $chunk) {
                    $query = Db::name('cashier_v3_payment_fact')->alias('p')
                        ->where('p.tenant_id', self::TENANT_ID)->whereIn('p.fact_id', $chunk);
                    // 补交只认本笔最新归属，不能继承原销售单的人员权限。
                    if ($kind === 'debt_repayment') $participantScope->applySupplement($query, 'p.order_id', $participantEmployeeId);
                    else $participantScope->applyOrder($query, 'p.order_id', $participantEmployeeId);
                    foreach ($query->column('p.fact_id') as $id) $participantFacts[(string)$id] = true;
                }
            }
        }
        $days = [];
        foreach ($winners as $day) {
            if ((string)$day['business_date'] < (string)$range['start']
                || (string)$day['business_date'] > (string)$range['end']) continue;
            if (!isset($selected[(int)$day['store_id']])) continue;
            if ($method !== '' && (string)$day['payment_method'] !== $method) continue;
            if ($participantFacts !== null && !isset($participantFacts[(string)$day['fact_id']])) continue;
            $days[$this->storeDaySourceMemberKey($day)] = $day;
        }
        return $this->attachCategory($days);
    }

    /** 原销售 ID 而非补交单 ID 是成交资格的唯一单据键。 */
    private function candidateOrderIds(array $range): array
    {
        $rows = Db::name('cashier_v3_payment_fact')->alias('p')
            ->leftJoin('cashier_v3_debt_repayment r',
                "r.tenant_id=p.tenant_id AND r.repayment_id=p.order_id AND p.source_document_type='debt_repayment' AND r.status='succeeded'")
            ->where('p.tenant_id', self::TENANT_ID)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('p.fact_type', 'payment_collected')
            ->where('p.fact_direction', 'forward')->where('p.amount_cents', '>', 0)
            ->whereIn('p.source_document_type', array_merge(self::SALE_PAYMENT_SOURCE_TYPES, ['debt_repayment']))
            ->whereNotIn('p.payment_method', ['other_collection', 'old_card_entry'])
            ->fieldRaw("DISTINCT CASE WHEN p.source_document_type='debt_repayment' THEN r.sales_order_id ELSE p.order_id END sales_order_id")
            ->select()->toArray();
        return array_values(array_unique(array_filter(array_map(static fn(array $row): string =>
            (string)($row['sales_order_id'] ?? ''), $rows))));
    }

    /** 作废原单不产生正常经营人数；原单来源与归属不从补交操作员或补交门店继承。 */
    private function salesOrders(array $ids): array
    {
        $orders = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $query = Db::name('cashier_v3_sales_order')->alias('s')
                ->where('s.tenant_id', self::TENANT_ID)->whereIn('s.order_id', $chunk)
                ->where('s.order_status', 'settled')->where('s.order_direction', 'forward')
                ->where('s.member_id', '>', 0);
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, 's.tenant_id', 's.order_id');
            foreach ($query->field('s.order_id,s.order_no,s.store_id,s.store_name_snapshot,s.member_id,s.member_name_snapshot,s.organization_id,s.organization_path_snapshot,s.checkout_request_id,s.settled_at,s.business_source_primary_id,s.business_source_primary_name_snapshot')->select()->toArray() as $order) {
                $orders[(string)$order['order_id']] = $order;
            }
        }
        return $orders;
    }

    /**
     * 仅人数资格排除旧卡录入原单的补交；原销售真现金和全部财务金额仍保持原事实。
     * 历史导入补交按自然键排除，不能从补交显示名称猜测。
     */
    private function receiptRows(array $orders): array
    {
        $oldCardCheckouts = [];
        $checkoutIds = array_values(array_unique(array_filter(array_column($orders, 'checkout_request_id'))));
        foreach (array_chunk($checkoutIds, 500) as $chunk) {
            foreach (Db::name('cashier_v3_checkout_payment_draft')
                ->where('tenant_id', self::TENANT_ID)->whereIn('request_id', $chunk)
                ->where('payment_method', 'old_card_entry')->where('draft_status', 'committed')
                ->column('request_id') as $requestId) $oldCardCheckouts[(string)$requestId] = true;
        }
        $policies = $this->thresholdPolicies();
        $receipts = [];
        foreach (array_chunk(array_keys($orders), 500) as $chunk) {
            $salePayments = $this->baseReceiptQuery()
                ->whereIn('p.source_document_type', self::SALE_PAYMENT_SOURCE_TYPES)->whereIn('p.order_id', $chunk)
                ->field('p.fact_id,p.order_id,p.business_date,p.settled_at,p.amount_cents,p.payment_method,p.natural_key')
                ->select()->toArray();
            foreach ($salePayments as $payment) {
                $this->appendReceipt($receipts, $payment, (string)$payment['order_id'], $orders, $policies);
            }
            $repaymentQuery = $this->baseReceiptQuery()
                ->join('cashier_v3_debt_repayment r',
                    "r.tenant_id=p.tenant_id AND r.repayment_id=p.order_id AND r.store_id=p.store_id AND r.member_id=p.member_id AND r.status='succeeded'")
                ->where('p.source_document_type', 'debt_repayment')->whereIn('r.sales_order_id', $chunk);
            // 历史导入欠款的补交不能推动正常销售单的成交资格。
            $repaymentQuery->whereNotExists(function ($imported): void {
                $imported->name('store_debt')->alias('deal_origin_debt')
                    ->join('store_order deal_origin_legacy', 'deal_origin_legacy.id=deal_origin_debt.order_id')
                    ->whereRaw('deal_origin_debt.id=r.debt_id')
                    ->where('deal_origin_legacy.channel_type', 'migration');
            });
            (new StoreReportNormalDataScopeServices())->excludeVoidedSupplementPaymentFacts(
                $repaymentQuery, 'p.tenant_id', 'p.store_id', 'p.order_id');
            foreach ($repaymentQuery->field('p.fact_id,p.order_id,r.sales_order_id,p.business_date,p.settled_at,p.amount_cents,p.payment_method,p.natural_key')->select()->toArray() as $payment) {
                $orderId = (string)$payment['sales_order_id'];
                if (isset($oldCardCheckouts[(string)$orders[$orderId]['checkout_request_id']])
                    || str_starts_with((string)$payment['natural_key'], 'historical_debt_repayment:payment:')) continue;
                $this->appendReceipt($receipts, $payment, $orderId, $orders, $policies);
            }
        }
        return $receipts;
    }

    /** 成交只接受成功正向现金；退款、其他收款和旧卡录入均不能推动单据门槛。 */
    private function baseReceiptQuery()
    {
        return Db::name('cashier_v3_payment_fact')->alias('p')
            ->where('p.tenant_id', self::TENANT_ID)->where('p.status', 'effective')
            ->where('p.fact_type', 'payment_collected')->where('p.fact_direction', 'forward')
            ->where('p.amount_cents', '>', 0)
            ->whereNotIn('p.payment_method', ['other_collection', 'old_card_entry']);
    }

    /** 门槛在原销售形成时选定；配置变更不会静默改写历史成交资格。 */
    private function appendReceipt(array &$receipts, array $payment, string $orderId, array $orders, array $policies): void
    {
        $order = $orders[$orderId] ?? null;
        if (!$order || (int)$order['business_source_primary_id'] <= 0) return;
        $name = trim((string)$order['business_source_primary_name_snapshot']);
        $thresholds = ['a' => 100000, 'other' => 50000];
        foreach ($policies as $policy) {
            // 时间戳只有秒精度；同秒结账仍沿用旧版，防止调参追溯改写已结账原单。
            if ((int)$policy['effective_at'] >= (int)$order['settled_at']
                && (int)$policy['effective_at'] !== 0) break;
            $thresholds = ['a' => (int)$policy['a_threshold_cents'], 'other' => (int)$policy['other_threshold_cents']];
        }
        $receipts[] = [
            'sales_order_id' => $orderId, 'fact_id' => (string)$payment['fact_id'],
            'payment_order_id' => (string)$payment['order_id'],
            'payment_source_document_type' => (string)($payment['source_document_type'] ??
                ((string)$payment['order_id'] === $orderId ? 'sales_order' : 'debt_repayment')),
            'payment_method' => (string)$payment['payment_method'],
            'business_date' => (string)$payment['business_date'], 'settled_at' => (int)$payment['settled_at'],
            'amount_cents' => (int)$payment['amount_cents'], 'store_id' => (int)$order['store_id'],
            'store_name_snapshot' => (string)$order['store_name_snapshot'],
            'member_id' => (int)$order['member_id'],
            'member_name_snapshot' => (string)$order['member_name_snapshot'],
            'organization_id' => (string)$order['organization_id'],
            'organization_path_snapshot' => (string)$order['organization_path_snapshot'],
            'order_no_snapshot' => (string)$order['order_no'],
            'business_source_primary_id' => (int)$order['business_source_primary_id'],
            'business_source_primary_name_snapshot' => $name,
            'threshold_cents' => preg_match('/^A/u', $name) ? $thresholds['a'] : $thresholds['other'],
        ];
    }

    /** 配置表由本轮迁移创建并写入默认版本；缺失迁移时不静默使用旧门槛。 */
    private function thresholdPolicies(): array
    {
        return Db::name('cashier_v3_deal_people_threshold_policy')->where('tenant_id', self::TENANT_ID)
            ->order('effective_at', 'asc')->order('id', 'asc')
            ->field('effective_at,a_threshold_cents,other_threshold_cents')->select()->toArray();
    }

    /** 报表维度键仍包含原单门店和来源，但会员日资格本身已在全实例去重。 */
    private function storeDaySourceMemberKey(array $row): string
    {
        return (int)$row['store_id'] . '|' . (string)$row['business_date'] . '|'
            . (int)$row['business_source_primary_id'] . '|' . (int)$row['member_id'];
    }

    /**
     * 人数分类只看首度达标时已发生的现金分摊，不借以后补交或退款改写归属。
     * 没有可核实商品链的资格仍计入全局人数，但不猜测其商品分类。
     */
    private function attachCategory(array $days): array
    {
        if ($days === []) return [];
        $factToDay = [];
        foreach ($days as $key => $day) {
            foreach ($day['qualifying_payment_fact_ids'] ?? [] as $factId) {
                if ($factId !== '') $factToDay[$factId] = $key;
            }
        }
        $allocations = [];
        $saleFactIds = [];
        foreach (array_chunk(array_keys($factToDay), 500) as $chunk) {
            $rows = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')
                ->join('cashier_v3_sale_fact s', 's.tenant_id=a.tenant_id AND s.fact_id=a.sale_fact_id')
                ->where('a.tenant_id', self::TENANT_ID)->whereIn('a.payment_fact_id', $chunk)
                ->where('a.status', 'effective')->where('a.fact_direction', 'forward')
                ->where('a.amount_cents', '>', 0)->where('s.status', 'effective')
                ->where('s.fact_direction', 'forward')
                ->field('a.payment_fact_id,a.sale_fact_id,a.amount_cents,s.order_id,s.store_id,s.source_type,s.id sale_sequence')
                ->select()->toArray();
            foreach ($rows as $row) {
                $key = $factToDay[(string)$row['payment_fact_id']] ?? null;
                if ($key === null || (string)$row['order_id'] !== (string)$days[$key]['sales_order_id']) continue;
                $allocations[$key][] = $row;
                $saleFactIds[(string)$row['sale_fact_id']] = true;
            }
        }
        if ($allocations === []) return $days;
        $directItems = [];
        $cardItems = [];
        foreach (array_chunk(array_keys($saleFactIds), 500) as $chunk) {
            foreach (Db::name('cashier_v3_report_sale_dimension_fact')
                ->where('tenant_id', self::TENANT_ID)->whereIn('sale_fact_id', $chunk)
                ->field('sale_fact_id,item_id,item_name_snapshot')->select()->toArray() as $item) {
                $directItems[(string)$item['sale_fact_id']] = $item;
            }
            foreach (Db::name('cashier_v3_card_sale_item_allocation_fact')
                ->where('tenant_id', self::TENANT_ID)->whereIn('sale_fact_id', $chunk)
                ->where('status', 'effective')->order('id', 'asc')
                ->field('id,allocation_fact_id,sale_fact_id,component_product_id,item_name_snapshot,sale_amount_cents,configured_amount_cents,component_count')
                ->select()->toArray() as $item) {
                $cardItems[(string)$item['sale_fact_id']][] = $item;
            }
        }
        $shares = [];
        $links = [];
        foreach ($allocations as $key => $rows) {
            foreach ($rows as $row) {
                $saleFactId = (string)$row['sale_fact_id'];
                $baseSequence = (int)$row['sale_sequence'];
                if ((string)$row['source_type'] !== 'card') {
                    $itemId = (int)($directItems[$saleFactId]['item_id'] ?? 0);
                    $sequence = sprintf('%020d:%020d', $baseSequence, 0);
                    $shares[$key][] = ['store_id' => (int)$row['store_id'], 'item_id' => $itemId,
                        'item_name' => (string)($directItems[$saleFactId]['item_name_snapshot'] ?? ''),
                        'amount_cents' => (int)$row['amount_cents'], 'sequence' => $sequence];
                    $links[$key . '|' . (count($shares[$key]) - 1)] = ['store_id' => (int)$row['store_id'], 'product_id' => $itemId];
                    continue;
                }
                $items = $cardItems[$saleFactId] ?? [];
                if ($items === []) continue;
                $weights = [];
                foreach ($items as $item) {
                    $weight = abs((int)$item['sale_amount_cents']) ?: abs((int)$item['configured_amount_cents'])
                        ?: max(1, (int)$item['component_count']);
                    $weights[] = ['fact_id' => (string)$item['allocation_fact_id'],
                        'sale_amount_cents' => $weight, 'debt_amount_cents' => 0];
                }
                $split = StoreReportPartnerCategorySnapshotServices::allocateAmountBySaleFact(
                    (int)$row['amount_cents'], $weights);
                foreach ($items as $item) {
                    $itemId = (int)$item['component_product_id'];
                    $shares[$key][] = ['store_id' => (int)$row['store_id'], 'item_id' => $itemId,
                        'item_name' => (string)$item['item_name_snapshot'],
                        'amount_cents' => (int)($split[(string)$item['allocation_fact_id']] ?? 0),
                        'sequence' => sprintf('%020d:%020d', $baseSequence, (int)$item['id'])];
                    $links[$key . '|' . (count($shares[$key]) - 1)] = ['store_id' => (int)$row['store_id'], 'product_id' => $itemId];
                }
            }
        }
        $categories = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        foreach ($shares as $key => $rows) {
            foreach ($rows as $index => &$row) {
                $row['category'] = $categories[$key . '|' . $index] ?? [];
            }
            unset($row);
            $winner = self::largestCategory($rows);
            if ($winner !== []) {
                $days[$key]['category_root_id'] = (int)$winner['root_id'];
                $days[$key]['category_id'] = (int)$winner['id'];
                $days[$key]['category_path'] = (string)$winner['path'];
                $days[$key]['item_id'] = (int)$winner['item_id'];
                $days[$key]['item_name'] = (string)$winner['item_name'];
                $days[$key]['product_type'] = (int)$winner['product_type'];
                $days[$key]['platform_product_id'] = (int)$winner['platform_product_id'];
            }
        }
        return $days;
    }

    /** 同一原单先归现金分摊最大的完整分类，平手按明细顺序；大类报表再向上投影。 */
    public static function largestCategory(array $shares): array
    {
        $categories = [];
        foreach ($shares as $row) {
            $category = $row['category'] ?? [];
            $categoryId = (int)($category['id'] ?? 0);
            $amount = (int)($row['amount_cents'] ?? 0);
            if ($categoryId <= 0 || $amount <= 0) continue;
            $categories[$categoryId]['amount'] = (int)($categories[$categoryId]['amount'] ?? 0) + $amount;
            $sequence = (string)($row['sequence'] ?? '');
            if (!isset($categories[$categoryId]['sequence']) || strcmp($sequence, $categories[$categoryId]['sequence']) < 0) {
                $categories[$categoryId]['sequence'] = $sequence;
            }
        }
        if ($categories === []) return [];
        uasort($categories, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']
            ?: strcmp($a['sequence'], $b['sequence']));
        $categoryId = (int)array_key_first($categories);
        $items = [];
        foreach ($shares as $row) {
            if ((int)($row['category']['id'] ?? 0) !== $categoryId || (int)$row['amount_cents'] <= 0) continue;
            $itemId = (int)$row['item_id'];
            $items[$itemId]['amount'] = (int)($items[$itemId]['amount'] ?? 0) + (int)$row['amount_cents'];
            if (!isset($items[$itemId]['sequence'])
                || strcmp((string)$row['sequence'], (string)$items[$itemId]['sequence']) < 0) {
                $items[$itemId]['sequence'] = (string)$row['sequence'];
                $items[$itemId]['category'] = $row['category'];
                $items[$itemId]['item_name'] = (string)($row['item_name'] ?? '');
            }
        }
        uasort($items, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']
            ?: strcmp($a['sequence'], $b['sequence']));
        $itemId = (int)array_key_first($items);
        return $items[$itemId]['category'] + [
            'item_id' => $itemId,
            'item_name' => (string)($items[$itemId]['item_name'] ?? ''),
        ];
    }

    /**
     * 每张原单仅在累计首度达标时产生资格，再按会员业务日全局选最早一笔。
     * 返回的门店是原销售门店；调用方只能在去重后按权限裁剪门店。
     *
     * @param array<int,array<string,mixed>> $receipts
     * @return array<string,array<string,mixed>> 以业务日和会员 ID 为键的资格
     */
    public function qualify(array $receipts): array
    {
        usort($receipts, static function (array $left, array $right): int {
            return strcmp((string)($left['business_date'] ?? ''), (string)($right['business_date'] ?? ''))
                ?: ((int)($left['settled_at'] ?? 0) <=> (int)($right['settled_at'] ?? 0))
                ?: strcmp((string)($left['fact_id'] ?? ''), (string)($right['fact_id'] ?? ''));
        });
        $orderTotals = [];
        $orderReceiptIds = [];
        $qualifiedOrders = [];
        $memberDays = [];
        foreach ($receipts as $receipt) {
            $orderId = (string)($receipt['sales_order_id'] ?? '');
            $memberId = (int)($receipt['member_id'] ?? 0);
            $amount = (int)($receipt['amount_cents'] ?? 0);
            $threshold = (int)($receipt['threshold_cents'] ?? 0);
            $date = (string)($receipt['business_date'] ?? '');
            if ($orderId === '' || $memberId <= 0 || $amount <= 0 || $threshold <= 0 || $date === '') continue;
            if (isset($qualifiedOrders[$orderId])) continue;
            $orderTotals[$orderId] = ($orderTotals[$orderId] ?? 0) + $amount;
            $orderReceiptIds[$orderId][] = (string)($receipt['fact_id'] ?? '');
            if ($orderTotals[$orderId] < $threshold) continue;
            $qualifiedOrders[$orderId] = true;
            $key = $date . '|' . $memberId;
            // 全实例先选最早达标的原单，跨店报表之后不能各自重复认领同一人日。
            if (!isset($memberDays[$key])) {
                $receipt['qualifying_payment_fact_ids'] = $orderReceiptIds[$orderId];
                $memberDays[$key] = $receipt;
            }
        }
        return $memberDays;
    }
}
