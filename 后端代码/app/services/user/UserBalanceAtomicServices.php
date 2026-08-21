<?php
declare(strict_types=1);

namespace app\services\user;

use app\services\BaseServices;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/**
 * 会员余额原子变更。余额三字段、流水和订单支付快照必须共用调用方事务。
 */
class UserBalanceAtomicServices extends BaseServices
{
    private const FINGERPRINT_VERSION = 'balance_v1';
    private const MAX_COMPONENT_AMOUNT = '99999999.99';

    private const TYPE_META = [
        'user_recharge' => [
            'ledger_type' => 'user_recharge', 'pm' => 1,
            'title' => '会员储值', 'mark' => '会员储值{%num%}元',
        ],
        'recharge_debt_repayment' => [
            'ledger_type' => 'recharge_debt_repayment', 'pm' => 1,
            'title' => '充值欠款补交', 'mark' => '充值欠款补交{%num%}元',
        ],
        'recharge_debt_void' => [
            'ledger_type' => 'recharge_debt_void', 'pm' => 0,
            'title' => '充值欠款补交作废', 'mark' => '作废充值欠款补交扣回{%num%}元',
        ],
        'pay_product' => [
            'ledger_type' => 'pay_product', 'pm' => 0,
            'title' => '余额支付购买商品', 'mark' => '余额支付{%num%}元购买商品',
        ],
        'pay_combination' => [
            'ledger_type' => 'pay_product', 'pm' => 0,
            'title' => '组合支付使用余额购买商品', 'mark' => '组合支付使用余额支付{%num%}元购买商品',
        ],
        'debt_repay' => [
            'ledger_type' => 'debt_repay', 'pm' => 0,
            'title' => '欠款还款', 'mark' => '欠款还款使用余额支付{%num%}元',
        ],
        'pay_product_refund' => [
            'ledger_type' => 'pay_product_refund', 'pm' => 1,
            'title' => '商品退款', 'mark' => '订单余额退款{%num%}元',
        ],
        'order_void' => [
            'ledger_type' => 'order_void', 'pm' => 1,
            'title' => '订单作废冲正', 'mark' => '订单作废退回余额{%num%}元',
        ],
        'user_recharge_refund' => [
            'ledger_type' => 'recharge_refund', 'pm' => 0,
            'title' => '用户储值退款', 'mark' => '退款扣除用户余额{%num%}元',
        ],
        'user_recharge_void' => [
            'ledger_type' => 'user_recharge_void', 'pm' => 0,
            'title' => '充值作废扣回', 'mark' => '充值作废扣回余额{%num%}元',
        ],
    ];

    public function lockUser(int $uid): array
    {
        if (!$this->isTransactionActive()) {
            throw new ValidateException('会员余额锁必须在数据库事务内使用。');
        }
        if ($uid <= 0) {
            throw $this->invalidOperation();
        }
        $row = Db::name('user')->where('uid', $uid)->lock(true)
            ->field('uid,now_money,ben_money,give_money')
            ->find();
        if (!$row) {
            throw $this->invalidOperation();
        }
        $user = is_array($row) ? $row : $row->toArray();
        $this->assertInvariant($user);
        return [
            'uid' => (int)$user['uid'],
            'now_money' => $this->storedMoney($user['now_money'] ?? null),
            'ben_money' => $this->storedMoney($user['ben_money'] ?? null),
            'give_money' => $this->storedMoney($user['give_money'] ?? null),
        ];
    }

    public function assertInvariant(array $user): void
    {
        $ben = $this->storedMoney($user['ben_money'] ?? $user['ben'] ?? null);
        $give = $this->storedMoney($user['give_money'] ?? $user['give'] ?? null);
        $total = $this->storedMoney($user['now_money'] ?? $user['total'] ?? null, false);
        if (bccomp(bcadd($ben, $give, 2), $total, 2) !== 0) {
            throw new ValidateException('会员余额数据异常，请先核对后再操作。');
        }
    }

