<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * V3 recharge debt must not reuse StoreDebtServices: that service assumes
 * store_debt.order_id identifies eb_store_order. Recharge has no such order.
 */
final class CashierV3RechargeDebtAuthorityServices
{
    public const CONTRACT_VERSION = 'cashier-v3-recharge-debt-authority-v1';
    private const AUTHORITY_TABLE = 'cashier_v3_recharge_debt_authority';

    public function persistInTx(
        array $input,
        int $rechargeId,
        string $rechargeOrderNo,
        string $commandIdempotencyKey,
        int $occurredAt,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('rechargeDebtAuthority.persistInTx');
        $amount = (int)($input['debtCents'] ?? 0);
        if ($amount === 0) {
            return ['debtId' => 0, 'debtNo' => '', 'amountCents' => 0, 'replayed' => false];
        }
        if ($amount < 0 || $rechargeId <= 0 || $rechargeOrderNo === '' || $commandIdempotencyKey === ''
            || $amount > (int)($input['principalCents'] ?? 0) || (int)($input['memberId'] ?? 0) <= 0
            || !$dataScope->allowsStore($operator->storeId())) {
            throw self::failure('recharge_debt_authority_input_invalid');
        }

        $recharge = Db::name('user_recharge')->where('id', $rechargeId)->lock(true)->find();
        if (!$recharge
            || (int)($recharge['uid'] ?? 0) !== (int)$input['memberId']
            || (int)($recharge['store_id'] ?? 0) !== $operator->storeId()
            || (string)($recharge['order_id'] ?? '') !== $rechargeOrderNo
            || self::cents((string)($recharge['debt_amount'] ?? '')) !== $amount) {
            throw self::failure('recharge_debt_recharge_projection_mismatch');
        }

        // Keep existing historical RCD3 records unchanged; a newly persisted
        // recharge debt shares the QK business number stream with sales debt.
        $debtNo = (string)Db::name(self::AUTHORITY_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('recharge_id', $rechargeId)
            ->lock(true)
            ->value('debt_no');
        if ($debtNo === '') {
            $debtNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
                $dataScope->tenantId(),
                CashierV3BusinessDocumentNumberServices::DEBT,
                'recharge_debt',
                (string)$rechargeId,
                date('Y-m-d', $occurredAt),
                $occurredAt
            );
        }
        $expectedDebt = [
            'debt_no' => $debtNo,
            // Zero is an intentional compatibility sentinel. New V3 repayment
            // resolves this debt only through the authority table below.
            'order_id' => 0,
            'order_sn' => $rechargeOrderNo,
            'uid' => (int)$input['memberId'],
            'store_id' => $operator->storeId(),
            'staff_id' => $operator->operatorId(),
            'total_debt' => self::money($amount),
            'repaid_debt' => '0.00',
            'status' => 0,
            'remark' => '收银V3充值欠款',
        ];
        $storedDebt = Db::name('store_debt')->where('debt_no', $debtNo)->lock(true)->find();
        $replayed = false;
        if ($storedDebt) {
            self::assertRow($expectedDebt, (array)$storedDebt, 'recharge_debt_replay_conflict');
            $debtId = (int)$storedDebt['id'];
            $replayed = true;
        } else {
            $debtId = (int)Db::name('store_debt')->insertGetId(array_merge($expectedDebt, [
                'add_time' => $occurredAt,
                'update_time' => $occurredAt,
            ]));
            if ($debtId <= 0) {
                throw self::failure('recharge_debt_insert_failed');
            }
        }

        $expectedItem = [
            'debt_id' => $debtId,
            'order_id' => 0,
            'cart_info_id' => 0,
            'product_id' => 0,
            'product_type' => 0,
            'product_name' => '储值充值',
            'cart_num' => 1,
            'debt_amount' => self::money($amount),
            'repaid_debt' => '0.00',
        ];
        $storedItem = Db::name('store_debt_item')->where('debt_id', $debtId)->lock(true)->find();
        if ($storedItem) {
            self::assertRow($expectedItem, (array)$storedItem, 'recharge_debt_item_replay_conflict');
            $replayed = true;
        } elseif ((int)Db::name('store_debt_item')->insert(array_merge($expectedItem, [
            'add_time' => $occurredAt,
            'update_time' => $occurredAt,
        ])) !== 1) {
            throw self::failure('recharge_debt_item_insert_failed');
        }

        $authority = [
            'debt_id' => $debtId,
            'debt_no' => $debtNo,
            'tenant_id' => $dataScope->tenantId(),
            'store_id' => $operator->storeId(),
            'member_id' => (int)$input['memberId'],
            'recharge_id' => $rechargeId,
            'recharge_order_no_snapshot' => $rechargeOrderNo,
            'command_idempotency_key' => $commandIdempotencyKey,
            'policy_version' => 1,
        ];
        $authority['authority_fingerprint'] = hash('sha256', json_encode($authority, JSON_UNESCAPED_SLASHES));
        $storedAuthority = Db::name(self::AUTHORITY_TABLE)->where('debt_id', $debtId)->lock(true)->find();
        if ($storedAuthority) {
            self::assertRow($authority, (array)$storedAuthority, 'recharge_debt_authority_replay_conflict');
            $replayed = true;
        } elseif ((int)Db::name(self::AUTHORITY_TABLE)->insert(array_merge($authority, [
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ])) !== 1) {
            throw self::failure('recharge_debt_authority_insert_failed');
        }

        return ['debtId' => $debtId, 'debtNo' => $debtNo, 'amountCents' => $amount, 'replayed' => $replayed];
    }

    private static function assertRow(array $expected, array $actual, string $reason): void
    {
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $actual) || (string)$actual[$column] !== (string)$value) {
                throw self::failure($reason, ['column' => $column]);
            }
        }
    }

    private static function cents(string $money): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/D', $money) !== 1) {
            throw self::failure('recharge_debt_money_invalid');
        }
        [$yuan, $fraction] = explode('.', $money, 2);
        return (int)$yuan * 100 + (int)$fraction;
    }

    private static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function failure(string $reason, array $detail = []): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '充值欠款资料不完整，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            array_merge(['reason' => $reason], $detail)
        );
    }
}
