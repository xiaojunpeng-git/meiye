<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\InventoryStockCountDate;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryContract;

function countDateAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// 完成时刻由服务端保存；跨午夜按门店业务时区归日，未完成草稿不得拥有盘点日期。
countDateAssert(InventoryStockCountDate::fromConfirmedAt(0) === '', 'draft has no count date');
countDateAssert(InventoryStockCountDate::fromConfirmedAt(strtotime('2026-09-27 15:59:59 UTC')) === '2026-09-27', 'before Shanghai midnight');
countDateAssert(InventoryStockCountDate::fromConfirmedAt(strtotime('2026-09-27 16:00:00 UTC')) === '2026-09-28', 'Shanghai midnight');
$fields = array_column(InventoryOperationalUnifiedQueryContract::definition('inventory_count')['fields'], null, 'key');
countDateAssert(($fields['count_date']['label'] ?? '') === '盘点日期', 'completion date is queryable');
countDateAssert(($fields['business_date']['label'] ?? '') === '业务日期' && !($fields['business_date']['defaultQuick'] ?? true), 'saved attribution-date settings remain compatible without claiming to be completion date');
echo "COUNT_DATE_CONTRACT_OK\n";
