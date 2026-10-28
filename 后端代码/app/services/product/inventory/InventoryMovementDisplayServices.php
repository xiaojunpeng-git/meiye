<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/**
 * Display-only enrichment of an already authorized, bounded movement page.
 * Keep technical source keys for traceability; never mutate ledger facts,
 * manufacture business numbers or borrow current catalog names.
 */
final class InventoryMovementDisplayServices
{
    private const TYPE_NAMES = [
        'cashier_sale' => '收银结账', 'cashier_sale_void' => '销售订单作废',
        'cashier_presale_stock_repair' => '预售扣库纠正',
        'manual_inbound' => '手工入库', 'manual_inbound_reversal' => '手工入库作废',
        'manual_outbound' => '手工出库', 'manual_outbound_reversal' => '手工出库作废',
        'cross_transfer_in' => '调拨入库', 'cross_transfer_out' => '调拨出库',
        'cross_transfer_withdraw' => '调拨撤回入库',
        'cross_transfer_in_reversal' => '调拨入库冲销', 'cross_transfer_out_reversal' => '调拨出库冲销',
        'batch_transfer_in' => '仓库调拨入库', 'batch_transfer_out' => '仓库调拨出库',
        'stock_count_gain' => '盘盈入库', 'stock_count_loss' => '盘亏出库',
        'salon_usage_issue' => '院装领用', 'salon_usage_out' => '院装领用',
        'salon_usage_issue_reversal' => '院装领用作废退库',
        'salon_usage_return' => '院装退回', 'completion_batch' => '项目耗材核销',
        'presale_claim_outbound' => '预售领用', 'presale_claim_void' => '预售领用作废',
        'gift_product_claim_outbound' => '赠送领用', 'gift_product_claim_void' => '赠送领用作废',
    ];

    /** 所有库存页面共用中文业务名称；未知代码保留在来源字段追溯，不直接作为用户文案。 */
    public static function typeName(string $type): string
    {
        return self::TYPE_NAMES[$type] ?? '其他库存业务';
    }

    /** Batch lookups stay within each fact's tenant/store and exact source IDs; no per-row SQL. */
    public function decorate(array $facts): array
    {
        $groups = [];
        foreach ($facts as $index => $fact) {
            $groups[json_encode([(string)$fact['tenant_id'], (int)$fact['store_id']])][$index] = $fact;
        }
        foreach ($groups as $group) {
            $first = reset($group);
            $tenant = (string)$first['tenant_id'];
            $store = (int)$first['store_id'];
            $saleIds = []; $voidIds = []; $claimIds = []; $lineIds = [];
            foreach ($group as $fact) {
                $type = (string)$fact['source_type'];
                if (in_array($type, ['cashier_sale', 'cashier_presale_stock_repair'], true)) $saleIds[] = (string)$fact['source_id'];
                if ($type === 'cashier_sale_void') $voidIds[] = (string)$fact['source_id'];
                if (in_array($type, ['cashier_sale', 'cashier_sale_void', 'cashier_presale_stock_repair'], true)) $lineIds[] = (string)$fact['source_detail_id'];
                if (in_array($type, ['presale_claim_outbound', 'presale_claim_void', 'gift_product_claim_outbound', 'gift_product_claim_void'], true)) $claimIds[] = (string)$fact['source_id'];
            }
            $voids = [];
            if ($voidIds) {
                $rows = Db::name('cashier_v3_order_lifecycle_operation')->where('tenant_id', $tenant)->where('store_id', $store)
                    ->where('source_type', 'sales')->where('operation_type', 'void')->where('status', 'succeeded')
                    ->whereIn('operation_id', array_unique($voidIds))
                    ->field('operation_id,operation_no,source_order_id')->select()->toArray();
                foreach ($rows as $row) { $voids[(string)$row['operation_id']] = $row; $saleIds[] = (string)$row['source_order_id']; }
            }
            $lines = [];
            if ($saleIds && $lineIds) {
                $rows = Db::name('cashier_v3_sales_order_line')->alias('sl')
                    ->join('cashier_v3_sales_order so', 'so.tenant_id=sl.tenant_id AND so.store_id=sl.store_id AND so.order_id=sl.order_id')
                    ->where('sl.tenant_id', $tenant)->where('sl.store_id', $store)
                    ->whereIn('sl.order_id', array_unique($saleIds))->whereIn('sl.order_line_id', array_unique($lineIds))
                    // Original forward lines remain authoritative even after a void.
                    ->where('sl.line_direction', 'forward')
                    ->field('sl.order_id,sl.order_line_id,sl.item_name_snapshot,sl.line_no,sl.is_presale,so.order_no')
                    ->select()->toArray();
                foreach ($rows as $row) $lines[(string)$row['order_id'] . ':' . (string)$row['order_line_id']] = $row;
            }
            $claims = [];
            if ($claimIds) {
                $rows = Db::name('cashier_v3_presale_claim')->where('tenant_id', $tenant)->where('store_id', $store)
                    ->whereIn('claim_id', array_unique($claimIds))->field('claim_id,claim_no,source_kind')->select()->toArray();
                foreach ($rows as $row) $claims[(string)$row['claim_id']] = $row;
            }
            foreach ($group as $index => $fact) $facts[$index] = $this->project($fact, $lines, $voids, $claims);
        }
        return $facts;
    }

    /** Resolve labels only from matched immutable sources, with honest legacy fallbacks. */
    private function project(array $fact, array $lines, array $voids, array $claims): array
    {
        $type = (string)$fact['source_type'];
        $source = (string)$fact['source_id'];
        $fact['source_type_name'] = self::typeName($type);
        $fact['source_detail_name'] = trim((string)($fact['source_product_name'] ?? '')) ?: '历史业务明细';
        $fact['order_sn'] = trim((string)($fact['document_no_snapshot'] ?? ''));
        if (in_array($type, ['cashier_sale', 'cashier_sale_void', 'cashier_presale_stock_repair'], true)) {
            $operation = $voids[$source] ?? [];
            // A correction references the original sale; it is not a fabricated void document.
            $orderId = $type !== 'cashier_sale_void' ? $source : (string)($operation['source_order_id'] ?? '');
            $line = $lines[$orderId . ':' . (string)$fact['source_detail_id']] ?? [];
            $fact['order_sn'] = $type !== 'cashier_sale_void'
                ? (string)($line['order_no'] ?? '') : (string)($operation['operation_no'] ?? '');
            $name = trim((string)($line['item_name_snapshot'] ?? ''));
            $fact['source_detail_name'] = $name !== ''
                ? '第' . (int)$line['line_no'] . '行：' . $name . ((int)$line['is_presale'] === 1 ? '（预售）' : '')
                : '历史销售明细';
        } elseif (isset($claims[$source])) {
            $fact['order_sn'] = (string)$claims[$source]['claim_no'];
        } elseif ($fact['order_sn'] === '' && in_array($type, [
            'stock_count_gain', 'stock_count_loss', 'cross_transfer_in', 'cross_transfer_out',
            'cross_transfer_in_reversal', 'cross_transfer_out_reversal', 'salon_usage_issue', 'salon_usage_out', 'salon_usage_return',
        ], true)) {
            // These writers persist the real business document number as source_id.
            $fact['order_sn'] = $source;
        }
        if (trim($fact['order_sn']) === '') $fact['order_sn'] = '历史业务单据';
        unset($fact['source_product_name'], $fact['document_no_snapshot']);
        return $fact;
    }
}
