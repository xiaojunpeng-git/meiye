<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use think\facade\Db;

/** 收银顶部当前会员的权威摘要；不写会员、权益或欠款事实。 */
final class CashierV3CashierMemberSummaryServices
{
    public function read(int $memberId, int $currentStoreId): array
    {
        $member = Db::name('user')
            ->where('uid', $memberId)
            ->field('uid,nickname,real_name,phone,avatar,bar_code,belong_store_id,now_money,status,is_del,delete_time')
            ->find();
        if (!$this->activeMember($member)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '当前会员不存在或已经停用。',
                CashierV3ResultCode::STATUS_FAILED,
                ['member_id' => $memberId, 'reason' => 'cashier_selected_member_not_active']
            );
        }
        $storeId = (int)($member['belong_store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = $currentStoreId;
        }
        $storeName = (string)Db::name('system_store')->where('id', $storeId)->value('name');
        $name = trim((string)($member['real_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($member['nickname'] ?? ''));
        }
        $accountBalance = $this->money($member['now_money'] ?? 0);
        $cards = $this->cardSummary($memberId);
        $debtAmount = $this->debtAmount($memberId, $currentStoreId);
        $exclusive = $this->exclusiveServiceName($memberId);
        return [
            'id' => (string)$memberId,
            'memberId' => $memberId,
            'name' => $name !== '' ? $name : '未命名会员',
            'phone' => (string)($member['phone'] ?? ''),
            'avatar' => (string)($member['avatar'] ?? ''),
            'memberNo' => (string)($member['bar_code'] ?? ''),
            'storeId' => $storeId,
            'storeName' => $storeName,
            'serviceAdvisorName' => $exclusive,
            'exclusiveStaffName' => $exclusive,
            'accountBalance' => $accountBalance,
            'cardBenefitAmount' => $cards['remainingAmount'],
            'remainingProjectAmount' => $cards['remainingAmount'],
            'remainingTimes' => $cards['remainingTimes'],
            'activeCardCount' => $cards['activeCardCount'],
            'totalAvailableAmount' => bcadd($accountBalance, $cards['remainingAmount'], 2),
            'totalBalanceAmount' => bcadd($accountBalance, $cards['remainingAmount'], 2),
            'outstandingDebtAmount' => $debtAmount,
            'summaryContractVersion' => 'cashier-member-summary-v1',
            'dataAsOf' => date('Y-m-d H:i:s'),
        ];
    }

    private function cardSummary(int $memberId): array
    {
        $holders = $this->rows(Db::name('user_card_holder')
            ->where('uid', $memberId)
            ->where('is_del', 0)
            ->where('store_id', '>', 0)
            ->where('write_surplus_times', '>', 0)
            ->field('id,oid,write_start,write_end')
            ->order('id asc')
            ->select());
        $holderIds = array_values(array_unique(array_filter(array_map('intval', array_column($holders, 'id')))));
        $disabledHolderIds = $this->disabledHolderIds($holderIds, $memberId);
        $timeCardHolderIds = $this->timeCardHolderIds($holderIds, $memberId);
        $holders = array_values(array_filter($holders, static function (array $holder) use ($disabledHolderIds): bool {
            return !isset($disabledHolderIds[(int)($holder['id'] ?? 0)]);
        }));
        $orderIds = array_values(array_unique(array_filter(array_map('intval', array_column($holders, 'oid')))));
        if (!$orderIds) {
            return ['activeCardCount' => 0, 'remainingTimes' => 0, 'remainingAmount' => '0.00'];
        }
        $validOrders = $this->rows(Db::name('store_order')
            ->whereIn('id', $orderIds)
            ->where('uid', $memberId)
            ->where('paid', 1)
            ->where('is_del', 0)
            ->where('is_system_del', 0)
            ->where('is_user_del', 0)
            ->where('refund_status', 0)
            ->where('terminal_action', 0)
            ->where('card_upgrade_use_oid', 0)
            ->field('id')
            ->select());
        $validOrderIds = array_values(array_unique(array_map('intval', array_column($validOrders, 'id'))));
        if (!$validOrderIds) {
            return ['activeCardCount' => 0, 'remainingTimes' => 0, 'remainingAmount' => '0.00'];
        }
        $now = time();
        $holderOrderIds = [];
        $holderIdByOrder = [];
        foreach ($holders as $holder) {
            $oid = (int)($holder['oid'] ?? 0);
            $start = max(0, (int)($holder['write_start'] ?? 0));
            $end = max(0, (int)($holder['write_end'] ?? 0));
            if (in_array($oid, $validOrderIds, true)
                && ($start === 0 || $start <= $now)
                && ($end === 0 || $end >= $now)) {
                $holderOrderIds[$oid] = true;
                $holderIdByOrder[$oid] = (int)($holder['id'] ?? 0);
            }
        }
        if (!$holderOrderIds) {
            return ['activeCardCount' => 0, 'remainingTimes' => 0, 'remainingAmount' => '0.00'];
        }
        $remainingTimes = 0;
        $remainingAmount = '0.00';
        $activeOrders = [];
        $carts = $this->rows(Db::name('store_order_cart_info')
            ->whereIn('oid', array_keys($holderOrderIds))
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->where('is_writeoff', 0)
            ->where('write_surplus_times', '>', 0)
            ->field('oid,write_times,write_surplus_times,write_start,write_end,pay_price')
            ->order('oid asc,id asc')
            ->select());
        foreach ($carts as $cart) {
            $start = max(0, (int)($cart['write_start'] ?? 0));
            $end = max(0, (int)($cart['write_end'] ?? 0));
            $total = (int)($cart['write_times'] ?? 0);
            $remaining = (int)($cart['write_surplus_times'] ?? 0);
            if ($total <= 0 || $remaining <= 0 || $remaining > $total
                || ($start > 0 && $start > $now) || ($end > 0 && $end < $now)) {
                continue;
            }
            if (!isset($timeCardHolderIds[(int)($holderIdByOrder[(int)$cart['oid']] ?? 0)])) {
                $remainingTimes += $remaining;
            }
            $consumed = $total - $remaining;
            try {
                $remainingAmount = bcadd(
                    $remainingAmount,
                    CashierV3EntitlementActualAmountAllocator::remaining(
                        $this->money($cart['pay_price'] ?? 0),
                        $total,
                        $consumed
                    ),
                    2
                );
            } catch (\InvalidArgumentException $exception) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '会员卡项金额资料异常，请联系管理员核对。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'cashier_member_card_amount_invalid']
                );
            }
            $activeOrders[(int)$cart['oid']] = true;
        }
        return [
            'activeCardCount' => count($activeOrders),
            'remainingTimes' => $remainingTimes,
            'remainingAmount' => $remainingAmount,
        ];
    }

