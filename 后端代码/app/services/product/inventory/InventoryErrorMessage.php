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
        'inventory_manual_inbound_scope_invalid' => '入库操作范围无效，请重新进入库存页面后再试。',
        'inventory_manual_inbound_input_invalid' => '入库信息不完整，请检查入库日期和商品明细。',
        'inventory_manual_inbound_idempotency_invalid' => '本次入库请求已失效，请刷新页面后重新提交。',
        'inventory_manual_inbound_line_invalid' => '入库商品明细不完整，请检查商品、批次、数量和单价。',
        'inventory_manual_inbound_dates_required' => '请为每个入库商品填写生产日期和到期日。',
        'inventory_manual_inbound_date_order_invalid' => '到期日不能早于生产日期。',
        'inventory_manual_inbound_date_invalid' => '入库日期格式不正确，请重新选择日期。',
        'inventory_manual_inbound_identifier_invalid' => '入库商品标识无效，请重新选择商品。',
        'inventory_manual_inbound_cost_invalid' => '入库单价格式不正确，请输入不小于 0 的金额。',
        'inventory_manual_inbound_store_scope_invalid' => '当前门店状态异常，暂时不能入库，请重新登录后再试。',
        'inventory_manual_inbound_operator_scope_denied' => '当前员工无权操作本门店库存。',
        'inventory_manual_inbound_organization_scope_invalid' => '当前门店的组织归属异常，请联系管理员处理。',
        'inventory_manual_inbound_organization_cycle' => '当前门店的组织层级异常，请联系管理员处理。',
        'inventory_manual_inbound_default_location_ambiguous' => '当前门店存在多个默认库存仓，请联系管理员处理。',
        'inventory_manual_inbound_location_scope_invalid' => '当前门店默认库存仓归属异常，请联系管理员处理。',
        'inventory_manual_inbound_default_location_conflict' => '当前门店默认库存仓配置冲突，请联系管理员处理。',
        'inventory_manual_inbound_default_location_create_failed' => '当前门店默认库存仓创建失败，请稍后重试。',
        'inventory_manual_inbound_sku_not_found' => '所选商品规格已失效，请重新选择商品。',
        'inventory_manual_inbound_stock_scope_invalid' => '当前商品库存归属异常，请联系管理员处理。',
        'inventory_manual_inbound_stock_changed' => '库存数据刚发生变化，请刷新后重新提交。',
        'inventory_manual_inbound_batch_not_active' => '该批次已停用，不能继续入库。',
        'inventory_manual_inbound_batch_cost_conflict' => '同一批次的入库单价必须保持一致，请更换批次或核对单价。',
        'inventory_manual_inbound_batch_changed' => '批次库存刚发生变化，请刷新后重新提交。',
        'inventory_manual_inbound_idempotency_conflict' => '本次入库内容与已提交记录不一致，请刷新后重新操作。',
        'inventory_manual_outbound_stock_insufficient' => '当前库存不足，请核对出库数量后重试。',
        'inventory_manual_outbound_idempotency_conflict' => '本次出库内容与已提交记录不一致，请刷新后重新操作。',
        'inventory_stock_count_loss_exceeds_book' => '盘亏数量不能超过当前账面库存。',
        // 整张盘点单在事务中核对；任何规格失效或账面变化都应提示重新核对，不允许部分入账。
        'inventory_stock_count_catalog_not_found' => '盘点商品已失效，请重新选择商品。',
        'inventory_stock_count_stock_changed' => '账面库存已变化，请重新核对盘点数量后提交。',
        'inventory_stock_count_duplicate_sku' => '盘点单中有重复商品规格，请删除重复行。',
        'inventory_stock_count_no_difference' => '实盘库存与账面库存没有差异，未生成盘点单。',
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
