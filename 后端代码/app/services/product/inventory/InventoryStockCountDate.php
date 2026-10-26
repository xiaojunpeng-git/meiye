<?php
declare(strict_types=1);

namespace app\services\product\inventory;

/** 盘点日期只取完成盘点的服务端时间；草稿没有完成日期，不能借用保存日或业务归属日。 */
final class InventoryStockCountDate
{
    /** 输入服务端确认的 Unix 秒；未确认返回空值，已确认按中国门店日期返回 YYYY-MM-DD。 */
    public static function fromConfirmedAt(int $confirmedAt): string
    {
        if ($confirmedAt <= 0) return '';
        return (new \DateTimeImmutable('@' . $confirmedAt))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
    }
}