    public function deductPreferBen(
        int $uid,
        string $amount,
        string $billType,
        int $linkId,
        string $title = '',
        ?string $idempotencyKey = null
    ): array {
        $amount = $this->inputMoney($amount);
        $meta = $this->typeMeta($billType, 0);
        $key = $this->idempotencyKey($idempotencyKey, false);
        if ($key === null && bccomp($amount, '0.00', 2) > 0) {
            Log::warning('[balance_legacy_no_idempotency] uid=' . $uid . ' type=' . $billType . ' link=' . $linkId);
        }

        return $this->runInTransaction(function () use ($uid, $amount, $billType, $linkId, $title, $key, $meta) {
            $before = $this->lockUser($uid);
            if (bccomp($amount, '0.00', 2) > 0) {
                $this->assertTraceable($linkId);
            }
            $replay = $this->replayIfExists(
                $key,
                $uid,
                $billType,
                $meta,
                $linkId,
                $amount,
                'principal_first_v1',
                null,
                null
            );
            if ($replay !== null) {
                return $replay;
            }
            if (bccomp($amount, '0.00', 2) === 0) {
                return $this->noChangeResult($before);
            }
            if (bccomp($before['now_money'], $amount, 2) < 0) {
                throw new ValidateException('余额不足');
            }
            $paidBen = bccomp($before['ben_money'], $amount, 2) >= 0
                ? $amount
                : $before['ben_money'];
            $paidGive = bcsub($amount, $paidBen, 2);
            if (bccomp($paidGive, $before['give_money'], 2) > 0) {
                throw new ValidateException('余额不足');
            }
            return $this->applyLockedChange(
                $before,
                $this->negative($paidBen),
                $this->negative($paidGive),
                $amount,
                $billType,
                $linkId,
                $title,
                $key,
                'principal_first_v1',
                $meta
            );
        });
    }

    public function creditBenGive(
        int $uid,
        string $ben,
        string $give,
        string $billType,
        int $linkId,
        string $title = '',
        ?string $idempotencyKey = null
    ): array {
        $ben = $this->inputMoney($ben);
        $give = $this->inputMoney($give);
        $total = bcadd($ben, $give, 2);
        $meta = $this->typeMeta($billType, 1);
        $key = $this->idempotencyKey($idempotencyKey, bccomp($total, '0.00', 2) > 0);

        return $this->runInTransaction(function () use ($uid, $ben, $give, $total, $billType, $linkId, $title, $key, $meta) {
            $before = $this->lockUser($uid);
            if (bccomp($total, '0.00', 2) > 0) {
                $this->assertTraceable($linkId);
            }
            $replay = $this->replayIfExists(
                $key,
                $uid,
                $billType,
                $meta,
                $linkId,
                $total,
                'specified_split_v1',
                $ben,
                $give
            );
            if ($replay !== null) {
                return $replay;
            }
            if (bccomp($total, '0.00', 2) === 0) {
                return $this->noChangeResult($before);
            }
            return $this->applyLockedChange(
                $before,
                $ben,
                $give,
                $total,
                $billType,
                $linkId,
                $title,
                $key,
                'specified_split_v1',
                $meta
            );
        });
    }

    public function deductBenGive(
        int $uid,
        string $ben,
        string $give,
        string $billType,
        int $linkId,
        string $title = '',
        ?string $idempotencyKey = null
    ): array {
        $ben = $this->inputMoney($ben);
        $give = $this->inputMoney($give);
        $total = bcadd($ben, $give, 2);
        $meta = $this->typeMeta($billType, 0);
        $key = $this->idempotencyKey($idempotencyKey, bccomp($total, '0.00', 2) > 0);

        return $this->runInTransaction(function () use ($uid, $ben, $give, $total, $billType, $linkId, $title, $key, $meta) {
            $before = $this->lockUser($uid);
            if (bccomp($total, '0.00', 2) > 0) {
                $this->assertTraceable($linkId);
            }
            $changeBen = $this->negative($ben);
            $changeGive = $this->negative($give);
            $replay = $this->replayIfExists(
                $key,
                $uid,
                $billType,
                $meta,
                $linkId,
                $total,
                'specified_split_v1',
                $changeBen,
                $changeGive
            );
            if ($replay !== null) {
                return $replay;
            }
            if (bccomp($total, '0.00', 2) === 0) {
                return $this->noChangeResult($before);
            }
            if (bccomp($before['ben_money'], $ben, 2) < 0) {
                throw new ValidateException('当前可退本金不足，请调整退款本金后再提交。');
            }
            if (bccomp($before['give_money'], $give, 2) < 0) {
                throw new ValidateException('当前可退赠金不足，请调整退款赠金后再提交。');
            }
            return $this->applyLockedChange(
                $before,
                $changeBen,
                $changeGive,
                $total,
                $billType,
                $linkId,
                $title,
                $key,
                'specified_split_v1',
                $meta
            );
        });
    }

