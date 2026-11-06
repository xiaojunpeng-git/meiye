<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\report\StoreReportXlsxWriter;
use think\facade\Db;

/** Serializes the authorized list projection. The caller supplies the same reader and filters as its list route. */
final class InventoryListExport
{
    private const PAGES = [
        'inventory_batch_stock' => ['库存查询', ['product_name' => '商品', 'sku_name' => '规格', 'stock_unit' => '单位', 'available_quantity' => '可用库存', 'amount' => '库存金额']],
        'inventory_inbound' => ['入库管理', ['order_sn' => '入库单号', 'business_date' => '入库日期', 'order_type_name' => '入库类型', 'location_name' => '入库仓/门店', 'detail_count' => '商品项数', 'amount' => '入库金额']],
        'inventory_outbound' => ['出库管理', ['order_sn' => '出库单号', 'business_date' => '出库日期', 'order_type_name' => '出库类型', 'location_name' => '出库仓/门店', 'detail_count' => '商品项数', 'amount' => '成本金额']],
        'inventory_transfer' => ['调拨管理', ['order_sn' => '调拨单号', 'business_date' => '调拨日期', 'from_party_name' => '调出方', 'to_party_name' => '调入方', 'detail_count' => '商品项数']],
        'inventory_salon_usage' => ['院装管理', ['order_sn' => '业务单号', 'business_date' => '领退日期', 'operation_name' => '领退类型', 'location_name' => '领用仓库', 'project_name_snapshot' => '关联项目', 'detail_count' => '耗材项数', 'remark' => '备注']],
    ];

    public static function supports(string $pageCode): bool
    {
        return isset(self::PAGES[$pageCode]);
    }

    /**
     * Start at page one, with the submitted scope and conditions already bound to the reader.
     * A single read transaction keeps counts and subsequent pages on the same database snapshot.
     */
    public static function response(string $pageCode, callable $read, int $pageSize, bool $platform = false, bool $storeScope = false)
    {
        [$title, $labels] = self::PAGES[$pageCode];
        $stock = $pageCode === 'inventory_batch_stock';
        if (!$stock) $labels += ['status_name' => '状态', 'operation_at' => '操作日期', 'operator_name' => '操作人'];
        if ($stock && $platform) $labels = ['location_name' => '仓库', 'batch_no' => '批次号'] + $labels;
        $columns = [];
        foreach ($labels as $key => $label) {
            $columns[] = [
                'key' => $key,
                'label' => $label,
                'type' => ['detail_count' => 'integer', 'available_quantity' => 'decimal', 'amount' => 'money'][$key] ?? 'text',
                'source' => '取当前库存页面相同筛选和权限下的查询结果。',
            ];
        }

        $connection = Db::connect();
        $connection->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        return $connection->transaction(static function () use ($read, $pageSize, $pageCode, $title, $columns, $stock, $storeScope, $platform) {
            $first = $read(1, $pageSize);
            $total = self::total($first);
            $summary = $first['stockSummary'] ?? [];
            $summaryRow = $stock && $total > 0 && !empty($first['tableLayout']['summaryRow']) ? [
                'product_name' => '合计',
                'location_name' => '合计',
                'available_quantity' => $summary['available_quantity'] ?? null,
                'amount' => self::fromCents($summary['inventory_amount_cents'] ?? null),
            ] : [];
            $records = (static function () use ($read, $pageSize, $pageCode, $first, $total, $storeScope, $platform, $stock): \Generator {
                $seen = 0;
                for ($page = 1; $page <= max(1, (int)ceil($total / $pageSize)); $page++) {
                    $result = $page === 1 ? $first : $read($page, $pageSize);
                    $rows = $result['records'] ?? $result['list'] ?? $result['items'] ?? [];
                    $seen += count($rows);
                    // Store stock is presented as a SKU summary for each list page.
                    if ($stock && !$platform && isset($result['records'])) $rows = self::summarizeStorePage($rows);
                    foreach ($rows as $row) yield self::displayRow($row, $pageCode, $platform, $storeScope);
                }
                // Refuse a partial workbook if a reader ever changes its pagination contract.
                if ($seen !== $total) throw new \RuntimeException('库存查询结果未完整导出，请重新查询后重试。');
            })();
            return (new StoreReportXlsxWriter())->response($pageCode, [
                'title' => $title, 'columns' => $columns, 'records' => $records, 'summary_row' => $summaryRow,
            ]);
        });
    }

