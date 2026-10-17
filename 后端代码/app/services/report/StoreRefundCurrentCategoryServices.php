<?php

namespace app\services\report;

use think\facade\Db;

/**
 * Resolve a refund's current platform categories from immutable item IDs.
 * A card shell is never an item: exact refunds use the card component, while a
 * whole-card refund may use only projects under one verified original card.
 * The result is for classification only; it never invents a historical partner
 * allocation or changes the refund's authoritative amount.
 */
final class StoreRefundCurrentCategoryServices
{
    /**
     * 按已核实项目金额拆分一个退款且不复制现金。整元退款保持整元，
     * 历史非整元事实保留分精度；权重缺失时整笔退回未分类，避免错误归属。
     *
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,int>
     */
    public static function allocateAmountCents(int $amount, array &$categories): array
    {
        if (count($categories) <= 1) return [$amount];
        $weights = array_map(static fn(array $category): int => (int)($category['weight_cents'] ?? 0), $categories);
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0 || count(array_filter($weights, static fn(int $weight): bool => $weight <= 0)) > 0) {
            $categories = [['id' => 0, 'path' => '', 'platform_product_id' => 0, 'weight_cents' => 0]];
            return [$amount];
        }
        $unit = $amount % 100 === 0 ? 100 : 1;
        $units = intdiv(abs($amount), $unit);
        $remaining = $units;
        $split = [];
        foreach ($weights as $index => $weight) {
            $part = $index === count($weights) - 1 ? $remaining : intdiv($units * $weight, $totalWeight);
            $remaining -= $part;
            $split[] = ($amount < 0 ? -1 : 1) * $part * $unit;
        }
        return $split;
    }

    /**
     * @param array<int,array<string,mixed>> $refunds Already authorized refund rows
     * @return array<string,array<int,array<string,mixed>>> Operation ID => current categories
     */
    public function resolve(array $refunds): array
    {
        $cardRows = $catalogRows = [];
        foreach ($refunds as $row) {
            $operationId = (string)($row['operation_id'] ?? '');
            if ($operationId === '' || (int)($row['store_id'] ?? 0) <= 0) continue;
            if ((string)($row['source_type'] ?? '') === 'card' && (int)($row['refund_card_holder_id'] ?? 0) > 0) {
                $cardRows[$operationId] = $row;
            } elseif ((string)($row['source_type'] ?? '') === 'catalog' && (int)($row['catalog_item_id'] ?? 0) > 0) {
                $catalogRows[$operationId] = $row;
            }
        }

        $links = $keyToOperation = $requiredCardLinks = [];
        if ($catalogRows !== []) {
            // A catalog_item_id is a SKU ID. Check the store copy before following
            // its pid to the platform product; equal numeric IDs mean nothing.
            $skus = Db::name('store_product_attr_value')->alias('sku')
                ->join('store_product product', 'product.id=sku.product_id')
                ->whereIn('sku.id', array_values(array_unique(array_map('intval', array_column($catalogRows, 'catalog_item_id')))))
                ->where('sku.type', 0)->where('product.type', 1)
                ->field('sku.id sku_id,sku.product_id,product.relation_id store_id')->select()->toArray();
            $bySku = array_column($skus, null, 'sku_id');
            foreach ($catalogRows as $operationId => $row) {
                $sku = $bySku[(int)$row['catalog_item_id']] ?? null;
                if ($sku && (int)$sku['store_id'] === (int)$row['store_id']) {
                    $key = $operationId . ':0';
                    $keyToOperation[$key] = $operationId;
                    $links[$key] = ['store_id' => (int)$row['store_id'], 'product_id' => (int)$sku['product_id']];
                }
            }
        }
        if ($cardRows !== []) {
            $holderIds = array_values(array_unique(array_map('intval', array_column($cardRows, 'refund_card_holder_id'))));
            $holders = Db::name('user_card_holder')->alias('holder')
                ->join('store_order original', 'original.id=holder.oid AND original.uid=holder.uid')
                ->whereIn('holder.id', $holderIds)
                ->fieldRaw('holder.id,holder.uid,holder.oid,holder.product_id,original.store_id,ROUND(original.total_price*100) original_total_cents')->select()->toArray();
            $byHolder = array_column($holders, null, 'id');
            $verified = $orderIds = $componentIds = [];
            foreach ($cardRows as $operationId => $row) {
                $holder = $byHolder[(int)$row['refund_card_holder_id']] ?? null;
                if (!$holder || (int)$holder['uid'] !== (int)($row['member_id'] ?? 0)
                    || (string)$holder['oid'] !== (string)($row['source_order_id'] ?? '')
                    || (int)$holder['store_id'] !== (int)$row['store_id']) continue;
                $verified[$operationId] = $holder;
                $orderIds[(int)$holder['oid']] = true;
                if ((int)($row['card_component_id'] ?? 0) > 0) $componentIds[(int)$row['card_component_id']] = true;
            }
            if ($verified !== []) {
                $carts = Db::name('store_order_cart_info')->whereIn('oid', array_keys($orderIds))
                    ->whereIn('product_type', [5, 6])
                    ->fieldRaw('id,oid,product_id,cart_type,product_type,ROUND(total_price*100) component_amount_cents')->order('id', 'asc')->select()->toArray();
                $byOrder = $byComponent = [];
                foreach ($carts as $cart) {
                    $byOrder[(int)$cart['oid']][] = $cart;
                    if (isset($componentIds[(int)$cart['id']])) $byComponent[(int)$cart['id']] = $cart;
                }
                foreach ($verified as $operationId => $holder) {
                    $row = $cardRows[$operationId];
                    $componentId = (int)($row['card_component_id'] ?? 0);
                    if ($componentId > 0) {
                        $cart = $byComponent[$componentId] ?? null;
                        if (!$cart || (int)$cart['oid'] !== (int)$holder['oid']
                            || (int)$cart['cart_type'] !== 2 || (int)$cart['product_type'] !== 6) continue;
                        $components = [$cart];
                        $weightsVerified = true;
                    } else {
                        $headers = $components = [];
                        foreach ($byOrder[(int)$holder['oid']] ?? [] as $cart) {
                            if ((int)$cart['product_type'] === 5 && (int)$cart['cart_type'] !== 2) $headers[] = $cart;
                            elseif ((int)$cart['product_type'] === 6 && (int)$cart['cart_type'] === 2) $components[] = $cart;
                        }
                        // A mixed or damaged historical order cannot lend another
                        // card's projects to this refund.
                        if (count($headers) !== 1 || (int)$headers[0]['product_id'] !== (int)$holder['product_id']
                            || $components === []) continue;
                        $componentTotal = array_sum(array_map(static fn(array $part): int => (int)$part['component_amount_cents'], $components));
                        $weightsVerified = (int)$holder['original_total_cents'] > 0
                            && $componentTotal === (int)$holder['original_total_cents']
                            && count(array_filter($components, static fn(array $part): bool => (int)$part['component_amount_cents'] <= 0)) === 0;
                    }
                    foreach ($components as $cart) {
                        $key = $operationId . ':' . (int)$cart['id'];
                        $keyToOperation[$key] = $operationId;
                        $requiredCardLinks[$operationId][] = $key;
                        $links[$key] = [
                            'store_id' => (int)$row['store_id'], 'product_id' => (int)$cart['product_id'],
                            'weight_cents' => $weightsVerified ? max(1, (int)$cart['component_amount_cents']) : 0,
                        ];
                    }
                }
            }
        }

        $resolved = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $incompleteCards = [];
        foreach ($requiredCardLinks as $operationId => $keys) {
            foreach ($keys as $key) {
                if (!isset($resolved[$key])
                    || in_array((int)$resolved[$key]['product_type'], [4, 5], true)) {
                    // One missing project cannot transfer its share to the
                    // classified siblings of a whole-card refund.
                    $incompleteCards[$operationId] = true;
                    break;
                }
            }
        }
        $result = [];
        foreach ($resolved as $key => $category) {
            $operationId = $keyToOperation[$key];
            if (isset($incompleteCards[$operationId])) continue;
            $categoryKey = (int)$category['id'] . '|' . (string)$category['path'];
            if (!isset($result[$operationId][$categoryKey])) {
                $result[$operationId][$categoryKey] = $category + ['weight_cents' => 0];
            }
            $result[$operationId][$categoryKey]['weight_cents'] += (int)($links[$key]['weight_cents'] ?? 0);
        }
        foreach ($result as &$categories) $categories = array_values($categories);
        unset($categories);
        return $result;
    }
}