    public function writeOrderPaidSnapshot(int $orderId, string $paidBen, string $paidGive): void
    {
        if ($orderId <= 0) {
            throw $this->invalidOperation();
        }
        if (!$this->isTransactionActive()) {
            throw new ValidateException('订单余额支付快照必须与余额变更在同一事务内保存。');
        }
        $paidBen = $this->inputMoney($paidBen);
        $paidGive = $this->inputMoney($paidGive);
        $this->runInTransaction(function () use ($orderId, $paidBen, $paidGive) {
            try {
                $order = Db::name('store_order')->where('id', $orderId)->lock(true)
                    ->field('id,paid_ben_amount,paid_give_amount,paid_balance_ready')
                    ->find();
            } catch (\Throwable $e) {
                throw new ValidateException('订单余额支付快照结构未就绪，已取消本次操作。');
            }
            if (!$order) {
                throw new ValidateException('订单不存在，已取消本次操作。');
            }
            if ((int)($order['paid_balance_ready'] ?? 0) === 1) {
                $existingBen = $this->storedMoney($order['paid_ben_amount'] ?? null);
                $existingGive = $this->storedMoney($order['paid_give_amount'] ?? null);
                if (bccomp($existingBen, $paidBen, 2) === 0 && bccomp($existingGive, $paidGive, 2) === 0) {
                    return;
                }
                throw new ValidateException('订单余额支付快照冲突，已取消本次操作。');
            }
            $affected = Db::name('store_order')->where('id', $orderId)
                ->where('paid_balance_ready', 0)
                ->update([
                    'paid_ben_amount' => $paidBen,
                    'paid_give_amount' => $paidGive,
                    'paid_balance_ready' => 1,
                ]);
            if ((int)$affected !== 1) {
                throw new ValidateException('订单余额支付快照保存失败，已取消本次操作。');
            }
        });
    }

    private function applyLockedChange(
        array $before,
        string $changeBen,
        string $changeGive,
        string $total,
        string $billType,
        int $linkId,
        string $title,
        ?string $idempotencyKey,
        string $strategy,
        array $meta
    ): array {
        $this->assertChangeContract($changeBen, $changeGive, $total, (int)$meta['pm']);
        $fingerprint = $this->fingerprint(
            (int)$before['uid'],
            $billType,
            (string)$meta['ledger_type'],
            $linkId,
            (int)$meta['pm'],
            $total,
            $changeBen,
            $changeGive,
            $strategy
        );

        $afterBen = bcadd($before['ben_money'], $changeBen, 2);
        $afterGive = bcadd($before['give_money'], $changeGive, 2);
        $afterTotal = bcadd($before['now_money'], bcadd($changeBen, $changeGive, 2), 2);
        $this->assertInvariant([
            'ben_money' => $afterBen,
            'give_money' => $afterGive,
            'now_money' => $afterTotal,
        ]);
        $affected = Db::name('user')->where('uid', (int)$before['uid'])->update([
            'ben_money' => $afterBen,
            'give_money' => $afterGive,
            'now_money' => $afterTotal,
        ]);
        if ((int)$affected !== 1) {
            throw new ValidateException('会员余额更新失败，已取消本次操作。');
        }

        try {
            $moneyId = $this->writeLedger(
                (int)$before['uid'],
                $billType,
                $total,
                $afterTotal,
                $linkId,
                $title,
                $afterBen,
                $afterGive,
                $changeBen,
                $changeGive,
                $idempotencyKey,
                $idempotencyKey !== null ? $fingerprint : null,
                $meta
            );
        } catch (\Throwable $e) {
            if ($idempotencyKey !== null && $this->isDuplicateKeyException($e)) {
                throw new ValidateException('余额操作幂等键冲突，已取消本次操作。');
            }
            if ($idempotencyKey !== null && $this->isConcurrencyKeyException($e)) {
                throw new ValidateException('余额操作正在处理中，请使用相同请求稍后重试。');
            }
            throw $e;
        }
        return $this->result(
            $before['ben_money'],
            $before['give_money'],
            $before['now_money'],
            $afterBen,
            $afterGive,
            $afterTotal,
            $changeBen,
            $changeGive,
            $total,
            $moneyId,
            false,
            false
        );
    }

