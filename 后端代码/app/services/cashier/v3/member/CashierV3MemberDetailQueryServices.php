<?php

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use think\facade\Db;

/**
 * 会员详情的只读资产投影。
 *
 * 旧卡的当前权益仍以 user_card_holder + store_order_cart_info 为权威源；这里不
 * 创建 V3 影子版本，也不修改任何权益、订单或事实记录。会员列表摘要和详情资产
 * 采用同一套有效订单、有效期和剩余次数过滤，避免出现摘要有次数而资产页为空。
 */
final class CashierV3MemberDetailQueryServices
{
    public function read(
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        if ($memberId <= 0) {
            throw $this->notFound();
        }
        $this->assertVisible($memberId, $dataScope);

        $member = Db::name('user')
            ->where('uid', $memberId)
            ->field('uid,nickname,real_name,phone,bar_code,belong_store_id,now_money,status,is_del,delete_time')
            ->find();
        if (!$this->isActiveMember($member)) {
            throw $this->notFound();
        }

        $storeId = (int)($member['belong_store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = $operatorScope->storeId();
        }
        $cards = $this->cards($memberId);
        $remainingTimes = 0;
        $remainingAmount = '0.00';
        foreach ($cards as $card) {
            $remainingTimes += (int)($card['remainingTimes'] ?? 0);
            $remainingAmount = bcadd($remainingAmount, (string)($card['remainingAmount'] ?? '0.00'), 2);
        }

        $name = trim((string)($member['real_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($member['nickname'] ?? ''));
        }
        $storeName = $storeId > 0
            ? (string)Db::name('system_store')->where('id', $storeId)->value('name')
            : '';
        $balance = $this->money($member['now_money'] ?? 0);

        return [
            'member' => [
                'id' => (string)$memberId,
                'memberId' => $memberId,
                'name' => $name !== '' ? $name : '未命名会员',
                'phone' => (string)($member['phone'] ?? ''),
                'memberNo' => (string)($member['bar_code'] ?? ''),
                'statusLabel' => '正常',
                'storeId' => $storeId,
                'storeName' => $storeName,
                'accountBalance' => $balance,
            ],
            'summary' => [
                'accountBalance' => $balance,
                'cardBenefitAmount' => $remainingAmount,
                'remainingProjectTimes' => $remainingTimes,
                'remainingProjectAmount' => $remainingAmount,
                'activeCardCount' => count($cards),
            ],
            'cards' => $cards,
            'tabStates' => [],
            'dataAsOf' => date('c'),
            'projectionContractVersion' => 'cashier-v3-member-detail-assets-v1',
        ];
    }

    private function assertVisible(int $memberId, CashierV3DataScopeContext $dataScope): void
    {
        if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_ALL) {
            return;
        }
        if ($dataScope->authorizationMode() !== CashierV3DataScopeContext::MODE_STORES) {
            throw $this->notFound();
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', (array)$dataScope->visibleStoreIds()))));
        if (!$storeIds || !Db::name('store_user')
            ->where('uid', $memberId)
            ->where('status', 1)
            ->whereIn('store_id', $storeIds)
            ->value('uid')) {
            throw $this->notFound();
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function cards(int $memberId): array
    {
        $now = time();
        $holders = Db::name('user_card_holder')->alias('h')
            ->join('store_order o', 'o.id = h.oid')
            ->where('h.uid', $memberId)
            ->where('h.is_del', 0)
            ->where('h.store_id', '>', 0)
            ->where('h.write_surplus_times', '>', 0)
            ->where('o.uid', $memberId)
            ->where('o.paid', 1)
            ->where('o.is_del', 0)
            ->where('o.is_system_del', 0)
            ->where('o.is_user_del', 0)
            ->where('o.refund_status', 0)
            ->where('o.terminal_action', 0)
            ->where('o.card_upgrade_use_oid', 0)
            ->field('h.id,h.oid,h.card_name,h.card_no,h.store_id,h.write_start,h.write_end,h.add_time')
            ->order('h.id', 'asc')
            ->select()
            ->toArray();

        $holderByOrder = [];
        foreach ($holders as $holder) {
            $start = max(0, (int)($holder['write_start'] ?? 0));
            $end = max(0, (int)($holder['write_end'] ?? 0));
            if (($start > 0 && $start > $now) || ($end > 0 && $end < $now)) {
                continue;
            }
            $holderByOrder[(int)$holder['oid']] = $holder;
        }
        if (!$holderByOrder) {
            return [];
        }

        $projectsByOrder = [];
        $rows = Db::name('store_order_cart_info')
            ->whereIn('oid', array_keys($holderByOrder))
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->where('is_writeoff', 0)
            ->where('write_surplus_times', '>', 0)
            ->field('id,oid,cart_info,write_times,write_surplus_times,write_start,write_end,pay_price')
            ->order('oid asc,id asc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $start = max(0, (int)($row['write_start'] ?? 0));
            $end = max(0, (int)($row['write_end'] ?? 0));
            $total = max(0, (int)($row['write_times'] ?? 0));
            $remaining = max(0, (int)($row['write_surplus_times'] ?? 0));
            if ($total <= 0 || $remaining <= 0 || $remaining > $total
                || ($start > 0 && $start > $now) || ($end > 0 && $end < $now)) {
                continue;
            }
            $amount = CashierV3EntitlementActualAmountAllocator::remaining(
                $this->money($row['pay_price'] ?? 0),
                $total,
                $total - $remaining
            );
            $projectsByOrder[(int)$row['oid']][] = [
                'id' => (string)($row['id'] ?? ''),
                'projectName' => $this->projectName($row['cart_info'] ?? ''),
                'purchaseTimes' => $total,
                'usedTimes' => $total - $remaining,
                'remainingTimes' => $remaining,
                'remainingAmount' => $amount,
            ];
        }

        $stores = Db::name('system_store')
            ->whereIn('id', array_values(array_unique(array_map(static function (array $holder): int {
                return (int)$holder['store_id'];
            }, $holderByOrder))))
            ->column('name', 'id');
        $cards = [];
        foreach ($holderByOrder as $orderId => $holder) {
            $projects = $projectsByOrder[$orderId] ?? [];
            if (!$projects) {
                continue;
            }
            $remainingTimes = 0;
            $remainingAmount = '0.00';
            foreach ($projects as $project) {
                $remainingTimes += (int)$project['remainingTimes'];
                $remainingAmount = bcadd($remainingAmount, (string)$project['remainingAmount'], 2);
            }
            $cards[] = [
                'id' => (string)($holder['id'] ?? ''),
                'cardNo' => (string)($holder['card_no'] ?? ''),
                'cardName' => trim((string)($holder['card_name'] ?? '')) ?: '会员卡项',
                'statusLabel' => '有效',
                'storeName' => (string)($stores[(int)$holder['store_id']] ?? ''),
                'openedAt' => $this->dateTime($holder['add_time'] ?? 0),
                'expiresAt' => $this->dateTime($holder['write_end'] ?? 0, '长期有效'),
                'remainingTimes' => $remainingTimes,
                'remainingAmount' => $remainingAmount,
                'purchaseBatchNo' => (string)$orderId,
                'projects' => $projects,
            ];
        }
        return $cards;
    }

    private function projectName($cartInfo): string
    {
        $decoded = is_array($cartInfo) ? $cartInfo : json_decode((string)$cartInfo, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $name = trim((string)($decoded['productInfo']['store_name'] ?? ''));
        return $name !== '' ? $name : '项目';
    }

    private function isActiveMember($member): bool
    {
        if (!is_array($member) || (int)($member['uid'] ?? 0) <= 0
            || (int)($member['status'] ?? 0) !== 1 || (int)($member['is_del'] ?? 0) !== 0) {
            return false;
        }
        $deleteTime = $member['delete_time'] ?? null;
        return $deleteTime === null || $deleteTime === '' || $deleteTime === 0 || $deleteTime === '0'
            || $deleteTime === '0000-00-00 00:00:00';
    }

    private function money($value): string
    {
        $raw = trim((string)$value);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/', $raw)) {
            return '0.00';
        }
        return bcadd($raw, '0', 2);
    }

    private function dateTime($value, string $empty = ''): string
    {
        $timestamp = (int)$value;
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : $empty;
    }

    private function notFound(): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::RESOURCE_NOT_FOUND,
            '该会员不存在或当前不可见。',
            CashierV3ResultCode::STATUS_FAILED
        );
    }
}
