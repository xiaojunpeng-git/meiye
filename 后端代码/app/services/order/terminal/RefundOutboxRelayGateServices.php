<?php
declare(strict_types=1);

namespace app\services\order\terminal;

use app\command\RefundSideEffectOutboxRelay;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Db;

/**
 * 新退款流程上线硬门禁（站点隔离心跳）。
 * REFUND_TERMINAL_REQUIRE_RELAY=1 时生效。
 */
class RefundOutboxRelayGateServices
{
    public function assertReadyForTerminalRefund(): void
    {
        if (!$this->isRequired()) {
            return;
        }
        try {
            $hb = (int)Cache::get($this->heartbeatKey(), 0);
        } catch (\Throwable $e) {
            throw new ValidateException('退款投递服务状态无法确认，暂不可办理新退款。请稍后重试或联系负责人。');
        }
        if ($hb <= 0 || (time() - $hb) > 60) {
            throw new ValidateException('退款投递服务未运行，暂不可办理新退款。请联系负责人启动退款投递服务。');
        }
        try {
            $oldest = Db::name('refund_side_effect_outbox')
                ->where('dispatch_state', 0)
                ->order('add_time', 'asc')
                ->value('add_time');
        } catch (\Throwable $e) {
            throw new ValidateException('退款投递账本无法访问，暂不可办理新退款。请联系负责人。');
        }
        if ($oldest && (time() - (int)$oldest) > 60) {
            throw new ValidateException('退款投递积压超时，暂不可办理新退款。请检查投递服务与消息队列。');
        }
    }

    public function isRequired(): bool
    {
        $v = (string)env('REFUND_TERMINAL_REQUIRE_RELAY', '0');
        return $v === '1' || strtolower($v) === 'true';
    }

    public function heartbeatKey(): string
    {
        return RefundSideEffectOutboxRelay::heartbeatKey();
    }

    public function siteCode(): string
    {
        return RefundSideEffectOutboxRelay::siteCode();
    }

    /** @return array{pending:int,oldest_age_sec:?int,heartbeat_age_sec:?int,site:string} */
    public function metrics(): array
    {
        $pending = (int)Db::name('refund_side_effect_outbox')->whereIn('dispatch_state', [0, 2])->count();
        $oldest = Db::name('refund_side_effect_outbox')
            ->whereIn('dispatch_state', [0, 2])
            ->order('add_time', 'asc')
            ->value('add_time');
        $hb = 0;
        try {
            $hb = (int)Cache::get($this->heartbeatKey(), 0);
        } catch (\Throwable $e) {
            $hb = 0;
        }
        return [
            'pending' => $pending,
            'oldest_age_sec' => $oldest ? max(0, time() - (int)$oldest) : null,
            'heartbeat_age_sec' => $hb > 0 ? max(0, time() - $hb) : null,
            'site' => $this->siteCode(),
        ];
    }
}
