<?php
declare(strict_types=1);
namespace app\services\cashier\v3\order;

use app\services\member\MemberOrderAccounting;
use think\facade\Db;

/**
 * 兼容仍通过 store_order 创建的会员端活动订单。只读原付款凭据并展示冻结的记账分类，
 * 不创建 V3 销售/业绩事实，不把投影变成操作授权；历史无标记订单完全不变。
 */
final class CashierV3MemberOrderAccountingProjection
{
    /** 入参必须已经过原订单 Reader 的组织授权和分页，禁止独立扩大订单集合。 */
    public function attach(array $rows): array
    {
        if (!$rows) return [];
        $orders = Db::name('store_order')->whereIn('id', array_column($rows, 'id'))
            ->where('accounting_method', MemberOrderAccounting::CODE)
            ->field('id,accounting_method,paid,pay_type,pay_price,cash_pay_price,yue_pay_price,trade_no,pay_time')
            ->select()->toArray();
        $byId = [];
        foreach ($orders as $order) $byId[(int)$order['id']] = $order;
        foreach ($rows as &$row) if (isset($byId[(int)$row['id']])) $row['_memberAccounting'] = $byId[(int)$row['id']];
        unset($row);
        return $rows;
    }

    /** 只投影已成功且金额守恒的原付款；余额始终保留内部资金性质，零元不伪造收款行。 */
    public static function project(array $mapped, array $row): array
    {
        $order = (array)($row['_memberAccounting'] ?? []);
        if (!MemberOrderAccounting::isThirdParty($order)) return $mapped;
        // 已成团订单已有更完整的商户号凭据，保留原投影而不以简化适配覆盖。
        if (($mapped['paymentDetailsDataStatus'] ?? '') === 'original_order_snapshot') {
            return MemberOrderAccounting::project($mapped, $order, (string)($order['pay_type'] ?? ''));
        }
        $paid = self::cents($order['pay_price'] ?? null);
        $external = self::cents($order['cash_pay_price'] ?? null);
        $balance = self::cents($order['yue_pay_price'] ?? null);
        if ((int)($order['paid'] ?? 0) === 1 && min($paid, $external, $balance) >= 0
            && $external + $balance === $paid && ($external === 0
                || (in_array($order['pay_type'], ['weixin', 'alipay'], true) && trim((string)$order['trade_no']) !== ''))) {
            $payments = [];
            foreach (['third_party' => $external, 'member_balance' => $balance] as $method => $amount) {
                if ($amount === 0) continue;
                $payments[] = [
                    'id' => 'member-accounting:' . $order['id'] . ':' . $method,
                    'paymentMethodCode' => $method, 'methodName' => MemberOrderAccounting::NAME,
                    'amount' => $amount / 100,
                    'externalTransactionNo' => $method === 'member_balance' ? '' : (string)$order['trade_no'],
                    'remark' => '', 'occurredAt' => date('Y-m-d H:i:s', (int)$order['pay_time']),
                ];
            }
            $mapped['paymentDetails'] = $payments;
            $mapped['paymentDetailsDataStatus'] = 'original_order_snapshot';
        }
        return MemberOrderAccounting::project($mapped, $order, (string)($order['pay_type'] ?? ''));
    }

    private static function cents($value): int
    {
        if (!is_scalar($value) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string)$value)) return -1;
        return (int)bcmul((string)$value, '100', 0);
    }
}
