<?php
declare(strict_types=1);

namespace app\command;

use app\model\order\StoreOrderTerminalOperation;
use app\services\order\StoreOrderRefundDomainServices;
use app\services\order\StoreOrderTerminalOperationServices;
use app\services\order\StoreOrderVoidServices;
use app\services\order\terminal\RefundSideEffectOutboxServices;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Cache;
use think\facade\Db;

/**
 * 退款 Outbox relay + 过期终态自动恢复（上线必启）
 */
class RefundSideEffectOutboxRelay extends Command
{
    public const HEARTBEAT_PREFIX = 'refund_outbox_relay_heartbeat:';

    public static function siteCode(): string
    {
        $fromEnv = trim((string)env('REFUND_SITE_CODE', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        try {
            $db = (string)Db::query('select database() as d')[0]['d'] ?? '';
            if ($db !== '') {
                return $db;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return 'default';
    }

    public static function heartbeatKey(): string
    {
        return self::HEARTBEAT_PREFIX . self::siteCode();
    }

    protected function configure()
    {
        $this->setName('refund:outbox-relay')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '每批最大条数', 100)
            ->addOption('loop', null, Option::VALUE_NONE, '常驻循环扫描')
            ->addOption('sleep', 's', Option::VALUE_OPTIONAL, 'loop 间隔秒', 2)
            ->addOption('operation-no', 'o', Option::VALUE_OPTIONAL, '仅处理指定 operation_no（验收用）', '')
            ->addOption('recover', null, Option::VALUE_NONE, '同时恢复过期 BALANCE/CHANNEL/LOCAL_CLOSING')
            ->setDescription('Relay pending refund side-effect outbox + recover expired terminal ops');
    }

    protected function execute(Input $input, Output $output)
    {
        /** @var RefundSideEffectOutboxServices $outbox */
        $outbox = app()->make(RefundSideEffectOutboxServices::class);
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $limit = (int)$input->getOption('limit');
        $loop = (bool)$input->getOption('loop');
        $sleep = max(1, (int)$input->getOption('sleep'));
        $operationNo = trim((string)$input->getOption('operation-no'));
        $doRecover = (bool)$input->getOption('recover') || $loop;

        do {
            $stat = $outbox->relayPending($limit, $operationNo);
            $recovered = 0;
            if ($doRecover) {
                $recovered = $this->recoverExpired($terminal, $limit, $operationNo, $output);
            }
            $this->touchHeartbeat();
            $pendingAge = $this->oldestPendingAgeSec($operationNo);
            $output->writeln(sprintf(
                '[%s] site=%s scanned=%d dispatched=%d failed=%d recovered=%d oldest_pending_age_sec=%s',
                date('Y-m-d H:i:s'),
                self::siteCode(),
                $stat['scanned'],
                $stat['dispatched'],
                $stat['failed'],
                $recovered,
                $pendingAge === null ? 'n/a' : (string)$pendingAge
            ));
            if ($pendingAge !== null && $pendingAge > 60) {
                $output->writeln('[ALARM] oldest PENDING age > 60s');
            }
            if (!$loop) {
                break;
            }
            sleep($sleep);
        } while (true);

        return 0;
    }

    /**
     * A2-R1：不在此预领租约；owner 原样传入领域 beginOrResume。
     * recovered 仅在状态真正推进或 SUCCESS 时 +1。
     */
    protected function recoverExpired(
        StoreOrderTerminalOperationServices $terminal,
        int $limit,
        string $operationNo,
        Output $output
    ): int {
        $rows = $terminal->listExpiredRecoverable($limit, $operationNo);
        $n = 0;
        /** @var StoreOrderRefundDomainServices $refundDomain */
        $refundDomain = app()->make(StoreOrderRefundDomainServices::class);
        /** @var StoreOrderVoidServices $voidDomain */
        $voidDomain = app()->make(StoreOrderVoidServices::class);
        foreach ($rows as $row) {
            $beforeState = (int)($row['state'] ?? -1);
            $beforeLocal = (int)($row['local_close_done'] ?? 0);
            $beforeSuccess = $beforeState === StoreOrderTerminalOperation::STATE_SUCCESS;
            $owner = $terminal->makeExecutionOwner(['execution_owner' => 'recovery:' . getmypid() . ':' . bin2hex(random_bytes(3))]);
            try {
                $token = (string)($row['request_token'] ?? '');
                $actionType = (int)($row['action_type'] ?? StoreOrderTerminalOperation::ACTION_REFUND);
                $biz = (int)($row['business_type'] ?? StoreOrderTerminalOperation::BUSINESS_ORDER);
                $payload = [
                    'store_scope' => (int)($row['store_id'] ?? 0),
                    'request_token' => $token,
                    'refund_amount' => (string)$row['refund_amount'],
                    'refund_ben' => (string)$row['refund_ben'],
                    'refund_give' => (string)$row['refund_give'],
                    'source_type' => (int)($row['source_type'] ?? 2),
                    'operator_type' => (string)($row['operator_type'] ?? 'store'),
                    'operator_id' => (int)($row['operator_id'] ?? 0),
                    'bookkeeping_confirmed' => (int)($row['bookkeeping_confirmed'] ?? 0),
                    'bookkeeping_remark' => (string)($row['bookkeeping_remark'] ?? ''),
                    'execution_owner' => $owner,
                    'reason' => (string)($row['reason'] ?? ''),
                    'void_reason' => (string)($row['reason'] ?? ''),
                    'link_recharge_id' => (int)($row['link_recharge_id'] ?? 0),
                    'store_order_id' => (int)($row['store_order_id'] ?? 0),
                ];
                // action_type 决定退款/作废编排；business_type 仅区分普通/充值/补交
                if ($actionType === StoreOrderTerminalOperation::ACTION_VOID) {
                    if ((int)$payload['store_order_id'] <= 0) {
                        $output->writeln('[recover_skip] op=' . ($row['operation_no'] ?? '') . ' missing store_order_id for void');
                        continue;
                    }
                    if ($biz === StoreOrderTerminalOperation::BUSINESS_RECHARGE
                        && (int)$payload['link_recharge_id'] <= 0) {
                        $output->writeln('[recover_skip] op=' . ($row['operation_no'] ?? '') . ' missing link_recharge_id for void recharge');
                        continue;
                    }
                    $result = $voidDomain->voidWholeOrder($payload);
                } elseif ($biz === StoreOrderTerminalOperation::BUSINESS_RECHARGE) {
                    $rechargeId = (int)($row['link_recharge_id'] ?? 0);
                    if ($rechargeId <= 0) {
                        $output->writeln('[recover_skip] op=' . ($row['operation_no'] ?? '') . ' missing link_recharge_id');
                        continue;
                    }
                    $result = $refundDomain->refundRecharge($rechargeId, $payload);
                } else {
                    $result = $refundDomain->refundWholeOrder($payload);
                }
                $fresh = Db::name('store_order_terminal_operation')->where('id', (int)$row['id'])->find();
                $afterState = (int)($fresh['state'] ?? -1);
                $afterLocal = (int)($fresh['local_close_done'] ?? 0);
                $progressed = ($afterState === StoreOrderTerminalOperation::STATE_SUCCESS && !$beforeSuccess)
                    || ($afterLocal === 1 && $beforeLocal === 0)
                    || ($afterState !== $beforeState && $afterState === StoreOrderTerminalOperation::STATE_SUCCESS);
                $okFlag = !empty($result['ok']) && (int)($result['state'] ?? -1) === StoreOrderTerminalOperation::STATE_SUCCESS;
                if ($progressed || $okFlag) {
                    $n++;
                }
            } catch (\Throwable $e) {
                $output->writeln('[recover_fail] op=' . ($row['operation_no'] ?? '') . ' ' . $e->getMessage());
            }
        }
        return $n;
    }

    protected function touchHeartbeat(): void
    {
        try {
            Cache::set(self::heartbeatKey(), time(), 300);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    protected function oldestPendingAgeSec(string $operationNo = ''): ?int
    {
        $q = Db::name('refund_side_effect_outbox')
            ->whereIn('dispatch_state', [0, 2])
            ->order('add_time', 'asc');
        if ($operationNo !== '') {
            $q->where('operation_no', $operationNo);
        }
        $row = $q->find();
        if (!$row) {
            return null;
        }
        $add = (int)($row['add_time'] ?? 0);
        return $add > 0 ? max(0, time() - $add) : null;
    }
}