    private function replayIfExists(
        ?string $idempotencyKey,
        int $uid,
        string $billType,
        array $meta,
        int $linkId,
        string $total,
        string $strategy,
        ?string $expectedChangeBen,
        ?string $expectedChangeGive
    ): ?array {
        if ($idempotencyKey === null) {
            return null;
        }
        try {
            // Current read is mandatory: an outer transaction may already hold an older RR snapshot.
            $existing = Db::name('user_money')->where('idempotency_key', $idempotencyKey)->lock(true)->find();
        } catch (\Throwable $e) {
            if ($this->isConcurrencyKeyException($e)) {
                throw new ValidateException('余额操作正在处理中，请使用相同请求稍后重试。');
            }
            throw new ValidateException('余额流水幂等结构未就绪，已取消本次操作。');
        }
        if (!$existing) {
            return null;
        }
        $ledger = is_array($existing) ? $existing : $existing->toArray();
        if (!array_key_exists('idempotency_key', $ledger)
            || !hash_equals((string)$ledger['idempotency_key'], $idempotencyKey)) {
            throw new ValidateException('余额操作幂等键与原业务不一致，已取消本次操作。');
        }

        return $this->replayResult(
            $ledger,
            $uid,
            $billType,
            (string)$meta['ledger_type'],
            $linkId,
            (int)$meta['pm'],
            $total,
            $strategy,
            $expectedChangeBen,
            $expectedChangeGive
        );
    }

    private function replayResult(
        array $ledger,
        int $uid,
        string $billType,
        string $ledgerType,
        int $linkId,
        int $pm,
        string $total,
        string $strategy,
        ?string $expectedChangeBen,
        ?string $expectedChangeGive
    ): array {
        foreach (['ben_change_amount', 'give_change_amount', 'idempotency_fingerprint'] as $requiredColumn) {
            if (!array_key_exists($requiredColumn, $ledger)) {
                throw new ValidateException('余额流水幂等结构未就绪，已取消本次操作。');
            }
        }
        $basicMatches = (int)($ledger['uid'] ?? 0) === $uid
            && (string)($ledger['type'] ?? '') === $ledgerType
            && (string)($ledger['link_id'] ?? '') === (string)$linkId
            && (int)($ledger['pm'] ?? -1) === $pm
            && (int)($ledger['status'] ?? 0) === 1
            && bccomp($this->storedMoney($ledger['number'] ?? null, false), $total, 2) === 0;
        if (!$basicMatches) {
            throw new ValidateException('余额操作幂等键与原业务不一致，已取消本次操作。');
        }

        $storedFingerprint = trim((string)($ledger['idempotency_fingerprint'] ?? ''));
        $legacy = $storedFingerprint === '';
        if ($legacy) {
            Log::warning('[balance_legacy_idempotency_unverifiable] key=' . (string)($ledger['idempotency_key'] ?? '')
                . ' uid=' . $uid . ' type=' . $billType . ' link=' . $linkId);
            throw new ValidateException('旧余额流水缺少权威本赠金分量，无法安全确认原操作。');
        }
        if ($ledger['ben_change_amount'] === null || $ledger['give_change_amount'] === null) {
            throw new ValidateException('余额流水幂等结构不完整，无法安全确认原操作。');
        }

        $changeBen = $ledger['ben_change_amount'] === null
            ? (string)$expectedChangeBen
            : $this->storedSignedMoney($ledger['ben_change_amount']);
        $changeGive = $ledger['give_change_amount'] === null
            ? (string)$expectedChangeGive
            : $this->storedSignedMoney($ledger['give_change_amount']);
        if ($expectedChangeBen !== null && bccomp($changeBen, $expectedChangeBen, 2) !== 0) {
            throw new ValidateException('余额操作幂等键与原业务不一致，已取消本次操作。');
        }
        if ($expectedChangeGive !== null && bccomp($changeGive, $expectedChangeGive, 2) !== 0) {
            throw new ValidateException('余额操作幂等键与原业务不一致，已取消本次操作。');
        }
        $this->assertChangeContract($changeBen, $changeGive, $total, $pm);
        $expectedFingerprint = $this->fingerprint(
            $uid,
            $billType,
            $ledgerType,
            $linkId,
            $pm,
            $total,
            $changeBen,
            $changeGive,
            $strategy
        );
        if (!hash_equals($storedFingerprint, $expectedFingerprint)) {
            throw new ValidateException('余额操作幂等键与原业务不一致，已取消本次操作。');
        }

        $afterBen = $this->storedMoney($ledger['ben_money'] ?? null);
        $afterGive = $this->storedMoney($ledger['give_money'] ?? null);
        $afterTotal = $this->storedMoney($ledger['balance'] ?? null, false);
        $beforeBen = bcsub($afterBen, $changeBen, 2);
        $beforeGive = bcsub($afterGive, $changeGive, 2);
        $beforeTotal = bcsub($afterTotal, bcadd($changeBen, $changeGive, 2), 2);
        $this->assertInvariant(['ben_money' => $beforeBen, 'give_money' => $beforeGive, 'now_money' => $beforeTotal]);
        $this->assertInvariant(['ben_money' => $afterBen, 'give_money' => $afterGive, 'now_money' => $afterTotal]);

        return $this->result(
            $beforeBen,
            $beforeGive,
            $beforeTotal,
            $afterBen,
            $afterGive,
            $afterTotal,
            $changeBen,
            $changeGive,
            $total,
            (int)($ledger['id'] ?? 0),
            true,
            $legacy
        );
    }

