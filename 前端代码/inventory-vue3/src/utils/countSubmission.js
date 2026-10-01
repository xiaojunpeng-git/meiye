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

/**
 * 在请求服务端前定位首条不可结算的盘点明细。
 * 只校验实际产生库存差异的行；盘盈会新建或合并批次，因此必须具备可追溯的批次、成本和有效期信息。
 */
export function validateCountSubmissionRows(rows) {
  const quantityPattern = /^\d+(?:\.\d{1,4})?$/
  const costPattern = /^\d+(?:\.\d{1,2})?$/
  const datePattern = /^\d{4}-\d{2}-\d{2}$/

  for (let rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
    const row = rows[rowIndex]
    const counted = String(row.counted_quantity ?? '').trim()
    if (!counted || Number(counted) === Number(row.book_quantity)) continue

    const productName = String(row.product_name || `商品ID ${row.product_id || '-'}`).trim()
    if (!quantityPattern.test(counted)) {
      return { valid: false, rowIndex, message: `第 ${rowIndex + 1} 行「${productName}」的实盘库存填写不正确，暂时无法完成盘点。请填写有效数量后再试。` }
    }
    if (Number(counted) <= Number(row.book_quantity)) continue

    const missing = []
    const batchNo = String(row.surplus_batch_no || '').trim()
    const unitCost = String(row.surplus_unit_cost ?? '').trim()
    const manufacturedDate = String(row.surplus_manufactured_date || '').trim()
    const expireDate = String(row.surplus_expire_date || '').trim()
    if (!batchNo) missing.push('盘盈批次号')
    if (!unitCost) missing.push('盘盈单价')
    if (!manufacturedDate) missing.push('生产日期')
    if (!expireDate) missing.push('到期日')
    if (missing.length) {
      return { valid: false, rowIndex, message: `第 ${rowIndex + 1} 行「${productName}」未填写${missing.join('、')}，暂时无法完成盘点。请补充后再试。` }
    }
    if (!costPattern.test(unitCost)) {
      return { valid: false, rowIndex, message: `第 ${rowIndex + 1} 行「${productName}」的盘盈单价填写不正确，暂时无法完成盘点。请填写不小于 0、最多两位小数的金额。` }
    }
    if (!datePattern.test(manufacturedDate) || !datePattern.test(expireDate) || manufacturedDate > expireDate) {
      return { valid: false, rowIndex, message: `第 ${rowIndex + 1} 行「${productName}」的生产日期或到期日不正确，暂时无法完成盘点。请重新选择，且到期日不能早于生产日期。` }
    }
  }

  return { valid: true, rowIndex: -1, message: '' }
}
