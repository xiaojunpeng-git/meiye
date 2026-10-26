<?php

namespace app\services\mobile\customer;

use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\card\CashierV3HistoricalEntitlementReadServices;
use think\facade\Db;

/**
 * Read-only asset drill-downs for the mobile member detail.
 *
 * Each detail keeps its own authority: current card rights, V3 immutable
 * balance-change facts plus imported display-only old history, and debt authority. This class never derives a ledger
 * from the member balance summary and never mutates a financial resource.
 */
final class MobileCustomerAssetRecordServices
{
    private const TENANT_ID = '0';
    private const MAX_PAGE_SIZE = 30;
    private const TYPES = ['entitlements', 'balance_changes', 'debts'];

    /** @var MobileCustomerQueryServices */
    private $customers;

    public function __construct(MobileCustomerQueryServices $customers)
    {
        $this->customers = $customers;
    }

    public function page(array $merchantContext, array $payload): array
    {
        $memberId = (int)($payload['memberId'] ?? 0);
        $type = (string)($payload['detailType'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new \think\exception\ValidateException('资产明细类型无效。');
        }
        $this->customers->assertMemberVisible($merchantContext, $memberId);
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int)($payload['pageSize'] ?? $payload['limit'] ?? 20)));
        $storeIds = $this->scopeStoreIds($merchantContext);
        if ($type === 'entitlements') {
            return $this->entitlements($memberId, $storeIds, (int)$merchantContext['storeId'], $page, $pageSize);
        }
        if ($type === 'balance_changes') {
            // Older mini programs do not understand missing principal/gift
            // splits. Require an explicit new-client capability before old
            // display rows can enter their balance response.
            return $this->balanceChanges($memberId, $storeIds, $page, $pageSize,
                ($payload['acceptLegacyBalanceHistory'] ?? false) === true);
        }
        return $this->debts($memberId, $storeIds, $page, $pageSize);
    }

    /** @return int[] */
    private function scopeStoreIds(array $merchantContext): array
    {
        if ((string)$merchantContext['dataScopeMode'] === 'PERSONAL_SELF') {
            return [(int)$merchantContext['storeId']];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($merchantContext['visibleStoreIds'] ?? [])))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function entitlements(int $memberId, array $storeIds, int $operatorStoreId, int $page, int $pageSize): array
    {
        if ($storeIds === []) return $this->pagePayload('entitlements', $memberId, [], 0, $page, $pageSize, 'CURRENT_CARD_ENTITLEMENT');
        $now = time();
        $holders = Db::name('user_card_holder')->alias('h')
            ->join('store_order o', 'o.id = h.oid')
            ->where('h.uid', $memberId)->whereIn('h.store_id', $storeIds)
            ->where('h.is_del', 0)->where('h.write_surplus_times', '>', 0)
            ->where('o.uid', $memberId)->where('o.paid', 1)
            ->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.is_user_del', 0)
            ->where('o.refund_status', 0)->where('o.terminal_action', 0)
            ->where('o.card_upgrade_use_oid', 0)
            ->field('h.id,h.oid,h.card_name,h.card_no,h.store_id,h.write_start,h.write_end,h.add_time')
            ->order('h.id', 'asc')->select()->toArray();
        $eligible = [];
        foreach ($holders as $holder) {
            $start = max(0, (int)($holder['write_start'] ?? 0));
            $end = max(0, (int)($holder['write_end'] ?? 0));
            if (($start > 0 && $start > $now) || ($end > 0 && $end < $now)) continue;
            $eligible[(int)$holder['id']] = $holder;
        }
        // Storeless migration carriers are a member asset only when the
        // authenticated operating store belongs to the owning organization.
        // Keep ordinary cards under their existing visible-store filter.
        $imported = (new CashierV3HistoricalEntitlementReadServices())->activeHolders(
            [$memberId], self::TENANT_ID,
            in_array($operatorStoreId, $storeIds, true) ? $operatorStoreId : 0
        );
        foreach ($imported as $id => $holder) $eligible[(int)$id] = $holder;
        if ($eligible === []) return $this->pagePayload('entitlements', $memberId, [], 0, $page, $pageSize, 'CURRENT_CARD_ENTITLEMENT');
        $orderIds = array_values(array_unique(array_map(static function (array $holder): int { return (int)$holder['oid']; }, $eligible)));
        $projectsByOrder = [];
        $rows = Db::name('store_order_cart_info')->whereIn('oid', $orderIds)
            ->where('cart_type', 2)->where('product_type', 6)->where('is_writeoff', 0)
            ->where('write_surplus_times', '>', 0)
            ->field('id,oid,cart_info,write_times,write_surplus_times,write_start,write_end,pay_price')
            ->order('oid', 'asc')->order('id', 'asc')->select()->toArray();
        foreach ($rows as $row) {
            $start = max(0, (int)($row['write_start'] ?? 0));
            $end = max(0, (int)($row['write_end'] ?? 0));
            $total = max(0, (int)($row['write_times'] ?? 0));
            $remaining = max(0, (int)($row['write_surplus_times'] ?? 0));
            if ($total <= 0 || $remaining <= 0 || $remaining > $total || ($start > 0 && $start > $now) || ($end > 0 && $end < $now)) continue;
            $projectsByOrder[(int)$row['oid']][] = [
                'id' => (string)($row['id'] ?? ''),
                'projectName' => $this->projectName($row['cart_info'] ?? ''),
                'purchaseTimes' => $total,
                'usedTimes' => $total - $remaining,
                'remainingTimes' => $remaining,
                'remainingAmount' => CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                    $this->money($row['pay_price'] ?? 0),
                    $total,
                    $total - $remaining,
                    is_array($row['cart_info'] ?? null)
                        ? $row['cart_info']
                        : (is_string($row['cart_info'] ?? null) ? (json_decode((string)$row['cart_info'], true) ?: []) : [])
                ),
            ];
        }
        $stores = Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id');
        $records = [];
        foreach ($eligible as $holder) {
            $projects = $projectsByOrder[(int)$holder['oid']] ?? [];
            if ($projects === []) continue;
            $remainingTimes = 0;
            $remainingAmount = '0.00';
            foreach ($projects as $project) {
                $remainingTimes += (int)$project['remainingTimes'];
                $remainingAmount = bcadd($remainingAmount, (string)$project['remainingAmount'], 2);
            }
            // Choice-count migration projects are alternatives in one pool.
            // Their component rows must never be added as separate grants.
            if (isset($imported[(int)$holder['id']])) {
                $remainingTimes = (int)$holder['write_surplus_times'];
            }
            $records[] = [
                'id' => 'holder:' . (int)$holder['id'],
                'cardName' => trim((string)$holder['card_name']) ?: '会员卡项',
                'cardNo' => (string)($holder['card_no'] ?? ''),
                'statusLabel' => '有效',
                'storeName' => (string)($stores[(int)$holder['store_id']] ?? ''),
                'openedAt' => $this->dateTime((int)($holder['add_time'] ?? 0)),
                'expiresAt' => $this->dateTime((int)($holder['write_end'] ?? 0), '长期有效'),
                'remainingTimes' => $remainingTimes,
                'remainingAmount' => $remainingAmount,
                'projects' => $projects,
            ];
        }
        return $this->slice('entitlements', $memberId, $records, $page, $pageSize, 'CURRENT_CARD_ENTITLEMENT');
    }

    private function balanceChanges(int $memberId, array $storeIds, int $page, int $pageSize, bool $includeLegacy): array
    {
        $authority = $includeLegacy ? 'CASHIER_V3_BALANCE_FACT_AND_IMPORTED_LEGACY_MONEY' : 'CASHIER_V3_BALANCE_FACT';
        $current = Db::name('cashier_v3_balance_fact')->alias('bf')
            ->where('bf.tenant_id', self::TENANT_ID)->where('bf.member_id', $memberId)
            ->where('bf.fact_type', 'balance_changed')->where('bf.status', 'effective');
        // Current financial facts retain merchant store scope. Old balance was
        // shared across stores, so a visible member grants all their imported
        // historical rows; it never grants another member or a spendable asset.
        if ($storeIds === []) $current->whereRaw('1 = 0');
        else $current->whereIn('bf.store_id', $storeIds);
        $legacyTotal = $includeLegacy ? (int)Db::name('mobile_customer_legacy_balance_record')
            ->where('source_system', 'morestore')->where('member_id', $memberId)->count() : 0;
        $total = (int)(clone $current)->count('bf.id') + $legacyTotal;
        $rows = $total > 0 ? $this->mergedBalancePage($memberId, $storeIds, $page, $pageSize, $includeLegacy) : [];
        $records = array_map(function (array $row): array {
            if ((int)$row['origin'] === 1) {
                // Old money rows do not reliably split principal and gifts.
                // The recorded after-value belongs to that old account/time.
                return [
                    'id' => 'legacy-balance:' . (int)$row['row_id'],
                    'changeNo' => 'legacy:' . (int)$row['source_money_id'],
                    'businessDate' => date('Y-m-d', (int)$row['event_at']),
                    'occurredAt' => $this->dateTime((int)$row['event_at']),
                    'typeLabel' => trim((string)$row['title_snapshot']) ?: (string)$row['source_type'],
                    'changeAmount' => ((int)$row['pm'] === 0 ? '-' : '') . (string)$row['amount_yuan'],
                    'balanceAfter' => (string)$row['balance_after_yuan'],
                    'balanceSplitKnown' => false,
                    'sourceReference' => (string)$row['mark_snapshot'],
                    'sourceTypeLabel' => '',
                    'storeName' => (string)$row['store_name_snapshot'],
                    'operatorName' => '',
                    'statusLabel' => '已生效',
                ];
            }
            $principalDelta = (int)$row['principal_delta_cents'];
            $bonusDelta = (int)$row['bonus_delta_cents'];
            $principalAfter = max(0, (int)$row['principal_after_cents']);
            $bonusAfter = max(0, (int)$row['bonus_after_cents']);
            return [
                'id' => 'balance:' . (int)$row['row_id'],
                'changeNo' => (string)$row['fact_id'],
                'businessDate' => (string)$row['business_date'],
                'occurredAt' => $this->dateTime((int)$row['event_at']),
                'typeLabel' => $this->balanceTypeLabel((string)$row['balance_change_type']),
                'changeAmount' => $this->cents($principalDelta + $bonusDelta),
                'principalDelta' => $this->cents($principalDelta),
                'bonusDelta' => $this->cents($bonusDelta),
                'principalAfter' => $this->cents($principalAfter),
                'bonusAfter' => $this->cents($bonusAfter),
                'balanceAfter' => $this->cents($principalAfter + $bonusAfter),
                'balanceSplitKnown' => true,
                'sourceReference' => (string)$row['order_no_snapshot'],
                'sourceTypeLabel' => $this->sourceDocumentLabel((string)$row['source_document_type']),
                'storeName' => (string)$row['store_name_snapshot'],
                'operatorName' => (string)$row['operator_name_snapshot'],
                'statusLabel' => '已生效',
            ];
        }, $rows);
        return $this->pagePayload('balance_changes', $memberId, $records, $total, $page, $pageSize, $authority);
    }

    /** Sort the union before pagination so old and new events cannot disappear at a page boundary. */
    private function mergedBalancePage(int $memberId, array $storeIds, int $page, int $pageSize, bool $includeLegacy): array
    {
        $prefix = (string)Db::connect()->getConfig('prefix');
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) throw new \RuntimeException('Invalid database table prefix');
        $stores = array_values(array_filter(array_map('intval', $storeIds), static function (int $id): bool { return $id > 0; }));
        $storeCondition = $stores === [] ? '1=0' : 'bf.store_id IN (' . implode(',', $stores) . ')';
        $offset = ($page - 1) * $pageSize;
        // Every interpolated number is server-derived and cast to a positive integer.
        $sql = 'SELECT * FROM ('
            . 'SELECT 0 origin,bf.id row_id,COALESCE(NULLIF(bf.settled_at,0),bf.occurred_at) event_at,'
            . 'bf.fact_id,bf.business_date,bf.balance_change_type,bf.principal_delta_cents,'
            . 'bf.bonus_delta_cents,bf.principal_after_cents,bf.bonus_after_cents,'
            . 'bf.order_no_snapshot,bf.source_document_type,bf.store_name_snapshot,bf.operator_name_snapshot,'
            . "0 source_money_id,'' source_type,'' title_snapshot,'' mark_snapshot,0 pm,"
            . '0 amount_yuan,0 balance_after_yuan '
            . 'FROM ' . $prefix . 'cashier_v3_balance_fact bf '
            . "WHERE bf.tenant_id='0' AND bf.member_id={$memberId} "
            . "AND bf.fact_type='balance_changed' AND bf.status='effective' AND {$storeCondition} ";
        if ($includeLegacy) $sql .= 'UNION ALL '
            . 'SELECT 1 origin,l.id row_id,l.occurred_at event_at,'
            . "'' fact_id,'' business_date,'' balance_change_type,0 principal_delta_cents,"
            . '0 bonus_delta_cents,0 principal_after_cents,0 bonus_after_cents,'
            . "'' order_no_snapshot,'' source_document_type,l.store_name_snapshot,"
            . "'' operator_name_snapshot,l.source_money_id,l.source_type,l.title_snapshot,"
            . 'l.mark_snapshot,l.pm,l.amount_yuan,l.balance_after_yuan '
            . 'FROM ' . $prefix . 'mobile_customer_legacy_balance_record l '
            . "WHERE l.source_system='morestore' AND l.member_id={$memberId}";
        $sql .= ") merged ORDER BY event_at DESC,origin ASC,row_id DESC LIMIT {$offset},{$pageSize}";
        return Db::query($sql);
    }

    private function debts(int $memberId, array $storeIds, int $page, int $pageSize): array
    {
        if ($storeIds === []) return $this->pagePayload('debts', $memberId, [], 0, $page, $pageSize, 'STORE_DEBT');
        $rows = Db::name('store_debt')->alias('d')
            ->leftJoin('cashier_v3_recharge_debt_authority r', 'r.debt_id=d.id')
            ->leftJoin('store_order o', 'o.id=d.order_id')
            ->leftJoin('system_store s', 's.id=d.store_id')
            ->leftJoin('system_store_staff st', 'st.id=d.staff_id')
            ->where('d.uid', $memberId)->whereIn('d.store_id', $storeIds)
            ->field('d.id,d.debt_no,d.order_id,d.order_sn,d.store_id,d.total_debt,d.repaid_debt,d.status,d.remark,d.add_time,d.update_time,r.store_id AS authority_store_id,r.recharge_id,r.recharge_order_no_snapshot,o.uid AS order_member_id,s.name AS store_name,st.staff_name')
            ->order('d.add_time', 'desc')->order('d.id', 'desc')->select()->toArray();
        $debtIds = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
        $itemsByDebt = $this->debtItems($debtIds);
        $records = [];
        foreach ($rows as $row) {
            $debtId = (int)($row['id'] ?? 0);
            $isRecharge = (int)($row['order_id'] ?? -1) === 0 && (int)($row['authority_store_id'] ?? 0) === (int)($row['store_id'] ?? 0) && (int)($row['recharge_id'] ?? 0) > 0;
            $isSale = (int)($row['order_id'] ?? 0) > 0 && (int)($row['order_member_id'] ?? 0) === $memberId;
            if (!$isRecharge && !$isSale) continue;
            $original = $this->money($row['total_debt'] ?? 0);
            $repaid = $this->money($row['repaid_debt'] ?? 0);
            $remaining = bcsub($original, $repaid, 2);
            if (bccomp($remaining, '0.00', 2) < 0) $remaining = '0.00';
            $records[] = [
                'id' => 'debt:' . $debtId,
                'debtNo' => (string)($row['debt_no'] ?? ''),
                'businessDate' => date('Y-m-d', max(0, (int)($row['add_time'] ?? 0))),
                'sourceLabel' => $isRecharge ? '充值欠款' : '销售订单欠款',
                'sourceOrderNo' => $isRecharge ? (string)($row['recharge_order_no_snapshot'] ?? $row['order_sn'] ?? '') : (string)($row['order_sn'] ?? ''),
                'summary' => $isRecharge ? '储值充值' : $this->debtSummary($itemsByDebt[$debtId] ?? []),
                'originalDebtAmount' => $original,
                'repaidAmount' => $repaid,
                'remainingDebtAmount' => $remaining,
                'statusLabel' => bccomp($remaining, '0.00', 2) > 0 ? '待补交' : '已结清',
                'storeName' => (string)($row['store_name'] ?? ''),
                'operatorName' => (string)($row['staff_name'] ?? ''),
                'remark' => (string)($row['remark'] ?? ''),
            ];
        }
        return $this->slice('debts', $memberId, $records, $page, $pageSize, 'STORE_DEBT');
    }

    private function debtItems(array $debtIds): array
    {
        if ($debtIds === []) return [];
        $rows = Db::name('store_debt_item')->whereIn('debt_id', $debtIds)->field('debt_id,product_name')->order('id', 'asc')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $id = (int)($row['debt_id'] ?? 0);
            $name = trim((string)($row['product_name'] ?? ''));
            if ($id > 0 && $name !== '') $result[$id][] = $name;
        }
        return $result;
    }

    private function debtSummary(array $items): string
    {
        $items = array_values(array_unique(array_filter(array_map('strval', $items))));
        if ($items === []) return '销售订单欠款';
        $head = array_slice($items, 0, 2);
        return implode('、', $head) . (count($items) > 2 ? '等' : '');
    }

    private function projectName($cartInfo): string
    {
        $data = is_array($cartInfo) ? $cartInfo : json_decode((string)$cartInfo, true);
        $name = trim((string)($data['productInfo']['store_name'] ?? ''));
        return $name !== '' ? $name : '项目';
    }

    private function balanceTypeLabel(string $type): string
    {
        $labels = [
            'recharge_credit' => '储值充值', 'order_payment' => '余额消费',
            'recharge_debt_repayment_credit' => '欠款补交到账', 'refund_credit' => '退款返还',
            'manual_adjustment' => '人工调整',
        ];
        return $labels[$type] ?? '余额变动';
    }

    private function sourceDocumentLabel(string $type): string
    {
        $labels = ['recharge' => '充值单', 'order' => '销售单', 'debt_repayment' => '欠款补交单', 'recharge_debt_repayment' => '欠款补交单'];
        return $labels[$type] ?? '业务单据';
    }

    private function slice(string $detailType, int $memberId, array $records, int $page, int $pageSize, string $authority): array
    {
        $total = count($records);
        return $this->pagePayload($detailType, $memberId, array_slice($records, ($page - 1) * $pageSize, $pageSize), $total, $page, $pageSize, $authority);
    }

    private function pagePayload(string $detailType, int $memberId, array $records, int $total, int $page, int $pageSize, string $authority): array
    {
        return ['detailType' => $detailType, 'memberId' => $memberId, 'records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'hasMore' => $page * $pageSize < $total, 'dataAuthority' => $authority];
    }

    private function money($value): string
    {
        $raw = trim((string)$value);
        return preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/D', $raw) === 1 ? bcadd($raw, '0', 2) : '0.00';
    }

    private function cents(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $absolute = abs($value);
        return $sign . intdiv($absolute, 100) . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function dateTime(int $timestamp, string $empty = ''): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : $empty;
    }
}
