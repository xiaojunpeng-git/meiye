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
            $itemsByDebt = $this->itemsByDebt($debtIds);
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
                $records[] = [
                    'id' => (string)$debtId,
                    'debtId' => $debtId,
                    'debtNo' => (string)($row['debt_no'] ?? ''),
                    'sourceType' => $isRecharge ? '充值欠款' : '销售订单欠款',
                    'sourceLabel' => $isRecharge ? '充值欠款' : '销售订单欠款',
                    'sourceOrderNo' => $orderNo,
                    'summary' => $isRecharge ? '储值充值' : $this->summary($itemsByDebt[$debtId] ?? []),
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
            ->leftJoin('cashier_v3_debt_authority a', 'a.debt_id=d.id')
            ->leftJoin('cashier_v3_sales_order s', 's.id=a.sales_order_record_id AND s.order_id=a.sales_order_id')
            ->leftJoin('store_order o', 'o.id=d.order_id')
            ->where('d.uid', $memberId)
            ->where('d.status', 0)
            ->field('d.id,d.store_id AS debt_store_id,d.debt_no,d.order_id,d.order_sn,d.total_debt,d.repaid_debt,d.status,d.add_time,d.update_time,d.remark,'
                . 'r.member_id AS recharge_member_id,r.recharge_id,r.recharge_order_no_snapshot,'
                . 'a.store_id AS authority_store_id,a.member_id AS authority_member_id,a.sales_order_record_id,a.sales_order_id,a.sales_order_no_snapshot,'
                . 's.member_id AS v3_order_member_id,o.uid AS order_member_id')
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

    /** @return array<int,array<int,string>> */
    private function itemsByDebt(array $debtIds): array
    {
        if (!$debtIds) {
            return [];
        }
        $rows = $this->rows(Db::name('store_debt_item')->whereIn('debt_id', $debtIds)
            ->field('debt_id,product_name')->order('id asc')->select());
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['debt_id'] ?? 0);
            $name = trim((string)($row['product_name'] ?? ''));
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
