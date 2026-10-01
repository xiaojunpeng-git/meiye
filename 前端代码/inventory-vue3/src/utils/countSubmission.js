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

/**
 * 用服务端返回的稳定商品身份定位草稿行，不依赖已删除目录的名称或当前排序。
 * 返回的索引按草稿显示顺序排列，用于同时高亮全部问题行并滚动到首行。
 */
export function invalidCountCatalogRowIndexes(rows, invalidLines) {
  const invalidIdentities = new Set((Array.isArray(invalidLines) ? invalidLines : []).map((line) => (
    `${Number(line?.product_id)}:${Number(line?.sku_id)}:${String(line?.sku_unique || '')}`
  )))
  return rows.reduce((indexes, row, index) => {
    const identity = `${Number(row?.product_id)}:${Number(row?.sku_id)}:${String(row?.sku_unique || '')}`
    if (invalidIdentities.has(identity)) indexes.push(index)
    return indexes
  }, [])
}

/**
 * 删除明细后同步问题行索引：被删除的问题行必须移除，后续行索引必须前移。
 * 这样不会把原问题行的红色状态错误地转移到下一件正常商品。
 */
export function reindexCountValidationRowsAfterRemoval(indexes, removedIndex) {
  return (Array.isArray(indexes) ? indexes : [])
    .filter((index) => index !== removedIndex)
    .map((index) => index > removedIndex ? index - 1 : index)
}

/** 使用草稿快照中的商品名称生成门店员工可直接处理的行级提示。 */
export function invalidCountCatalogMessage(rows, indexes) {
  const labels = indexes.map((index) => {
    const row = rows[index] || {}
    return `第 ${index + 1} 行「${String(row.product_name || `商品ID ${row.product_id || '-'}`)}」`
  })
  return `${labels.join('、')}已从当前门店商品目录删除，暂时无法完成盘点。请先恢复商品，或删除这些盘点行后再提交。`
}
