<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use Throwable;

/** Presents inventory command failures without exposing implementation codes as UI copy. */
final class InventoryErrorMessage
{
    private const MESSAGES = [
        'inventory_salon_usage_return_exceeds_issue' => '退回数量不能超过原领用数量。',
        'inventory_salon_usage_project_not_found' => '所选项目不存在、已停用或不属于当前门店。',
        'inventory_salon_usage_stock_insufficient' => '当前库存不足，无法完成院装领用。',
        'inventory_salon_usage_date_invalid' => '院装业务日期格式不正确，请重新选择日期。',
        'inventory_salon_usage_date_range_invalid' => '开始日期不能晚于结束日期。',
        'inventory_batch_transfer_input_invalid' => '调拨信息不完整，请检查调入仓和明细。',
        'inventory_batch_transfer_line_invalid' => '调拨明细不合法，请检查商品和数量。',
        'inventory_batch_transfer_target_same_as_source' => '调入仓不能与调出仓相同。',
        'inventory_batch_transfer_stock_insufficient' => '当前库存不足，无法完成调拨。',
        'inventory_batch_transfer_idempotency_conflict' => '本次调拨内容与已提交记录不一致，请刷新后重新操作。',
        'inventory_stock_request_line_invalid' => '请货明细不合法，请检查商品和数量。',
        'inventory_stock_request_input_invalid' => '请货信息不完整，请检查后重新提交。',
        'inventory_stock_request_idempotency_conflict' => '本次请货内容与已提交记录不一致，请刷新后重新操作。',
        'inventory_platform_warehouse_idempotency_conflict' => '本次建仓内容与已提交记录不一致，请刷新后重新操作。',
        'inventory_platform_warehouse_name_taken' => '当前门店已存在同名仓库。',
        'inventory_platform_warehouse_name_lock_timeout' => '仓库创建处理中，请稍后查询结果。',
        'inventory_manual_inbound_idempotency_conflict' => '本次入库内容与已提交记录不一致，请刷新后重新操作。',
        'inventory_manual_outbound_stock_insufficient' => '当前库存不足，请核对出库数量后重试。',
        'inventory_manual_outbound_idempotency_conflict' => '本次出库内容与已提交记录不一致，请刷新后重新操作。',
        'inventory_stock_count_loss_exceeds_book' => '盘亏数量不能超过当前账面库存。',
    ];

    /** @return array{code: string, message: string}|null */
    public static function from(Throwable $exception): ?array
    {
        $code = trim($exception->getMessage());
        if (preg_match('/^inventory_[a-z0-9_]+$/D', $code) !== 1) {
            return null;
        }

        return [
            'code' => $code,
            'message' => self::MESSAGES[$code] ?? '库存操作未完成，请核对数据后重试。',
        ];
    }
}
