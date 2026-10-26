<?php

namespace app\services\mobile\customer;

use think\exception\ValidateException;
use think\facade\Db;

/** Complete current-instance order history for one customer, without scope filtering. */
final class MobileCustomerOrderRecordServices
{
    private const MAX_PAGE_SIZE = 50;

    public function page(array $payload): array
    {
        $memberId = (int)($payload['memberId'] ?? 0);
        if ($memberId <= 0 || !Db::name('user')->where('uid', $memberId)->where('is_del', 0)->count()) {
            throw new ValidateException('客户参数无效，或客户已不存在。');
        }
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int)($payload['pageSize'] ?? $payload['limit'] ?? 20)));
        $query = Db::name('store_order')->where('uid', $memberId);
        $total = (int)(clone $query)->count('id');
        $rows = $query->field('id,order_id,store_id,type,order_type,total_price,pay_price,paid,status,refund_status,is_del,is_system_del,is_user_del,terminal_action,add_time,pay_time,remark')
            ->orderRaw('CASE WHEN pay_time > 0 THEN pay_time ELSE add_time END DESC')
            ->order('id', 'desc')->page($page, $pageSize)->select()->toArray();
        $storeIds = array_values(array_unique(array_filter(array_map(static function (array $row): int { return (int)$row['store_id']; }, $rows))));
        $storeNames = $storeIds === [] ? [] : Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id');
        $itemsByOrder = $this->itemsByOrder(array_column($rows, 'id'));

        return [
            'memberId' => $memberId,
            'records' => array_map(function (array $row) use ($storeNames, $itemsByOrder): array {
                $id = (int)$row['id'];
                return [
                    'id' => 'order:' . $id,
                    'orderId' => (string)$id,
                    'kindLabel' => $this->kindLabel($row),
                    'statusLabel' => $this->statusLabel($row),
                    'itemSummary' => $this->itemSummary($itemsByOrder[$id] ?? []),
                    'amount' => $this->money($row['pay_price'] ?? 0),
                    'totalAmount' => $this->money($row['total_price'] ?? 0),
                    'occurredAt' => $this->dateTime((int)($row['pay_time'] ?: $row['add_time'])),
                    'storeName' => (string)($storeNames[(int)$row['store_id']] ?? ''),
                ];
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'hasMore' => $page * $pageSize < $total,
            'dataAuthority' => 'CURRENT_INSTANCE_MEMBER_STORE_ORDER_HISTORY',
        ];
    }

    /** One immutable order projection for the customer-detail recent-purchase link. */
    public function detail(array $payload): array
    {
        $memberId = (int)($payload['memberId'] ?? 0);
        $orderId = (int)($payload['orderId'] ?? 0);
        if ($memberId <= 0 || $orderId <= 0) {
            throw new ValidateException('订单详情参数无效。');
        }

        $order = Db::name('store_order')
            ->where('id', $orderId)
            ->where('uid', $memberId)
            ->field('id,order_id,store_id,type,order_type,total_price,pay_price,paid,status,refund_status,is_del,is_system_del,is_user_del,terminal_action,add_time,pay_time,remark')
            ->find();
        if (!$order) {
            throw new ValidateException('未找到该客户的订单。');
        }

        $storeName = (string)(Db::name('system_store')->where('id', (int)$order['store_id'])->value('name') ?? '');
        return [
            'memberId' => $memberId,
            'order' => [
                'id' => 'order:' . (int)$order['id'],
                'orderId' => (string)$order['id'],
                'kindLabel' => $this->kindLabel($order),
                'statusLabel' => $this->statusLabel($order),
                'amount' => $this->money($order['pay_price'] ?? 0),
                'totalAmount' => $this->money($order['total_price'] ?? 0),
                'occurredAt' => $this->dateTime((int)($order['pay_time'] ?: $order['add_time'])),
                'storeName' => $storeName,
                'items' => $this->detailItems((int)$order['id']),
            ],
            'dataAuthority' => 'CURRENT_INSTANCE_MEMBER_STORE_ORDER_HISTORY',
        ];
    }

    private function itemsByOrder(array $orderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $orderIds)));
        if ($ids === []) return [];
        $rows = Db::name('store_order_cart_info')->whereIn('oid', $ids)->field('oid,cart_info')->order('id', 'asc')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $name = $this->itemName($row['cart_info'] ?? '');
            if ($name !== '') $result[(int)$row['oid']][] = $name;
        }
        return $result;
    }

    private function itemName($cartInfo): string
    {
        $data = is_array($cartInfo) ? $cartInfo : json_decode((string)$cartInfo, true);
        if (!is_array($data)) return '';
        return trim((string)($data['productInfo']['store_name'] ?? $data['productInfo']['storeName'] ?? $data['product_name'] ?? ''));
    }

    private function itemSummary(array $items): string
    {
        $items = array_values(array_unique(array_filter(array_map('strval', $items))));
        if ($items === []) return '无商品明细';
        $head = array_slice($items, 0, 2);
        return implode('、', $head) . (count($items) > 2 ? '等' : '');
    }

    private function detailItems(int $orderId): array
    {
        $rows = Db::name('store_order_cart_info')->where('oid', $orderId)->field('id,cart_info')->order('id', 'asc')->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $data = is_array($row['cart_info'] ?? null) ? $row['cart_info'] : json_decode((string)($row['cart_info'] ?? ''), true);
            if (!is_array($data)) continue;
            $product = is_array($data['productInfo'] ?? null) ? $data['productInfo'] : [];
            $quantity = max(1, (int)($data['cart_num'] ?? $data['cartNum'] ?? $data['quantity'] ?? 1));
            $unitPrice = $data['truePrice'] ?? $data['true_price'] ?? $product['price'] ?? $product['ot_price'] ?? 0;
            $items[] = [
                'id' => 'order-item:' . (int)$row['id'],
                'name' => $this->itemName($data) ?: '未命名商品',
                'quantity' => $quantity,
                'unitPrice' => $this->money($unitPrice),
                'subtotal' => $this->money((float)$unitPrice * $quantity),
            ];
        }
        return $items;
    }

    private function kindLabel(array $row): string
    {
        if (strpos((string)($row['remark'] ?? ''), 'V3 recharge ') === 0) return '储值订单';
        if ((int)($row['order_type'] ?? 0) === 1) return '储值订单';
        if ((int)($row['order_type'] ?? 0) === 2) return '项目核销单';
        return '消费订单';
    }

    private function statusLabel(array $row): string
    {
        if ((int)($row['is_del'] ?? 0) === 1 || (int)($row['is_system_del'] ?? 0) === 1 || (int)($row['is_user_del'] ?? 0) === 1) return '已删除';
        if ((int)($row['refund_status'] ?? 0) > 0) return '退款处理中';
        if ((int)($row['terminal_action'] ?? 0) > 0) return '已作废';
        return (int)($row['paid'] ?? 0) === 1 ? '已支付' : '待支付';
    }

    private function money($value): string
    {
        return number_format((float)$value, 2, '.', '');
    }

    private function dateTime(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
