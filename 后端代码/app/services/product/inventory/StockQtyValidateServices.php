<?php
namespace app\services\product\inventory;

use app\services\BaseServices;
use mohe\exceptions\AdminException;

/**
 * 库存数量精度统一校验：普通产品整数，院装最多 2 位小数
 */
class StockQtyValidateServices extends BaseServices
{
    /**
     * @param mixed $qty
     * @param int $decimalScale 最终业务精度（0 或 2）
     * @param string $goodsLabel 商品名称/规格，用于提示
     * @param int|null $excelRow Excel 真实行号
     */
    public function assertQty($qty, int $decimalScale, string $goodsLabel = '', ?int $excelRow = null): string
    {
        if ($qty === '' || $qty === null) {
            throw new AdminException($this->prefix($excelRow) . $this->label($goodsLabel) . '数量不能为空');
        }
        if (!is_numeric($qty)) {
            throw new AdminException($this->prefix($excelRow) . $this->label($goodsLabel) . '数量格式不正确');
        }
        $str = trim((string)$qty);
        if (bccomp($str, '0', 4) < 0) {
            throw new AdminException($this->prefix($excelRow) . $this->label($goodsLabel) . '数量不能为负数');
        }
        $scale = $decimalScale > 0 ? 2 : 0;
        if ($scale === 0) {
            if (strpos($str, '.') !== false) {
                // 允许 10.0 / 10.00 视为整数
                if (bccomp($str, (string)(int)$str, 4) !== 0) {
                    throw new AdminException($this->prefix($excelRow) . $this->label($goodsLabel) . '只允许整数');
                }
            }
            return (string)(int)bcmul($str, '1', 0);
        }
        if (preg_match('/\.\d{3,}$/', $str)) {
            throw new AdminException($this->prefix($excelRow) . $this->label($goodsLabel) . '最多保留 2 位小数');
        }
        return bcadd($str, '0', 2);
    }

    /**
     * 根据是否院装解析业务精度
     */
    public function resolveScale(int $productType, int $isInventory, int $salonStockEnabled): int
    {
        if ($productType === 0 && $isInventory === 1 && $salonStockEnabled === 1) {
            return 2;
        }
        return 0;
    }

    protected function label(string $goodsLabel): string
    {
        return $goodsLabel !== '' ? ('商品【' . $goodsLabel . '】') : '商品';
    }

    protected function prefix(?int $excelRow): string
    {
        return $excelRow ? ('第' . $excelRow . '行：') : '';
    }
}
