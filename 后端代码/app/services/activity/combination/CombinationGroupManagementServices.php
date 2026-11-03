<?php
declare(strict_types=1);

namespace app\services\activity\combination;

use think\exception\ValidateException;
use think\facade\Db;

/** Management changes group membership only; fake seats never acquire orders, money or rights. */
class CombinationGroupManagementServices
{
    /** One root lock and unique audit make button replay and automatic virtual completion idempotent. */
    public function complete(int $groupId, int $operatorId, bool $automatic = false): array
    {
        // InnoDB can choose either concurrent completion/refund as a deadlock victim.
        // Retry only a rolled-back deadlock; root/audit/issuance guards keep replay idempotent.
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->completeOnce($groupId, $operatorId, $automatic);
            } catch (\Throwable $error) {
                if ($attempt >= 2 || strpos($error->getMessage(), 'Deadlock found') === false) throw $error;
            }
        }
    }

    /** One database attempt; successful group facts survive a later recoverable issuance failure. */
    private function completeOnce(int $groupId, int $operatorId, bool $automatic): array
    {
        $result = Db::transaction(function () use ($groupId, $operatorId, $automatic) {
            $root = Db::name('store_pink')->where('id', $groupId)->lock(true)->find();
            if (!$root || (int)$root['k_id'] !== 0 || (int)$root['uid'] <= 0) throw new ValidateException('拼团不存在或不是团长记录');
            if ((int)$root['status'] === 2) return ['message' => '该团已成团', 'virtual_count' => 0];
            if ((int)$root['status'] !== 1 || (int)$root['is_refund'] !== 0 || (!$automatic && (int)$root['stop_time'] < time()))
                throw new ValidateException('仅进行中的拼团可立即成团');
            $members = Db::name('store_pink')->where('id|k_id', $groupId)->where('is_refund', 0)->order('id')->select()->toArray();
            $eligible = [];
            foreach ($members as $member) {
                if ((int)$member['is_virtual']) { $eligible[] = $member; continue; }
                $order = Db::name('store_order')->where('id', (int)$member['order_id_key'])->lock(true)->find();
                // Do not fill a seat while its current member has a refund in flight: success/failure may still change membership.
                if (!$order || !(int)$order['paid'] || (int)$order['refund_status'] !== 0 || (int)$order['terminal_action'] !== 0)
                    throw new ValidateException('团内有退款或支付状态待处理，请处理完成后再成团');
                $eligible[] = $member;
            }
            $missing = (int)$root['people'] - count($eligible);
            if ($missing < 0 || (int)$root['people'] < 2 || (int)$root['people'] > 1000)
                throw new ValidateException('拼团人数配置异常');
            for ($i = 0; $i < $missing; $i++) {
                Db::name('store_pink')->insert(['uid' => 0, 'nickname' => '虚拟团员', 'avatar' => '',
                    'order_id' => '', 'order_id_key' => 0, 'total_num' => 0, 'total_price' => 0,
                    'cid' => $root['cid'], 'pid' => $root['pid'], 'people' => $root['people'], 'price' => 0,
                    'add_time' => time(), 'stop_time' => time(), 'k_id' => $groupId,
                    'is_tpl' => 1, 'is_refund' => 0, 'status' => 2, 'is_virtual' => 1]);
            }
            Db::name('store_pink')->where('id|k_id', $groupId)->where('is_refund', 0)
                ->update(['status' => 2, 'stop_time' => time()]);
            Db::name('combination_force_completion')->insert(['group_id' => $groupId,
                'operator_id' => $operatorId, 'virtual_count' => $missing, 'created_at' => time()]);
            return ['message' => '拼团成功', 'virtual_count' => $missing];
        });
        // Failed issuance is recoverable from pending receipts. Never roll back a successful group because a notification failed.
        $this->issueCompleted($groupId);
        // 统一奖励服务会剔除虚拟成团；独立失败由持久回执恢复。
        try { (new CombinationLeaderRewardServices())->onGroupCompleted($groupId); }
        catch (\Throwable $error) { \think\facade\Log::warning('团长奖励待重试 gid=' . $groupId); }
        return $result;
    }

    public function issueCompleted(int $groupId): void
    {
        $ids = Db::name('store_pink')->where('id|k_id', $groupId)->where('uid', '>', 0)
            ->where('status', 2)->where('is_refund', 0)->column('order_id_key');
        foreach ($ids as $orderId) (new CombinationFulfillmentServices())->issue((int)$orderId);
    }
}