    private function writeLedger(
        int $uid,
        string $billType,
        string $number,
        string $balance,
        int $linkId,
        string $title,
        string $afterBen,
        string $afterGive,
        string $changeBen,
        string $changeGive,
        ?string $idempotencyKey,
        ?string $fingerprint,
        array $meta
    ): int {
        $row = [
            'uid' => $uid,
            'link_id' => $linkId,
            'type' => (string)$meta['ledger_type'],
            'title' => $title !== '' ? $title : (string)$meta['title'],
            'number' => $number,
            'balance' => $balance,
            'mark' => str_replace('{%num%}', $number, (string)$meta['mark']),
            'pm' => (int)$meta['pm'],
            'status' => 1,
            'ben_money' => $afterBen,
            'give_money' => $afterGive,
            'ben_change_amount' => $changeBen,
            'give_change_amount' => $changeGive,
            'idempotency_key' => $idempotencyKey,
            'idempotency_fingerprint' => $fingerprint,
            'add_time' => time(),
        ];
        try {
            $id = (int)Db::name('user_money')->insertGetId($row);
        } catch (\Throwable $e) {
            if ($this->isDuplicateKeyException($e) || $this->isConcurrencyKeyException($e)) {
                throw $e;
            }
            throw new ValidateException('余额流水结构未就绪或写入失败，已取消本次操作。');
        }
        if ($id <= 0) {
            throw new ValidateException('余额流水写入失败，已取消本次操作。');
        }
        return $id;
    }

    private function result(
        string $beforeBen,
        string $beforeGive,
        string $beforeTotal,
        string $afterBen,
        string $afterGive,
        string $afterTotal,
        string $changeBen,
        string $changeGive,
        string $total,
        int $moneyId,
        bool $idempotent,
        bool $legacyIdempotent
    ): array {
        return [
            'before' => ['ben' => $beforeBen, 'give' => $beforeGive, 'total' => $beforeTotal],
            'after' => ['ben' => $afterBen, 'give' => $afterGive, 'total' => $afterTotal],
            'change_ben' => $changeBen,
            'change_give' => $changeGive,
            'total' => $total,
            'paid_ben' => bccomp($changeBen, '0.00', 2) < 0 ? $this->absolute($changeBen) : '0.00',
            'paid_give' => bccomp($changeGive, '0.00', 2) < 0 ? $this->absolute($changeGive) : '0.00',
            'money_id' => $moneyId,
            'idempotent' => $idempotent,
            'legacy_idempotent' => $legacyIdempotent,
        ];
    }

    private function noChangeResult(array $user): array
    {
        return $this->result(
            $user['ben_money'], $user['give_money'], $user['now_money'],
            $user['ben_money'], $user['give_money'], $user['now_money'],
            '0.00', '0.00', '0.00', 0, false, false
        );
    }

    private function fingerprint(
        int $uid,
        string $billType,
        string $ledgerType,
        int $linkId,
        int $pm,
        string $total,
        string $changeBen,
        string $changeGive,
        string $strategy
    ): string {
        return hash('sha256', implode('|', [
            self::FINGERPRINT_VERSION,
            (string)$uid,
            $billType,
            $ledgerType,
            (string)$linkId,
            (string)$pm,
            $total,
            $changeBen,
            $changeGive,
            $strategy,
        ]));
    }

    private function typeMeta(string $billType, int $expectedPm): array
    {
        if ($billType !== trim($billType)) {
            throw new ValidateException('余额操作类型无效，已取消本次操作。');
        }
        if (!isset(self::TYPE_META[$billType]) || (int)self::TYPE_META[$billType]['pm'] !== $expectedPm) {
            throw new ValidateException('余额操作类型无效，已取消本次操作。');
        }
        return self::TYPE_META[$billType];
    }