    private static function total(array $result): int
    {
        return (int)($result['total'] ?? $result['count'] ?? 0);
    }

    /** The list itself displays a per-page SKU grouping for store batch results. */
    private static function summarizeStorePage(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = implode(':', [$row['product_id'], $row['sku_id'], $row['sku_unique'] ?? '']);
            $quantity = $row['batch_balance_quantity'] ?? $row['available_quantity'] ?? 0;
            $amount = $row['inventory_amount'] ?? null;
            if (!isset($groups[$key])) {
                $groups[$key] = $row;
                $groups[$key]['available_quantity'] = $quantity;
            } else {
                $groups[$key]['available_quantity'] = bcadd((string)$groups[$key]['available_quantity'], (string)$quantity, 6);
                $previous = $groups[$key]['inventory_amount'] ?? null;
                $groups[$key]['inventory_amount'] = $previous === null || $amount === null
                    ? null : bcadd((string)$previous, (string)$amount, 2);
            }
        }
        return array_values($groups);
    }

    /** Export preserves authoritative currency precision and uses the same business labels as the list. */
    private static function displayRow(array $row, string $pageCode, bool $platform, bool $storeScope): array
    {
        if ($pageCode === 'inventory_batch_stock') {
            $row['available_quantity'] = $row['available_quantity'] ?? $row['batch_balance_quantity'] ?? null;
            $row['amount'] = array_key_exists('inventory_amount_cents', $row)
                ? self::fromCents($row['inventory_amount_cents']) : ($row['inventory_amount'] ?? null);
            if (preg_match('/^AUTO-(?:IN|COUNT)-[a-f0-9]{48}$/', (string)($row['batch_no'] ?? ''))) $row['batch_no'] = '';
            return $row;
        }

        if ($storeScope) $row['location_name'] = ($row['store_name'] ?? '') ?: ($row['location_name'] ?? null);
        $row['amount'] = self::fromCents($row['cost_amount_cents'] ?? null);
        $time = (int)($row['operation_at'] ?? 0);
        $row['operation_at'] = $time > 0 ? date('Y-m-d H:i:s', $time) : null;
        $status = (string)($row['status_name'] ?? $row['document_status'] ?? '');
        if ($pageCode === 'inventory_transfer' && $platform
            && (($row['document_status'] ?? '') === 'DISPATCHED' || in_array($status, ['DISPATCHED', '在途'], true))) {
            $status = '未收货';
        }
        if ($pageCode === 'inventory_salon_usage') {
            $row['order_sn'] = $row['usage_no'] ?? $row['order_sn'] ?? null;
            $row['operation_name'] = ($row['operation_type'] ?? '') === 'RETURN' ? '退回' : '领用';
            $row['project_name_snapshot'] = trim((string)($row['project_name_snapshot'] ?? '')) ?: '未关联项目';
        }
        $row['status_name'] = [
            'SETTLED' => '已结算', 'CONFIRMED' => '已确认', 'DRAFT' => '草稿', 'DISPATCHED' => '在途',
            'RECEIVED' => '已收货', 'CANCELLED' => '已取消', 'REVERSED' => '已作废', 'VOIDED' => '已作废',
        ][$status] ?? ($status !== '' ? $status : '已完成');
        return $row;
    }

    private static function fromCents($value): ?string
    {
        return is_numeric($value) ? bcdiv((string)$value, '100', 2) : null;
    }
}
