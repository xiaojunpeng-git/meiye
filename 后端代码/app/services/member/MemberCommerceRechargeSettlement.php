<?php
declare(strict_types=1);
namespace app\services\member;

use app\services\cashier\v3\member\CashierV3RechargeModule;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use think\facade\Db;

/** Confirmed Fuyou collection enters the existing recharge/balance/fact transaction. */
final class MemberCommerceRechargeSettlement
{
    public function settleInTx(array $row, array $snapshot, array $scope, string $transactionId): array
    {
        if ($transactionId === '' || (int)$row['balance_cents'] !== 0 || (int)$row['external_cents'] !== (int)$row['amount_cents']) {
            throw new \RuntimeException('Recharge requires a confirmed external payment');
        }
        $key = MemberCommerceServices::checkoutKey($row['public_id']);
        $recorder = new CashierV3BusinessEventRecorder();
        $execution = $recorder->newExecution('submit-recharge', $key, $scope['operator'], $scope['data'], $row['public_id']);
        $response = (new CashierV3RechargeModule())->submitInTx([
            'action' => 'submit-recharge', 'idempotency_key' => $key,
            'operator_scope' => $scope['operator'], 'data_scope' => $scope['data'],
            'event_recorder' => $recorder, 'event_execution' => $execution,
            'event_contract' => CashierV3ActionManifest::eventContractFor('submit-recharge'),
            'business_source' => $snapshot['businessSource'], 'member_channel' => $row['channel'],
            'member_recharge_terms' => ['principalCents' => (int)$row['amount_cents'],
                'bonusCents' => (int)$snapshot['bonusCents'], 'giftConfig' => $snapshot['giftConfig'] ?? []],
            'payload' => ['memberId' => (int)$row['member_id'],
                'rechargeMode' => (int)$snapshot['packageId'] > 0 ? 'package' : 'custom',
                'rechargePackageId' => (int)$snapshot['packageId'],
                // Cashier recharge commands express amounts in whole yuan;
                // decimal database formatting is not that command contract.
                'principalAmount' => (string)intdiv((int)$row['amount_cents'], 100),
                'bonusAmount' => (string)intdiv((int)$snapshot['bonusCents'], 100),
                'balanceVersion' => (int)Db::name('user')->where('uid', $row['member_id'])->value('balance_version'),
                'businessDate' => $snapshot['businessDate'], 'rechargeRemark' => '会员自助充值 ' . $row['public_id'],
                'paymentMethod' => $snapshot['paymentMethod'], 'collectionReference' => $transactionId]]);
        $recharge = $response['data']['recharge'];
        return ['orderId' => 'RCH:' . (int)$recharge['rechargeId'], 'orderNo' => $recharge['rechargeOrderNo'], 'recharge' => $recharge];
    }
}