    private function assertChangeContract(string $changeBen, string $changeGive, string $total, int $pm): void
    {
        if ($pm === 1) {
            if (bccomp($changeBen, '0.00', 2) < 0 || bccomp($changeGive, '0.00', 2) < 0) {
                throw new ValidateException('余额流水方向异常，已取消本次操作。');
            }
            $sum = bcadd($changeBen, $changeGive, 2);
        } else {
            if (bccomp($changeBen, '0.00', 2) > 0 || bccomp($changeGive, '0.00', 2) > 0) {
                throw new ValidateException('余额流水方向异常，已取消本次操作。');
            }
            $sum = bcadd($this->absolute($changeBen), $this->absolute($changeGive), 2);
        }
        if (bccomp($sum, $total, 2) !== 0) {
            throw new ValidateException('余额流水本赠金分量与总额不一致，已取消本次操作。');
        }
    }

    private function idempotencyKey(?string $key, bool $required): ?string
    {
        $raw = $key === null ? '' : $key;
        $key = trim($raw);
        if ($key === '') {
            if ($required) {
                throw new ValidateException('余额操作幂等标识缺失，已取消本次操作。');
            }
            return null;
        }
        if ($raw !== $key || strlen($key) > 128 || preg_match('/[\x00-\x20\x7F]/', $key)) {
            throw new ValidateException('余额操作幂等标识无效，已取消本次操作。');
        }
        return $key;
    }

    private function assertTraceable(int $linkId): void
    {
        if ($linkId <= 0) {
            throw new ValidateException('余额变更缺少来源业务，已取消本次操作。');
        }
    }

    private function inputMoney(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/', $value)) {
            throw new ValidateException('金额格式无效，必须为非负且最多两位小数。');
        }
        $normalized = bcadd($value, '0', 2);
        if (bccomp($normalized, self::MAX_COMPONENT_AMOUNT, 2) > 0) {
            throw new ValidateException('金额超出允许范围。');
        }
        return $normalized;
    }

    private function storedMoney($value, bool $component = true): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new ValidateException('会员余额数据异常，请先核对后再操作。');
        }
        $raw = trim((string)$value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) {
            throw new ValidateException('会员余额数据异常，请先核对后再操作。');
        }
        $normalized = bcadd($raw, '0', 2);
        if ($component && bccomp($normalized, self::MAX_COMPONENT_AMOUNT, 2) > 0) {
            throw new ValidateException('会员余额数据异常，请先核对后再操作。');
        }
        return $normalized;
    }

    private function storedSignedMoney($value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new ValidateException('余额流水分量异常，无法安全重放。');
        }
        $raw = trim((string)$value);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $raw)) {
            throw new ValidateException('余额流水分量异常，无法安全重放。');
        }
        return bcadd($raw, '0', 2);
    }

    private function negative(string $value): string
    {
        return bccomp($value, '0.00', 2) === 0 ? '0.00' : bcsub('0.00', $value, 2);
    }

    private function absolute(string $value): string
    {
        return bccomp($value, '0.00', 2) < 0 ? bcsub('0.00', $value, 2) : $value;
    }

    private function isDuplicateKeyException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false
            || strpos($message, '1062') !== false
            || strpos($message, 'unique') !== false;
    }

    private function isConcurrencyKeyException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'deadlock') !== false
            || strpos($message, '1213') !== false
            || strpos($message, 'lock wait timeout') !== false
            || strpos($message, '1205') !== false;
    }

    private function runInTransaction(callable $callback)
    {
        if ($this->isTransactionActive()) {
            return $this->runWithinSavepoint($callback);
        }
        return $this->transaction($callback);
    }

    private function runWithinSavepoint(callable $callback)
    {
        $pdo = Db::getPdo();
        if (!$pdo || !$pdo->inTransaction()) {
            throw new ValidateException('余额事务状态异常，已取消本次操作。');
        }
        $savepoint = 'balance_sp_' . bin2hex(random_bytes(8));
        $pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $result = $callback();
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } catch (\Throwable $rollbackError) {
                try {
                    Db::rollback();
                } catch (\Throwable $ignored) {
                }
                throw new ValidateException('余额事务回滚失败，整笔业务已强制取消，请立即核对。');
            }
            throw $e;
        }
    }

    private function isTransactionActive(): bool
    {
        try {
            $pdo = Db::getPdo();
            return $pdo && $pdo->inTransaction();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function invalidOperation(): ValidateException
    {
        return new ValidateException('操作未成功，订单状态未改变，请核对后重试或联系负责人。');
    }
}