    /** @param array<int,int> $holderIds @return array<int,bool> */
    private function disabledHolderIds(array $holderIds, int $memberId): array
    {
        if (!$holderIds) return [];
        $ids = Db::name('cashier_v3_card_state')
            ->whereIn('card_holder_id', $holderIds)
            ->where('current_member_id', $memberId)
            ->where('card_status', 'disabled')
            ->column('card_holder_id');
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** @param array<int,int> $holderIds @return array<int,bool> */
    private function timeCardHolderIds(array $holderIds, int $memberId): array
    {
        if (!$holderIds) return [];
        $ids = Db::name('cashier_v3_card_rule_state')
            ->whereIn('card_holder_id', $holderIds)
            ->where('member_id', $memberId)
            ->where('rule_type', 'time')
            ->column('card_holder_id');
        return array_fill_keys(array_map('intval', $ids), true);
    }

    private function debtAmount(int $memberId, int $storeId): string
    {
        return (new CashierV3MemberDebtProjectionServices())->amountForMember($memberId, $storeId);
    }

    private function exclusiveServiceName(int $memberId): string
    {
        try {
            return trim((string)Db::name('member_exclusive_service')
                ->where('member_id', $memberId)
                ->where('status', 1)
                ->value('staff_name'));
        } catch (\Throwable $exception) {
            // 旧会员未建立专属服务人关系时，摘要允许明确为空。
            return '';
        }
    }

    private function activeMember($row): bool
    {
        if (!$row || !is_array($row) || (int)($row['uid'] ?? 0) <= 0
            || (int)($row['status'] ?? 0) !== 1 || (int)($row['is_del'] ?? 0) !== 0) {
            return false;
        }
        $deletedAt = $row['delete_time'] ?? null;
        return $deletedAt === null || $deletedAt === '' || $deletedAt === 0 || $deletedAt === '0'
            || $deletedAt === '0000-00-00 00:00:00';
    }

    private function money($value): string
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员金额资料异常，请联系管理员核对。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_member_money_invalid']
            );
        }
        $raw = trim((string)$value);
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员金额资料异常，请联系管理员核对。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_member_money_invalid']
            );
        }
        return bcadd($raw, '0', 2);
    }

    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }
}
