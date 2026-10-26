<?php
declare(strict_types=1);

namespace app\jobs\order;

use app\services\message\SystemMessageServices;
use app\services\order\terminal\RefundSideEffectOnceServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Db;
use think\facade\Log;

/**
 * 退款通知投递（与生产者事务解耦，消费侧幂等）
 *
 * - 站内信与 once 同事务落库；失败靠事务回滚，禁止无条件 release(key)
 * - 短信/公众号/小程序为尽力发送（吞异常），不保证全渠道可靠重试
 */
class RefundNoticeJob extends BaseJobs
{
    use QueueTrait;

    public function doJob(array $payload): bool
    {
        $data = $payload['data'] ?? [];
        $order = $payload['order'] ?? [];
        $opNo = trim((string)($payload['operation_no'] ?? $data['operation_no'] ?? $order['terminal_operation_no'] ?? ''));
        $key = trim((string)($payload['idempotency_key'] ?? $order['terminal_refund_notice_key'] ?? ''));
        if ($key === '' && $opNo !== '') {
            $key = $opNo . ':refund_notice';
        }
        $injectFail = (string)($payload['inject_fail_notice'] ?? $data['inject_fail_notice'] ?? '');

        try {
            if ($key === '') {
                event('notice.notice', [['data' => $data, 'order' => $order], 'order_refund']);
                return true;
            }

            /** @var RefundSideEffectOnceServices $once */
            $once = app()->make(RefundSideEffectOnceServices::class);
            if ((int)Db::name('system_message')->where('idempotency_key', $key)->count() > 0
                && $once->exists($key)) {
                return true;
            }

            $ran = $once->runOnce($key, function () use ($data, $order, $key, $injectFail) {
                if ($injectFail === 'business_1062') {
                    // 模拟业务表唯一键冲突：必须向上抛出，不得被当成 once 重复消费
                    throw new \RuntimeException(
                        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'uk_business_row'"
                    );
                }
                $this->saveSystemMessageSync((int)($order['uid'] ?? 0), $data, $key);
                if ($injectFail === 'after_message') {
                    throw new \RuntimeException('inject_fail_notice=after_message');
                }
            }, $opNo, RefundSideEffectOnceServices::STEP_REFUND_NOTICE);

            // 站内信已成功：其它渠道尽力发送（可跳过便于单测）
            $data['terminal_idempotency_key'] = $key;
            $data['operation_no'] = $opNo;
            $order['terminal_refund_notice_key'] = $key;
            $order['terminal_operation_no'] = $opNo;
            $skipChannels = !empty($payload['skip_notice_channels']);
            if (!$skipChannels
                && ($ran || (int)Db::name('system_message')->where('idempotency_key', $key)->count() > 0)) {
                try {
                    event('notice.notice', [['data' => $data, 'order' => $order], 'order_refund']);
                } catch (\Throwable $e) {
                    Log::warning('RefundNoticeJob channel after message: ' . $e->getMessage());
                }
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('RefundNoticeJob fail: ' . $e->getMessage());
            // runOnce 失败已事务回滚 once；禁止再 release，避免删掉并发成功者的 once
            return false;
        }
    }

    protected function saveSystemMessageSync(int $uid, array $data, string $idemKey): void
    {
        if ($uid <= 0) {
            throw new \RuntimeException('refund notice missing uid');
        }
        if ((int)Db::name('system_message')->where('idempotency_key', $idemKey)->count() > 0) {
            return;
        }
        $notice = Db::name('system_notification')->where('mark', 'order_refund')->find();
        if (!$notice) {
            throw new \RuntimeException('order_refund notification missing');
        }
        $title = (string)($notice['system_title'] ?? '退款成功');
        $str = (string)($notice['system_text'] ?? '');
        foreach ($data as $k => $item) {
            if (is_scalar($item)) {
                $str = str_replace(['{' . $k . '}', '{$' . $k . '}'], (string)$item, $str);
                $title = str_replace(['{' . $k . '}', '{$' . $k . '}'], (string)$item, $title);
            }
        }
        /** @var SystemMessageServices $systemMessageServices */
        $systemMessageServices = app()->make(SystemMessageServices::class);
        $systemMessageServices->save([
            'mark' => 'order_refund',
            'uid' => $uid,
            'content' => $str !== '' ? $str : ('订单退款' . (string)($data['refund_price'] ?? '')),
            'title' => $title,
            'type' => 1,
            'add_time' => time(),
            'idempotency_key' => $idemKey,
        ]);
    }
}
