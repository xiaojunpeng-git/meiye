<?php
declare(strict_types=1);

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use think\facade\Db;

/**
 * Read model for the member debt overlay.
 *
 * Recharge debts deliberately use store_debt.order_id = 0, so they cannot be
 * read through the legacy store_debt -> store_order join. The authority map is
 * the only supported way to recognise that compatibility sentinel.
 */
final class CashierV3MemberDebtProjectionServices
{
    public const CONTRACT_VERSION = 'cashier-v3-member-debt-projection-v1';

    /** @return array<string,mixed> */
    public function read(
        int $memberId,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope
    ): array {
        if ($memberId <= 0 || !$scope->allowsStore($operator->storeId())
            || $operator->storeId() !== $scope->forcedStoreId()
            || $operator->tenantId() === '' || $operator->tenantId() !== $scope->tenantId()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权查看该会员的欠款。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }

        return Db::transaction(function () use ($memberId, $operator, $scope): array {
            $member = (array)Db::name('user')->where('uid', $memberId)
                ->field('uid,real_name,nickname,phone,bar_code,now_money,balance_version,status,is_del,delete_time')
                ->find();
            if (!$this->activeMember($member)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::RESOURCE_NOT_FOUND,
                    '当前会员不存在或已经停用。',
                    CashierV3ResultCode::STATUS_FAILED
                );
            }

            // 欠款属于会员账务，不按当前收银门店或来源门店切分。收银已允许
            // 跨店使用权益时，顶部总欠款、欠款明细与卡级欠款必须是同一会员
            // 全量未结清口径，不能出现“总欠款 0、卡项被欠款限制”的矛盾。
            $rows = $this->debtRowsForMember($memberId);

            $debtIds = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
            $summariesByDebt = $this->summariesForDebts($debtIds);
            // 原销售的人员事实只作本次补交的默认草稿，不能用它代替补交自己的
            // 归属快照；按已授权会员欠款行的来源单批量读取，避免逐条查询。
            $saleOrderIds = array_values(array_unique(array_filter(array_map('strval', array_column($rows, 'sales_order_id')))));
            $originalGuides = $originalManagers = [];
            if ($saleOrderIds !== []) {
                foreach (Db::name('cashier_v3_customer_guide_round_fact')->where('tenant_id', $scope->tenantId())
                    ->whereIn('order_id', $saleOrderIds)->where('status', 'effective')
                    ->field('order_id,member_id,guide_employee_id,guide_employee_name_snapshot,guide_round_no')->select()->toArray() as $fact) {
                    $originalGuides[(string)$fact['order_id']][] = $fact;
                }
                foreach (Db::name('cashier_v3_sales_manager_fact')->where('tenant_id', $scope->tenantId())
                    ->whereIn('order_id', $saleOrderIds)->where('status', 'effective')
                    ->field('order_id,member_id,sales_manager_employee_id,sales_manager_name_snapshot')->select()->toArray() as $fact) {
                    $originalManagers[(string)$fact['order_id']][] = $fact;
                }
            }
            $records = [];
            $total = '0.00';
            foreach ($rows as $row) {
                $debtId = (int)($row['id'] ?? 0);
                $debtKind = $this->debtKind($row, $memberId);
                $isRecharge = $debtKind === 'recharge';
                $isV3Sale = $debtKind === 'v3_sale';
                // A debt row must be tied either to a recharge authority record
                // for this forced store or to this member's sales order. Earlier
                // local authority records use tenant_id=0 as the default-tenant
                // sentinel, so projection scope must rely on store/member rather
                // than silently hiding that valid V3 debt.
                if ($debtKind === '') {
                    continue;
                }
                $original = $this->money($row['total_debt'] ?? '0.00');
                $repaid = $this->money($row['repaid_debt'] ?? '0.00');
                $remaining = bcsub($original, $repaid, 2);
                if (bccomp($remaining, '0.00', 2) <= 0) {
                    continue;
                }
                $orderNo = $isRecharge
                    ? (string)($row['recharge_order_no_snapshot'] ?? $row['order_sn'] ?? '')
                    : ($isV3Sale
                        ? (string)($row['sales_order_no_snapshot'] ?? $row['order_sn'] ?? '')
                        : (string)($row['order_sn'] ?? ''));
                // 欠款只展示原销售或充值单冻结来源；该值随欠款交接到结账页，
                // 最终提交仍须由服务端重新锁定原单核对，不能相信此只读投影。
                $sourcePrefix = $isRecharge ? 'recharge_source_' : ($isV3Sale ? 'original_source_' : '');
                $originalSource = $sourcePrefix !== '' ? [
                    'primarySourceId' => (int)($row[$sourcePrefix . 'primary_id'] ?? 0),
                    'primarySourceNameSnapshot' => (string)($row[$sourcePrefix . 'primary_name'] ?? ''),
                    'secondarySourceId' => (int)($row[$sourcePrefix . 'secondary_id'] ?? 0),
                    'secondarySourceNameSnapshot' => (string)($row[$sourcePrefix . 'secondary_name'] ?? ''),
                    'displayNameSnapshot' => (string)($row[$sourcePrefix . 'label'] ?? ''),
                ] : null;
                // 历史原单若只有残缺来源，不将半截信息交给收银端显示或提交。
                if ($originalSource !== null && ($originalSource['primarySourceId'] <= 0
                    || trim($originalSource['primarySourceNameSnapshot']) === ''
                    || trim($originalSource['displayNameSnapshot']) === ''
                    || ($originalSource['secondarySourceId'] > 0 && trim($originalSource['secondarySourceNameSnapshot']) === ''))) {
                    $originalSource = null;
                }
                $saleOrderId = (string)($row['sales_order_id'] ?? '');
                $defaultGuides = [];
                foreach ($originalGuides[$saleOrderId] ?? [] as $fact) {
                    if ((int)$fact['member_id'] !== $memberId) continue;
                    $employeeId = (int)$fact['guide_employee_id'];
                    $defaultGuides[$employeeId] = ['staffId' => $employeeId, 'employeeId' => $employeeId,
                        'name' => (string)$fact['guide_employee_name_snapshot'],
                        'guideRoundNo' => (int)($fact['guide_round_no'] ?? 0)];
                }
                $defaultManagers = [];
                foreach ($originalManagers[$saleOrderId] ?? [] as $fact) {
                    if ((int)$fact['member_id'] !== $memberId) continue;
                    $employeeId = (int)$fact['sales_manager_employee_id'];
                    $defaultManagers[$employeeId] = ['staffId' => $employeeId, 'employeeId' => $employeeId,
                        'name' => (string)$fact['sales_manager_name_snapshot']];
                }
                $records[] = [
                    'id' => (string)$debtId,
                    'debtId' => $debtId,
                    'debtNo' => (string)($row['debt_no'] ?? ''),
                    'sourceType' => $isRecharge ? '充值欠款' : '销售订单欠款',
                    'sourceLabel' => $isRecharge ? '充值欠款' : '销售订单欠款',
                    'sourceOrderNo' => $orderNo,
                    'originalBusinessSource' => $originalSource,
                    'originalGuideSelections' => array_values($defaultGuides),
                    'originalSalesManagerSelections' => array_values($defaultManagers),
                    'summary' => $isRecharge ? '储值充值' : ($summariesByDebt[$debtId] ?? '销售订单欠款'),
                    'originalDebtAmount' => $original,
                    'repaidAmount' => $repaid,
                    'remainingDebtAmount' => $remaining,
                    'status' => '待补交',
                    'statusLabel' => '待补交',
                    'businessDate' => date('Y-m-d', max(0, (int)($row['add_time'] ?? 0))),
                    'recordVersion' => max(1, (int)($row['update_time'] ?? 0)),
                    'revision' => max(1, (int)($row['update_time'] ?? 0)),
                ];
                $total = bcadd($total, $remaining, 2);
            }
            $name = trim((string)($member['real_name'] ?? '')) ?: trim((string)($member['nickname'] ?? ''));
            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'memberId' => $memberId,
                'member' => [
                    'id' => (string)$memberId,
                    'memberId' => $memberId,
                    // Debt may be opened outside the cashier's active member.
                    // Supply that authorized member's per-kind source choices.
                    'sourceDefaults' => (new \app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices())
                        ->initialChoices($scope->tenantId(), $operator->storeId(), $memberId),
                    'name' => $name !== '' ? $name : '未命名会员',
                    'phone' => (string)($member['phone'] ?? ''),
                    'memberNo' => (string)($member['bar_code'] ?? ''),
                ],
                'records' => $records,
                'outstandingDebtAmount' => $total,
                'outstandingDebtCount' => count($records),
                'balanceVersion' => max(1, (int)($member['balance_version'] ?? 0)),
                'debtDataAsOf' => date('Y-m-d H:i:s'),
            ];
        });
    }

    public function amountForMember(int $memberId): string
    {
        if ($memberId <= 0) {
            return '0.00';
        }
        $recognized = [];
        foreach ($this->debtRowsForMember($memberId) as $row) {
            if ($this->debtKind($row, $memberId) !== '') {
                $recognized[] = $row;
            }
        }
        return $this->pendingTotal($recognized);
    }

    private function debtRowsForMember(int $memberId): array
    {
        return $this->rows(Db::name('store_debt')->alias('d')
            ->leftJoin('cashier_v3_recharge_debt_authority r', 'r.debt_id=d.id')
            ->leftJoin('user_recharge rr', 'rr.id=r.recharge_id')
            ->leftJoin('cashier_v3_debt_authority a', 'a.debt_id=d.id')
            ->leftJoin('cashier_v3_sales_order s', 's.id=a.sales_order_record_id AND s.order_id=a.sales_order_id')
            ->leftJoin('store_order o', 'o.id=d.order_id')
            ->where('d.uid', $memberId)
            ->where('d.status', 0)
            ->field('d.id,d.store_id AS debt_store_id,d.debt_no,d.order_id,d.order_sn,d.total_debt,d.repaid_debt,d.status,d.add_time,d.update_time,d.remark,'
                . 'r.member_id AS recharge_member_id,r.recharge_id,r.recharge_order_no_snapshot,'
                . 'rr.business_source_primary_id AS recharge_source_primary_id,rr.business_source_primary_name_snapshot AS recharge_source_primary_name,'
                . 'rr.business_source_secondary_id AS recharge_source_secondary_id,rr.business_source_secondary_name_snapshot AS recharge_source_secondary_name,rr.business_source_label_snapshot AS recharge_source_label,'
                . 'a.store_id AS authority_store_id,a.member_id AS authority_member_id,a.sales_order_record_id,a.sales_order_id,a.sales_order_no_snapshot,'
                . 's.member_id AS v3_order_member_id,s.business_source_primary_id AS original_source_primary_id,'
                . 's.business_source_primary_name_snapshot AS original_source_primary_name,s.business_source_secondary_id AS original_source_secondary_id,'
                . 's.business_source_secondary_name_snapshot AS original_source_secondary_name,s.business_source_label_snapshot AS original_source_label,'
                . 'o.uid AS order_member_id')
            ->order('d.add_time desc,d.id desc')
            ->select());
    }

    private function debtKind(array $row, int $memberId): string
    {
        if ((int)($row['order_id'] ?? -1) === 0
            && (int)($row['recharge_member_id'] ?? 0) === $memberId
            && (int)($row['recharge_id'] ?? 0) > 0) {
            return 'recharge';
        }
        // The debt authority is the immutable V3 sales-debt mapping. Some
        // historical migrations have the authority row but no longer retain a
        // cashier_v3_sales_order projection. A missing projection must not
        // hide a real outstanding debt. If that projection does exist, keep it
        // as an additional member consistency check.
        $salesOrderRecordId = (int)($row['sales_order_record_id'] ?? 0);
        $debtOrderId = (int)($row['order_id'] ?? 0);
        if (trim((string)($row['sales_order_id'] ?? '')) !== ''
            && trim((string)($row['sales_order_no_snapshot'] ?? '')) !== ''
            && (int)($row['authority_member_id'] ?? 0) === $memberId
            && (int)($row['authority_store_id'] ?? 0) > 0
            && (int)($row['authority_store_id'] ?? 0) === (int)($row['debt_store_id'] ?? 0)
            // New V3 debts keep the sales-order record ID. Migration debt
            // authorities intentionally use 0 and retain the immutable order
            // number snapshot instead, while store_debt keeps its legacy row.
            && (($salesOrderRecordId > 0 && $debtOrderId === $salesOrderRecordId)
                || ($salesOrderRecordId === 0 && $debtOrderId > 0))
            && ((int)($row['v3_order_member_id'] ?? 0) === 0
                || (int)($row['v3_order_member_id'] ?? 0) === $memberId)) {
            return 'v3_sale';
        }
        if ((int)($row['order_id'] ?? 0) > 0
            && (int)($row['order_member_id'] ?? 0) === $memberId) {
            return 'legacy_sale';
        }
        return '';
    }

    /**
     * Return display names only for debt IDs already filtered by the caller's
     * member or store scope. Both debt views must use this issuance-time name.
     *
     * @param array<int,int> $debtIds
     * @return array<int,string>
     */
    public function summariesForDebts(array $debtIds): array
    {
        $summaries = [];
        foreach ($this->itemsByDebt($debtIds) as $debtId => $items) {
            $summaries[$debtId] = $this->summary($items);
        }
        return $summaries;
    }

    /**
     * Read the current platform categories of the goods or projects behind each debt.
     * A card is only a container: its outer category cannot classify repayment of its projects.
     * Ambiguous historical card orders and missing component links remain unclassified.
     *
     * @param array<int,int> $debtIds IDs already constrained by the caller's data scope
     * @return array<int,array<int,array>>
     */
    public function currentCategoriesForDebts(array $debtIds): array
    {
        $debtIds = array_values(array_unique(array_filter(array_map('intval', $debtIds))));
        if ($debtIds === []) return [];

        $items = $this->rows(Db::name('store_debt_item')->alias('i')
            ->join('store_debt debt', 'debt.id=i.debt_id')
            ->whereIn('i.debt_id', $debtIds)
            ->field('i.debt_id,i.id debt_item_id,i.order_id,i.product_type debt_product_type,i.product_id,debt.store_id,debt.uid debt_member_id')
            ->order('i.id asc')->select());
        $cardOrderIds = [];
        foreach ($items as $item) {
            if ((int)$item['debt_product_type'] === 5 && (int)$item['order_id'] > 0) {
                $cardOrderIds[(int)$item['order_id']] = true;
            }
        }
        $cardItemsByOrder = $cardHeadersByOrder = $componentsByOrder = [];
        if ($cardOrderIds !== []) {
            // Count across the original order, not just this page's debts; otherwise
            // one card could inherit another card's projects in a mixed historical order.
            foreach ($this->rows(Db::name('store_debt_item')->whereIn('order_id', array_keys($cardOrderIds))
                ->where('product_type', 5)->field('order_id')->select()) as $cardItem) {
                $orderId = (int)$cardItem['order_id'];
                $cardItemsByOrder[$orderId] = ($cardItemsByOrder[$orderId] ?? 0) + 1;
            }
            $cartRows = $this->rows(Db::name('store_order_cart_info')->alias('c')
                ->join('store_order o', 'o.id=c.oid')
                ->whereIn('c.oid', array_keys($cardOrderIds))->whereIn('c.product_type', [5, 6])
                ->field('c.oid,c.product_id,c.cart_type,c.product_type,o.store_id,o.uid member_id')
                ->order('c.id asc')->select());
            foreach ($cartRows as $cartRow) {
                $orderId = (int)$cartRow['oid'];
                if ((int)$cartRow['cart_type'] === 2 && (int)$cartRow['product_type'] === 6) {
                    $componentsByOrder[$orderId][] = $cartRow;
                } elseif ((int)$cartRow['product_type'] === 5 && (int)$cartRow['cart_type'] !== 2) {
                    $cardHeadersByOrder[$orderId][] = (int)$cartRow['product_id'];
                }
            }
        }
        $unresolvedCardItems = [];
        foreach ($items as $item) {
            if ((int)$item['debt_product_type'] !== 5) continue;
            $orderId = (int)$item['order_id'];
            $headers = $cardHeadersByOrder[$orderId] ?? [];
            if (($cardItemsByOrder[$orderId] ?? 0) !== 1 || count($headers) > 1
                || ($headers !== [] && $headers[0] !== (int)$item['product_id'])
                || ($componentsByOrder[$orderId] ?? []) === []) $unresolvedCardItems[] = $item;
        }
        $issuedComponents = $this->issuedCardComponentsForDebtItems($unresolvedCardItems);
        // Resolve the same product IDs with the business store as every other report.
        $links = [];
        foreach ($items as $item) {
            $store = (int)$item['store_id'];
            $candidates = array_merge([$item], $componentsByOrder[(int)$item['order_id']] ?? [],
                $issuedComponents[(int)$item['debt_item_id']] ?? []);
            foreach ($candidates as $candidate) {
                $id = (int)$candidate['product_id'];
                $links[$store . ':' . $id] = ['store_id' => $store, 'product_id' => $id];
            }
        }
        $categories = (new \app\services\report\StoreReportCurrentProductCategoryServices())->resolve($links);
        $byDebt = [];
        foreach ($items as $item) {
            $debtId = (int)($item['debt_id'] ?? 0);
            if ($debtId <= 0) continue;
            $itemCategories = [];
            $store = (int)$item['store_id'];
            if ((int)$item['debt_product_type'] === 5) {
                $orderId = (int)$item['order_id'];
                $headers = $cardHeadersByOrder[$orderId] ?? [];
                $components = [];
                if (($cardItemsByOrder[$orderId] ?? 0) === 1 && count($headers) <= 1
                    && ($headers === [] || $headers[0] === (int)$item['product_id'])) {
                    $components = array_values(array_filter($componentsByOrder[$orderId] ?? [], static function (array $component) use ($item): bool {
                        return (int)$component['store_id'] === (int)$item['store_id']
                            && (int)$component['member_id'] === (int)$item['debt_member_id'];
                    }));
                }
                if ($components === []) $components = $issuedComponents[(int)$item['debt_item_id']] ?? [];
                foreach ($components as $component) {
                    $category = $categories[$store . ':' . (int)$component['product_id']] ?? [];
                    if (in_array((int)($category['product_type'] ?? -1), [4, 5], true)) $category = [];
                    $itemCategories[] = $category ?: ['id' => 0, 'path' => ''];
                }
            } else {
                $itemCategories[] = $categories[$store . ':' . (int)$item['product_id']] ?? ['id' => 0, 'path' => ''];
            }
            if ($itemCategories === []) $itemCategories[] = ['id' => 0, 'path' => ''];
            foreach ($itemCategories as $category) $byDebt[$debtId][(int)$category['id']] = $category;
        }
        return array_map('array_values', $byDebt);
    }

    /** Display and filtering consume the same verified category set, including unresolved components. */
    public function categorySummariesForDebts(array $debtIds): array
    {
        $summaries = [];
        foreach ($this->currentCategoriesForDebts($debtIds) as $id => $categories) {
            $summaries[$id] = \app\services\report\StoreReportSaleCategoryServices::label($categories);
        }
        return $summaries;
    }

    /**
     * Some historical debts retain a legacy order ID while the issued card has
     * a different order ID. Resolve its projects only through a unique holder
     * matching member, store, card product and issue time; names are not IDs.
     *
     * @param array<int,array<string,mixed>> $items Debt rows with id/debt_item_id, debt_id and product_id
     * @return array<int,array<int,array<string,mixed>>> Components keyed by debt-item ID
     */
    public function issuedCardComponentsForDebtItems(array $items): array
    {
        $cardItems = $debtIds = [];
        foreach ($items as $item) {
            if ((int)($item['product_type'] ?? $item['debt_product_type'] ?? 0) !== 5) continue;
            $itemId = (int)($item['debt_item_id'] ?? $item['id'] ?? 0);
            $debtId = (int)($item['debt_id'] ?? 0);
            if ($itemId <= 0 || $debtId <= 0 || (int)($item['product_id'] ?? 0) <= 0) continue;
            $cardItems[$itemId] = $item;
            $debtIds[$debtId] = true;
        }
        if ($cardItems === []) return [];

        $debts = [];
        foreach ($this->rows(Db::name('store_debt')->whereIn('id', array_keys($debtIds))
            ->field('id,uid,store_id,add_time')->select()) as $debt) {
            $debts[(int)$debt['id']] = $debt;
        }
        $members = $products = $stores = $times = [];
        foreach ($cardItems as $item) {
            $debt = $debts[(int)$item['debt_id']] ?? null;
            if (!$debt) continue;
            $members[(int)$debt['uid']] = true;
            $products[(int)$item['product_id']] = true;
            $stores[(int)$debt['store_id']] = true;
            $times[(int)$debt['add_time']] = true;
        }
        if ($members === [] || $products === [] || $stores === [] || $times === []) return [];
        $holdersByIdentity = [];
        foreach ($this->rows(Db::name('user_card_holder')
            ->whereIn('uid', array_keys($members))->whereIn('store_id', array_keys($stores))
            ->whereIn('product_id', array_keys($products))->where('product_type', 5)
            ->whereBetween('add_time', [min(array_keys($times)), max(array_keys($times))])
            ->field('id,uid,store_id,product_id,add_time,oid')->select()) as $holder) {
            $key = implode(':', [(int)$holder['uid'], (int)$holder['store_id'],
                (int)$holder['product_id'], (int)$holder['add_time']]);
            $holdersByIdentity[$key][] = $holder;
        }
        $ordersByItem = $orderIds = [];
        foreach ($cardItems as $itemId => $item) {
            $debt = $debts[(int)$item['debt_id']] ?? null;
            if (!$debt) continue;
            $key = implode(':', [(int)$debt['uid'], (int)$debt['store_id'],
                (int)$item['product_id'], (int)$debt['add_time']]);
            $holders = $holdersByIdentity[$key] ?? [];
            if (count($holders) !== 1 || (int)$holders[0]['oid'] <= 0) continue;
            $orderId = (int)$holders[0]['oid'];
            $ordersByItem[$itemId] = $orderId;
            $orderIds[$orderId] = true;
        }
        if ($orderIds === []) return [];
        $cartByOrder = [];
        foreach ($this->rows(Db::name('store_order_cart_info')->alias('c')
            ->join('store_order o', 'o.id=c.oid')
            ->leftJoin('store_product p', 'p.id=c.product_id')
            ->leftJoin('store_product parent', 'parent.id=p.pid AND parent.type=0 AND parent.relation_id=0')
            ->whereIn('c.oid', array_keys($orderIds))->whereIn('c.product_type', [5, 6])
            ->field('c.id,c.oid,c.uid,o.store_id,c.product_id,c.product_type,c.cart_type,c.total_price,c.pay_price,p.type product_scope_type,p.relation_id product_store_id,p.cate_id,parent.cate_id parent_cate_id')
            ->order('c.id asc')->select()) as $cart) {
            $cartByOrder[(int)$cart['oid']][] = $cart;
        }
        $resolved = [];
        foreach ($ordersByItem as $itemId => $orderId) {
            $item = $cardItems[$itemId];
            $debt = $debts[(int)$item['debt_id']];
            $headers = $components = [];
            foreach ($cartByOrder[$orderId] ?? [] as $cart) {
                if ((int)$cart['uid'] !== (int)$debt['uid'] || (int)$cart['store_id'] !== (int)$debt['store_id']) {
                    $headers = $components = [];
                    break;
                }
                if ((int)$cart['product_type'] === 5 && (int)$cart['cart_type'] !== 2) $headers[] = $cart;
                elseif ((int)$cart['product_type'] === 6 && (int)$cart['cart_type'] === 2) $components[] = $cart;
            }
            if (count($headers) === 1 && (int)$headers[0]['product_id'] === (int)$item['product_id']
                && $components !== []) $resolved[$itemId] = $components;
        }
        return $resolved;
    }

    /** @return array<int,array<int,string>> */
    private function itemsByDebt(array $debtIds): array
    {
        if (!$debtIds) {
            return [];
        }
        // Historical imports stored a generic debt label, while the card holder
        // retains the name frozen at issuance. Match both order and product so
        // another card on the same order cannot rename this debt in the read model.
        $rows = $this->rows(Db::name('store_debt_item')->alias('i')
            ->leftJoin('user_card_holder h', 'h.oid=i.order_id AND h.product_id=i.product_id')
            ->whereIn('i.debt_id', $debtIds)
            ->field('i.debt_id,i.product_name,h.card_name AS card_name_snapshot')
            ->order('i.id asc')->select());
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['debt_id'] ?? 0);
            $name = trim((string)($row['product_name'] ?? ''));
            if ($name === '历史卡项欠款' || $name === 'SWXT历史卡项欠款') {
                $snapshot = trim((string)($row['card_name_snapshot'] ?? ''));
                if ($snapshot !== '') {
                    $name = $snapshot;
                }
            }
            if ($id > 0 && $name !== '') {
                $out[$id][] = $name;
            }
        }
        return $out;
    }

    private function summary(array $items): string
    {
        $items = array_values(array_unique(array_filter(array_map('strval', $items))));
        if (!$items) {
            return '销售订单欠款';
        }
        $head = array_slice($items, 0, 2);
        return implode('、', $head) . (count($items) > 2 ? '等' : '');
    }

    private function pendingTotal(array $rows): string
    {
        $total = '0.00';
        foreach ($rows as $row) {
            $pending = bcsub($this->money($row['total_debt'] ?? '0.00'), $this->money($row['repaid_debt'] ?? '0.00'), 2);
            if (bccomp($pending, '0.00', 2) > 0) {
                $total = bcadd($total, $pending, 2);
            }
        }
        return $total;
    }

    private function money($value): string
    {
        $raw = trim((string)$value);
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员欠款金额资料异常，请联系管理员核对。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        return bcadd($raw, '0', 2);
    }

    private function activeMember(array $row): bool
    {
        if ((int)($row['uid'] ?? 0) <= 0 || (int)($row['status'] ?? 0) !== 1 || (int)($row['is_del'] ?? 1) !== 0) {
            return false;
        }
        $deleted = $row['delete_time'] ?? null;
        return $deleted === null || $deleted === '' || (string)$deleted === '0' || (string)$deleted === '0000-00-00 00:00:00';
    }

    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }
}
