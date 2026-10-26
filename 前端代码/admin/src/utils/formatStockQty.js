/**
 * 库存数量展示：院装固定两位，非院装固定整数
 * @param {number|string} qty
 * @param {boolean|number} isSalon
 * @returns {string}
 */
export function formatStockQty(qty, isSalon) {
  const n = Number(qty);
  if (!Number.isFinite(n)) return isSalon ? '0.00' : '0';
  if (isSalon) return n.toFixed(2);
  return String(Math.trunc(n));
}

/**
 * 单位展示：空值显示 -
 */
export function formatStockUnit(unit) {
  const s = unit == null ? '' : String(unit).trim();
  return s === '' ? '-' : s;
}
