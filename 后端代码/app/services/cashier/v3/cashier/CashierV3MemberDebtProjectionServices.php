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
                $records[] = [
                    'id' => (string)$debtId,
                    'debtId' => $debtId,
                    'debtNo' => (string)($row['debt_no'] ?? ''),
                    'sourceType' => $isRecharge ? '充值欠款' : '销售订单欠款',
                    'sourceLabel' => $isRecharge ? '充值欠款' : '销售订单欠款',
                    'sourceOrderNo' => $orderNo,
                    'originalBusinessSource' => $originalSource,
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
     * Read each debt item's current platform product category for order-center display.
     * Categories are deliberately not frozen on a repayment: platform category changes
     * must be reflected on the next query, while missing/deleted categories stay explicit.
     *
     * @param array<int,int> $debtIds IDs already constrained by the caller's data scope
     * @return array<int,string>
     */
    public function categorySummariesForDebts(array $debtIds): array
    {
        $debtIds = array_values(array_unique(array_filter(array_map('intval', $debtIds))));
        if ($debtIds === []) return [];

        $items = $this->rows(Db::name('store_debt_item')->alias('i')
            ->leftJoin('store_product p', 'p.id=i.product_id AND p.is_del=0')
            ->leftJoin('store_product parent', 'parent.id=p.pid AND parent.type=0 AND parent.is_del=0')
            ->whereIn('i.debt_id', $debtIds)
            ->field('i.debt_id,i.id debt_item_id,p.id product_id,p.cate_id,parent.cate_id parent_cate_id')
            ->order('i.id asc')->select());
        $categories = [];
        foreach ($this->rows(Db::name('store_product_category')
            ->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name')->select()) as $category) {
            $categories[(int)$category['id']] = $category;
        }

        $pathsByDebt = [];
        foreach ($items as $item) {
            $debtId = (int)($item['debt_id'] ?? 0);
            if ($debtId <= 0) continue;
            $rawIds = trim((string)($item['cate_id'] ?? ''));
            if ($rawIds === '') $rawIds = trim((string)($item['parent_cate_id'] ?? ''));
            $itemPaths = [];
            foreach (explode(',', $rawIds) as $rawId) {
                $rawId = trim($rawId);
                if (preg_match('/^[1-9][0-9]*$/D', $rawId) !== 1) continue;
                $path = $this->categoryPath((int)$rawId, $categories);
                if ($path !== '') $itemPaths[$path] = true;
            }
            // One debt can contain several goods. Preserve an unclassified item instead
            // of silently making the classified siblings appear to cover the whole debt.
            if ($itemPaths === []) $pathsByDebt[$debtId]['未分类'] = true;
            else foreach (array_keys($itemPaths) as $path) $pathsByDebt[$debtId][$path] = true;
        }

        $summaries = [];
        foreach ($pathsByDebt as $debtId => $paths) {
            $labels = array_keys($paths);
            // Platform data may store both an ancestor and its descendant. Keep the
            // deepest visible path so the same category is not displayed twice.
            $labels = array_values(array_filter($labels, static function (string $label) use ($labels): bool {
                foreach ($labels as $other) {
                    if ($other !== $label && str_starts_with($other, $label . '/')) return false;
                }
                return true;
            }));
            $summaries[(int)$debtId] = implode('、', array_slice($labels, 0, 2))
                . (count($labels) > 2 ? '等' : '');
        }
        return $summaries;
    }

    /** A broken, cyclic or deleted category chain has no trustworthy display path. */
    private function categoryPath(int $categoryId, array $categories): string
    {
        $names = [];
        $seen = [];
        while ($categoryId > 0 && isset($categories[$categoryId]) && !isset($seen[$categoryId]) && count($names) < 12) {
            $seen[$categoryId] = true;
            $name = trim((string)($categories[$categoryId]['cate_name'] ?? ''));
            if ($name === '') return '';
            $names[] = $name;
            $categoryId = (int)($categories[$categoryId]['pid'] ?? 0);
        }
        return $categoryId === 0 ? implode('/', array_reverse($names)) : '';
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
