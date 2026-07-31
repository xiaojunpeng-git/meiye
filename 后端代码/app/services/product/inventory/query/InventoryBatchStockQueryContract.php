<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

final class InventoryBatchStockQueryContract
{
    public const PAGE_CODE = 'inventory_batch_stock';
    public const SCHEMA_VERSION = 'inventory-batch-stock-2026-07-29-v1';
    public const STABLE_ROW_KEY = 'batch_balance_id';

    public const STATUS_GOOD = 'GOOD';
    public const STATUS_DEFECTIVE = 'DEFECTIVE';
    public const STATUS_QUARANTINE = 'QUARANTINE';
    public const STATUS_FROZEN = 'FROZEN';
    public const STATUS_EXPIRED = 'EXPIRED';

    public const PERMISSION_VIEW = 'inventory.batch.view';
    public const PERMISSION_COST = 'inventory.cost.view';
    public const PERMISSION_EXPORT = 'inventory.batch.export';

    public static function fields(): array
    {
        return [
            self::field('organization_name', '组织', 'text', true, true),
            self::field('location_name', '仓库', 'text', true, true),
            self::field('store_name', '门店', 'text', true, true),
            self::field('product_name', '商品名称', 'text', true, true),
            self::field('sku_name', '商品规格', 'text', true, true),
            self::field('product_code', '商品编码', 'text'),
            self::field('barcode', '商品条码', 'text', false, true),
            self::field('brand_name', '品牌', 'text'),
            self::field('category_name', '商品类别', 'text'),
            self::field('stock_unit', '库存单位', 'text'),
            self::field('batch_no', '批次号', 'text', true, true),
            self::field('quality_status', '库存状态', 'text', true, true),
            self::field('received_date', '正式入库日期', 'date', true),
            self::field('manufactured_date', '生产日期', 'date', true),
            self::field('expire_date', '到期日', 'date', true),
            self::field('batch_balance_quantity', '批次结存数量', 'decimal', true, false, ['display', 'filter', 'sort', 'summary', 'export']),
            self::field('batch_unit_cost', '批次单位成本', 'amount', true, false, [], self::PERMISSION_COST),
            self::field('source_order_no', '来源入库单', 'text'),
            self::field('data_quality', '数据质量', 'text', true, true),
        ];
    }

    /**
     * These are saved through UQ after its neutral command runtime is ready.
     * Inventory owns only the fixed business formula blueprints.
     */
    public static function defaultCustomFieldBlueprints(): array
    {
        $cutoff = ['type' => 'context', 'key' => 'query_cutoff_date'];
        $expireDays = [
            'type' => 'operator',
            'operator' => 'date_diff_days',
            'args' => [self::fieldNode('expire_date'), $cutoff],
        ];
        return [
            [
                'stableName' => 'inventory_days_to_expiry',
                'name' => '距到期日天数',
                'returnType' => 'integer',
                'expression' => $expireDays,
            ],
            [
                'stableName' => 'inventory_age_days',
                'name' => '库存天数',
                'returnType' => 'integer',
                'expression' => [
                    'type' => 'operator',
                    'operator' => 'date_diff_days',
                    'args' => [$cutoff, self::fieldNode('received_date')],
                ],
            ],
            [
                'stableName' => 'inventory_amount',
                'name' => '库存金额',
                'returnType' => 'amount',
                'requiredPermission' => self::PERMISSION_COST,
                'expression' => [
                    'type' => 'operator',
                    'operator' => 'multiply',
                    'args' => [
                        self::fieldNode('batch_balance_quantity'),
                        self::fieldNode('batch_unit_cost'),
                    ],
                ],
            ],
            [
                'stableName' => 'inventory_expiry_band',
                'name' => '剩余保质期分档',
                'returnType' => 'text',
                'expression' => self::expiryBandExpression($expireDays),
            ],
        ];
    }

    public static function statusLabel(string $status): string
    {
        $labels = [
            self::STATUS_GOOD => '良品',
            self::STATUS_DEFECTIVE => '残次品',
            self::STATUS_QUARANTINE => '待检',
            self::STATUS_FROZEN => '冻结',
            self::STATUS_EXPIRED => '过期',
        ];
        if (!isset($labels[$status])) {
            throw new \InvalidArgumentException('inventory_batch_status_invalid');
        }
        return $labels[$status];
    }

    public static function assertCutoffDate(string $date): string
    {
        $date = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('inventory_query_cutoff_date_invalid');
        }
        return $date;
    }

    public static function unitsToDecimal(int $units, int $scale): string
    {
        if ($scale < 0 || $scale > 4) {
            throw new \InvalidArgumentException('inventory_quantity_scale_invalid');
        }
        $negative = $units < 0;
        $digits = ltrim((string)abs($units), '0');
        $digits = $digits === '' ? '0' : $digits;
        if ($scale === 0) {
            return ($negative ? '-' : '') . $digits;
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$scale);
        $fraction = rtrim(substr($digits, -$scale), '0');
        $value = $fraction === '' ? $whole : $whole . '.' . $fraction;
        return ($negative && $value !== '0' ? '-' : '') . $value;
    }

    public static function centsToAmount(int $cents): string
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('inventory_cost_invalid');
        }
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function field(
        string $key,
        string $label,
        string $type,
        bool $defaultVisible = false,
        bool $defaultQuick = false,
        array $allowedOperations = [],
        string $permission = ''
    ): array {
        return compact(
            'key',
            'label',
            'type',
            'defaultVisible',
            'defaultQuick',
            'allowedOperations',
            'permission'
        );
    }

    private static function fieldNode(string $key): array
    {
        return ['type' => 'field', 'key' => $key];
    }

    private static function literal(string $type, $value): array
    {
        return ['type' => 'literal', 'valueType' => $type, 'value' => $value];
    }

    private static function compare(string $operator, array $left, int $right): array
    {
        return [
            'type' => 'operator',
            'operator' => $operator,
            'args' => [$left, self::literal('integer', $right)],
        ];
    }

    private static function conditional(array $condition, string $value, array $otherwise): array
    {
        return [
            'type' => 'operator',
            'operator' => 'if',
            'args' => [$condition, self::literal('text', $value), $otherwise],
        ];
    }

    private static function expiryBandExpression(array $days): array
    {
        $unknown = self::literal('text', '到期日未知');
        $result = self::literal('text', '3年以上');
        foreach ([
            1095 => '2-3年',
            730 => '1-2年',
            365 => '1年内',
            180 => '91-180天',
            90 => '61-90天',
            60 => '46-60天',
            45 => '31-45天',
            30 => '0-30天',
        ] as $upper => $label) {
            $result = self::conditional(self::compare('lte', $days, $upper), $label, $result);
        }
        $result = self::conditional(self::compare('lt', $days, 0), '已过期', $result);
        return [
            'type' => 'operator',
            'operator' => 'if',
            'args' => [[
                'type' => 'operator',
                'operator' => 'is_null',
                'args' => [self::fieldNode('expire_date')],
            ], $unknown, $result],
        ];
    }
}
