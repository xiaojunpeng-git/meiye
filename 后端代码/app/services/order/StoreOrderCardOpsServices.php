<?php

namespace app\services\order;

use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\user\UserCardHolder;
use app\services\BaseServices;
use app\services\user\UserServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 卡项订单转让、延期
 */
class StoreOrderCardOpsServices extends BaseServices
{
    /**
     * @param array $order
     * @return array
     */
    public function assertCardOrder(array $order): array
    {
        if ((int)($order['paid'] ?? 0) !== 1) {
            throw new ValidateException('订单未支付');
        }
        if ((int)($order['refund_status'] ?? 0) !== 0 || (int)($order['refund_type'] ?? 0) === 6) {
            throw new ValidateException('订单已退款或已撤销');
        }
        if ((int)($order['card_upgrade_use_oid'] ?? 0) > 0) {
            throw new ValidateException('该卡已用于升级，无法操作');
        }
        $productType = (int)($order['product_type'] ?? 0);
        $holder = UserCardHolder::where('oid', (int)$order['id'])->where('is_del', 0)->find();
        if (!in_array($productType, [4, 5], true) && !$holder) {
            throw new ValidateException('仅卡项订单支持此操作');
        }
        if (!$holder) {
            return [];
        }
        return is_array($holder) ? $holder : $holder->toArray();
    }

    /**
     * 卡转让：变更订单所属客户并写入订单记录
     * @param int $orderId
     * @param int $toUid
     * @param int $managerId
     * @param string $managerType
     */
    public function transfer(int $orderId, int $toUid, int $managerId = 0, string $managerType = 'store'): void
    {
        /** @var StoreOrderServices $orderService */
        $orderService = app()->make(StoreOrderServices::class);
        $order = $orderService->get($orderId);
        if (!$order) {
            throw new ValidateException('订单不存在');
        }
        $order = is_array($order) ? $order : $order->toArray();
        $this->assertCardOrder($order);

        $fromUid = (int)($order['uid'] ?? 0);
        if ($toUid <= 0) {
            throw new ValidateException('请选择受让客户');
        }
        if ($toUid === $fromUid) {
            throw new ValidateException('不能转让给当前客户');
        }

        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $toUser = $userServices->getUserInfo($toUid);
        if (!$toUser) {
            throw new ValidateException('受让客户不存在');
        }
        $toUser = $this->normalizeUser($toUser);
        $toName = $this->formatUserName($toUser);
        $fromName = '';
        if ($fromUid > 0) {
            $fromUser = $userServices->getUserInfo($fromUid);
            $fromName = $fromUser ? $this->formatUserName($this->normalizeUser($fromUser)) : '';
        }

        Db::transaction(function () use ($orderService, $orderId, $toUid, $toUser, $toName, $fromUid, $fromName, $managerId, $managerType) {
            $update = [
                'uid' => $toUid,
                'real_name' => trim((string)($toUser['real_name'] ?? '')) ?: trim((string)($toUser['nickname'] ?? '')),
            ];
            if (!empty($toUser['phone'])) {
                $update['user_phone'] = $toUser['phone'];
            }
            $orderService->update($orderId, $update);
            StoreOrder::where('pid', $orderId)->update(['uid' => $toUid]);
            UserCardHolder::where('oid', $orderId)->where('is_del', 0)->update(['uid' => $toUid]);

            $msg = sprintf('本卡在%s已转让给%s（ID:%d）', date('Y年n月j日'), $toName, $toUid);
            if ($fromName !== '') {
                $msg .= sprintf('（原客户：%s，ID:%d）', $fromName, $fromUid);
            }
            /** @var StoreOrderStatusServices $statusServices */
            $statusServices = app()->make(StoreOrderStatusServices::class);
            $statusServices->saveStatus($orderId, 'card_transfer', ['change_message' => $msg], $managerId, $managerType);
        });
    }

    /**
     * 卡延期：更新有效期（失效状态也可操作）
     * @param int $orderId
     * @param string $writeEndDate Y-m-d
     * @param int $managerId
     * @param string $managerType
     */
    public function extend(int $orderId, string $writeEndDate, int $managerId = 0, string $managerType = 'store'): void
    {
        /** @var StoreOrderServices $orderService */
        $orderService = app()->make(StoreOrderServices::class);
        $order = $orderService->get($orderId);
        if (!$order) {
            throw new ValidateException('订单不存在');
        }
        $order = is_array($order) ? $order : $order->toArray();
        $this->assertCardOrder($order);

        $writeEndDate = trim($writeEndDate);
        if ($writeEndDate === '') {
            throw new ValidateException('请选择有效期');
        }
        $writeEnd = strtotime($writeEndDate . ' 23:59:59');
        if (!$writeEnd) {
            throw new ValidateException('有效期格式不正确');
        }

        Db::transaction(function () use ($orderId, $writeEnd, $managerId, $managerType) {
            StoreOrderCartInfo::where('oid', $orderId)->update(['write_end' => $writeEnd]);
            UserCardHolder::where('oid', $orderId)->where('is_del', 0)->update(['write_end' => $writeEnd]);

            $msg = sprintf('卡有效期已延期至%s', date('Y年n月j日', $writeEnd));
            /** @var StoreOrderStatusServices $statusServices */
            $statusServices = app()->make(StoreOrderStatusServices::class);
            $statusServices->saveStatus($orderId, 'card_extend', ['change_message' => $msg], $managerId, $managerType);
        });
    }

    /**
     * @param array|object $user
     * @return array
     */
    protected function normalizeUser($user): array
    {
        if (is_array($user)) {
            return $user;
        }
        if (is_object($user) && method_exists($user, 'toArray')) {
            return $user->toArray();
        }
        return [];
    }

    /**
     * @param array $user
     * @return string
     */
    protected function formatUserName(array $user): string
    {
        $real = trim((string)($user['real_name'] ?? ''));
        if ($real !== '') {
            return $real;
        }
        $nick = trim((string)($user['nickname'] ?? ''));
        if ($nick !== '') {
            return $nick;
        }
        $phone = trim((string)($user['phone'] ?? ''));
        if ($phone !== '') {
            return $phone;
        }
        return '用户' . (int)($user['uid'] ?? 0);
    }
}
