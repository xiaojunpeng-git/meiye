<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\message\notice\RoutineTemplateListService;
use think\facade\Db;
use think\facade\Log;

/**
 * Sends 010's own WeChat subscription notifications from committed V3 facts.
 * 007 is a migration reference only; no request or lookup reaches that site.
 * This is deliberately outside checkout's transaction, so notification failure
 * cannot roll back a completed payment or replay its balance deduction.
 */
final class CashierV3CheckoutMemberNotificationServices
{
    public function sendAfterCommit(string $commandKey, array $submission): void
    {
        if ($commandKey === '') return;

        if ($this->enabled('order_writeoff')) {
            $events = Db::name('cashier_v3_business_event')
                ->where('command_idempotency_key', $commandKey)
                ->where('event_type', 'entitlement.writeoff.completed')
                ->field('event_no,member_id,store_id,occurred_at,payload')
                ->select()->toArray();
            $notifier = app()->make(RoutineTemplateListService::class)->setEvent('order_writeoff');
            foreach ($events as $event) {
                try {
                    $this->sendWriteoff($notifier, $event);
                } catch (\Throwable $error) {
                    Log::error('[cashier_v3_writeoff_notification_failed] ' . json_encode([
                        'event_no' => (string)($event['event_no'] ?? ''),
                        'error' => get_class($error),
                    ]));
                }
            }
        }

        if ($this->enabled('yue_change')) {
            // The checkout receipt can omit optional wallet fields after
            // normalization. Query the immutable committed debit instead;
            // the receipt or browser is never authority for a balance notice.
            $facts = Db::name('cashier_v3_balance_fact')
                ->where('command_idempotency_key', $commandKey)
                ->where('balance_change_type', 'order_payment')
                ->where('fact_direction', 'forward')
                ->where('status', 'effective')
                ->field('fact_id,order_id,member_id,store_id,principal_delta_cents,'
                    . 'bonus_delta_cents,principal_after_cents,bonus_after_cents,settled_at')
                ->select()->toArray();
            if (count($facts) === 1) {
                $this->sendBalanceDeduction(
                    app()->make(RoutineTemplateListService::class)->setEvent('yue_change'),
                    $facts[0], $commandKey
                );
            } elseif (count($facts) > 1) {
                Log::warning('[cashier_v3_balance_notification_fact_ambiguous] ' . hash('sha256', $commandKey));
            }
        }
    }

    /** The local notification switch is the final gate for each message. */
    private function enabled(string $mark): bool
    {
        return (int)Db::name('system_notification')
            ->where('mark', $mark)->value('is_routine') === 1;
    }

    /** The remaining rights are read after commit, never from the browser. */
    private function sendWriteoff(RoutineTemplateListService $notifier, array $event): void
    {
        $payload = json_decode((string)($event['payload'] ?? ''), true);
        if (!is_array($payload)) return;
        $memberId = (int)($event['member_id'] ?? 0);
        $detailId = (int)($payload['sourceDetailId'] ?? 0);
        $projectId = (int)($payload['projectId'] ?? 0);
        $quantity = (int)($payload['quantity'] ?? 0);
        if ($memberId <= 0 || $detailId <= 0 || $projectId <= 0 || $quantity <= 0) return;

        $detail = Db::name('store_order_cart_info')->where('id', $detailId)
            ->field('oid,write_surplus_times')->find();
        $projectName = Db::name('store_product')->where('id', $projectId)->value('store_name');
        $storeName = Db::name('system_store')->where('id', (int)$event['store_id'])->value('name');
        if (!$detail || $projectName === null || $storeName === null) {
            Log::warning('[cashier_v3_writeoff_notification_source_missing] ' . (string)$event['event_no']);
            return;
        }
        $notifier->sendTemplate('CIKA_CHUANGE', $memberId, [
            'thing1' => mb_substr((string)$storeName, 0, 20),
            'thing5' => mb_substr((string)$projectName, 0, 20),
            'thing2' => (string)$quantity,
            'number3' => (string)$detail['write_surplus_times'],
            'time4' => date('Y-m-d H:i:s', (int)$event['occurred_at']),
        ], '/pages/admin/writeRecordList/index?id=' . (int)$detail['oid']);
    }

    /** Balance debit amount and remaining balance come from the committed wallet fact. */
    private function sendBalanceDeduction(RoutineTemplateListService $notifier, array $balance, string $commandKey): void
    {
        $memberId = (int)($balance['member_id'] ?? 0);
        $orderId = trim((string)($balance['order_id'] ?? ''));
        $storeId = (int)($balance['store_id'] ?? 0);
        $deductedCents = -(int)$balance['principal_delta_cents'] - (int)$balance['bonus_delta_cents'];
        $afterCents = (int)$balance['principal_after_cents'] + (int)$balance['bonus_after_cents'];
        if ($memberId <= 0 || $orderId === '' || $storeId <= 0 || $deductedCents <= 0 || $afterCents < 0) return;
        $order = Db::name('cashier_v3_sales_order')
            ->where('order_id', $orderId)
            ->where('command_idempotency_key', $commandKey)
            ->where('member_id', $memberId)
            ->where('store_id', $storeId)
            ->where('order_status', 'settled')
            ->where('order_direction', 'forward')
            ->field('order_no,settled_at')->find();
        if (!$order) return;
        $storeName = Db::name('system_store')->where('id', $storeId)->value('name');
        if ($storeName === null) return;
        $notifier->sendTemplate('YUE_CHANGE', $memberId, [
            'thing1' => mb_substr((string)$storeName, 0, 20),
            'amount2' => $this->yuan($deductedCents),
            'amount3' => $this->yuan($afterCents),
            'time4' => date('Y-m-d H:i:s', (int)$balance['settled_at']),
            'character_string12' => (string)$order['order_no'],
        ], '/pages/goods/order_details/index?order_id=' . rawurlencode($orderId));
    }

    private function yuan(int $cents): string
    {
        // Template amounts are display strings; keep the locked wallet's
        // integer-cent value exact without a floating-point conversion.
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
