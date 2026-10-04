/**
 * 空白代表未盘；所有非空实盘数都提交，不能用加载时账面快照过滤。
 * 是否产生调整由服务端在事务内比较提交时库存，保护期间发生的入出库事实。
 * 非法非空输入仍保留给服务端校验，不能悄悄丢弃用户试图提交的记录。
 */
export function countedRows(rows) {
  return rows.filter((row) => {
    const counted = String(row.counted_quantity ?? '').trim()
    if (!counted) return false
    return true
  })
}

/** Server line numbers count only submitted physical rows; map back to the full draft before highlighting. */
export function submittedCountRowIndex(rows, lineIndex) {
  const position = Number(lineIndex)
  if (!Number.isInteger(position) || position < 1) return -1
  const submitted = countedRows(rows)
  return position <= submitted.length ? rows.indexOf(submitted[position - 1]) : -1
}

/** The server decides gain from locked stock; the draft snapshot supplies the operator-facing row name. */
export function missingCountSurplusMessage(rows, rowIndex) {
  const row = rows[rowIndex] || {}
  const name = String(row.product_name || `商品ID ${row.product_id || '-'}`).trim()
  return `第 ${rowIndex + 1} 行「${name}」的盘盈批次资料不完整，请核对批号、单价、生产日期和到期日后再完成盘点。`
}

/**
 * 在请求服务端前定位首条不可结算的盘点明细。
 * 商品总量盘点的正数实盘行均需具备批次、成本和有效期资料；不依赖可能过期的账面快照。
 * 既有批次盘点可由服务端按已锁定批次补成本和日期，仍单独校验批号身份。
 */
export function validateCountSubmissionRows(rows) {
  const quantityPattern = /^\d+(?:\.\d{1,4})?$/
  const costPattern = /^\d+(?:\.\d{1,2})?$/
  const datePattern = /^\d{4}-\d{2}-\d{2}$/
  const batchIdentities = new Set()
  const skuModes = new Map()

  for (let rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
    const row = rows[rowIndex]
    const counted = String(row.counted_quantity ?? '').trim()
    if (!counted) continue

    const productName = String(row.product_name || `商品ID ${row.product_id || '-'}`).trim()
    const skuIdentity = `${row.product_id}:${row.sku_id}`
    const mode = row.count_mode === 'BATCH' ? 'BATCH' : 'SKU'
    if (skuModes.has(skuIdentity) && skuModes.get(skuIdentity) !== mode) return { valid: false, rowIndex,
      message: `第 ${rowIndex + 1} 行「${productName}」与前面同一商品的盘点方式不同，请保留按总量或按批次的一种方式。` }
    skuModes.set(skuIdentity, mode)
    if (row.count_mode === 'BATCH') {
      // 同规格允许不同批号，但空批号或重复批号必须定位到行，不可重复覆盖批次实盘。
      const batchNo = String(row.surplus_batch_no || '').trim()
      const identity = `${row.sku_id}:${batchNo}`
      if (!batchNo || batchIdentities.has(identity)) return { valid: false, rowIndex,
        message: `第 ${rowIndex + 1} 行「${productName}」${!batchNo ? '未填写批号' : '与前面的批号重复'}，请填写不同的批号后再完成盘点。` }
      batchIdentities.add(identity)
    }
    if (!quantityPattern.test(counted)) {
      return { valid: false, rowIndex, message: `第 ${rowIndex + 1} 行「${productName}」的实盘库存填写不正确，暂时无法完成盘点。请填写有效数量后再试。` }
    }
    // 正数总量可能因期间出入库变成盘盈；零实盘无需创建新批次，批次模式保留服务端已有批次资料口径。
    if (mode !== 'SKU' || Number(counted) === 0) continue

    const missing = []
    const batchNo = String(row.surplus_batch_no || '').trim()
    const unitCost = String(row.surplus_unit_cost ?? '').trim()
    const manufacturedDate = String(row.surplus_manufactured_date || '').trim()
    const expireDate = String(row.surplus_expire_date || '').trim()
    if (!batchNo) missing.push('批次号')
    if (!unitCost) missing.push('单价')
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
