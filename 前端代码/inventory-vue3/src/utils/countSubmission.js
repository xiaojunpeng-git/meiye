/**
 * 加载商品只形成待盘草稿；确认时只提交实际库存与账面库存不同的规格。
 * 空白代表未盘，等值代表未产生库存调整，二者都不应生成盘点明细。
 * 非法非空输入仍保留给服务端校验，不能悄悄丢弃用户试图提交的记录。
 */
export function changedCountRows(rows) {
  return rows.filter((row) => {
    const counted = String(row.counted_quantity ?? '').trim()
    if (!counted) return false
    return Number(counted) !== Number(row.book_quantity)
  })
}
