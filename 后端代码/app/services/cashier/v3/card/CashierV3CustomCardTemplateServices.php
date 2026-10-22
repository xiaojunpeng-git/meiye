<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Positive product/SKU identities required by the existing card issuer are
 * compatibility carriers, never the price or entitlement authority. New stores
 * resolve them by a store-bound business marker, not a copied customer ID.
 */
final class CashierV3CustomCardTemplateServices
{
    public static function marker(int $storeId): string
    {
        return 'CASHIER_V3_CUSTOM_CARD:STORE:' . $storeId;
    }

    /** Only server-loaded product metadata may identify an internal carrier. */
    public static function isTemplate(array $product): bool
    {
        $storeId = (int)($product['relation_id'] ?? $product['product_relation_id'] ?? 0);
        return $storeId > 0
            && (int)($product['type'] ?? $product['product_type'] ?? -1) === 1
            && (string)($product['keyword'] ?? $product['product_keyword'] ?? '') === self::marker($storeId);
    }

    /** Read-only lookup: catalog discovery must never manufacture business rows. */
    public function find(int $storeId, bool $lock = false): ?array
    {
        $rows = Db::name('store_product')->where('type', 1)->where('relation_id', $storeId)
            ->where('keyword', self::marker($storeId))->lock($lock)->limit(2)->select()->toArray();
        if (count($rows) > 1) throw self::failure('custom_card_template_ambiguous');
        if (!$rows) return null;
        $product = $rows[0];
        $skus = Db::name('store_product_attr_value')->where('product_id', (int)$product['id'])
            ->where('type', 0)->lock($lock)->limit(2)->select()->toArray();
        if ((int)$product['product_type'] !== 6 || (int)$product['is_del'] !== 0
            || (int)$product['is_verify'] !== 1 || count($skus) !== 1
            || (int)$skus[0]['is_show'] !== 1) {
            // Do not replace a disabled/damaged carrier behind existing cards.
            throw self::failure('custom_card_template_invalid');
        }
        return ['productId' => (int)$product['id'], 'skuId' => (int)$skus[0]['id']];
    }

    /**
     * Called only while accepting a new immutable configuration. Lock the store
     * parent before checking/creating the marker so concurrent first purchases
     * cannot create two carriers. All inserts roll back with the checkout.
     */
    public function ensureInTx(int $storeId): array
    {
        CashierV3TransactionGuard::assertInTransaction('customCardTemplateCreate');
        if ($storeId <= 0 || !Db::name('system_store')->where('id', $storeId)->lock(true)->find()) {
            throw self::failure('custom_card_template_store_invalid');
        }
        // A locking current read is essential under MySQL REPEATABLE READ:
        // a prior catalog snapshot must not hide another transaction's insert.
        $identity = $this->find($storeId, true);
        if ($identity !== null) return $identity;
        $productId = (int)Db::name('store_product')->insertGetId([
            'type' => 1, 'relation_id' => $storeId, 'pid' => 0, 'product_type' => 6,
            'store_name' => '系统定制卡载体', 'keyword' => self::marker($storeId),
            'unit_name' => '张', 'is_show' => 0, 'is_del' => 0, 'is_verify' => 1,
            'is_inventory' => 0, 'allow_negative_stock' => 0, 'add_time' => time(),
        ]);
        if ($productId <= 0) throw self::failure('custom_card_template_insert_failed');
        $skuId = (int)Db::name('store_product_attr_value')->insertGetId([
            'product_id' => $productId, 'product_type' => 6, 'type' => 0, 'is_show' => 1,
            'unique' => substr(hash('sha256', self::marker($storeId) . ':' . $productId), 0, 8),
            'suk' => '默认', 'price' => '0.00', 'ot_price' => '0.00', 'cost' => '0.00',
            // Carrier validity is only a neutral adapter default. The accepted
            // configuration replaces it with the customer's fixed end date.
            'stock' => 0, 'write_times' => 0, 'write_valid' => 1,
        ]);
        if ($skuId <= 0) throw self::failure('custom_card_template_sku_insert_failed');
        return ['productId' => $productId, 'skuId' => $skuId];
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
            '定制卡内部资料异常，本次未完成结账，请联系管理员。',
            CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]);
    }
}
