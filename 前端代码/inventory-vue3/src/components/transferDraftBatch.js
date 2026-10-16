function party(key) {
  const [type, rawId] = String(key || '').split(':', 2)
  const id = Number(rawId)
  if (!['HQ', 'STORE'].includes(type) || !Number.isInteger(id) || id <= 0) throw new Error('调拨明细的调出方或调入方无效。')
  return { type, id }
}

function quantity(value) {
  const text = String(value ?? '').trim()
  if (!/^\d+(?:\.\d{1,4})?$/.test(text) || Number(text) <= 0) throw new Error('调拨数量须大于零且最多四位小数。')
  return text
}

/** Expand multi-destination rows into one authoritative document per source/target pair. */
export function planTransferDraftBatch(rows, { platform, storeSourceKey, businessDate, remark, staffId, hqLocationId }) {
  if (!Array.isArray(rows) || !rows.length) throw new Error('请添加调拨商品。')
  const groups = new Map()
  let lineCount = 0
  for (const [rowIndex, row] of rows.entries()) {
    const sourceKey = platform ? row.sourceKey : storeSourceKey
    const source = platform ? party(sourceKey) : null
    if (!row.product || Number(row.product.product_id) <= 0 || Number(row.product.sku_id) <= 0 || !String(row.product.sku_unique || '').trim()) throw new Error(`第 ${rowIndex + 1} 行未选择有效商品。`)
    if (!Array.isArray(row.destinations) || !row.destinations.length) throw new Error(`第 ${rowIndex + 1} 行未选择调入方。`)
    for (const destination of row.destinations) {
      const target = party(destination.key)
      if (platform && sourceKey === destination.key) throw new Error(`第 ${rowIndex + 1} 行调出方与调入方相同。`)
      const groupKey = `${sourceKey}>${destination.key}`
      let group = groups.get(groupKey)
      if (!group) {
        group = {
          business_date: businessDate, remark: String(remark || ''),
          target_party_type: target.type, target_store_id: target.type === 'STORE' ? target.id : 0,
          target_location_id: target.type === 'HQ' ? target.id : 0,
          request_document_id: 0, lines: [],
          ...(platform ? {
            hq_location_id: source.type === 'HQ' ? source.id : Number(hqLocationId),
            source_party_type: source.type,
            source_store_id: source.type === 'STORE' ? source.id : 0,
            transfer_staff_id: Number(staffId) || 0
          } : {})
        }
        groups.set(groupKey, group)
      }
      if (group.lines.some((line) => Number(line.sku_id) === Number(row.product.sku_id))) throw new Error(`第 ${rowIndex + 1} 行与同方向已有商品重复。`)
      group.lines.push({
        product_id: Number(row.product.product_id), sku_id: Number(row.product.sku_id),
        sku_unique: String(row.product.sku_unique), quantity: quantity(destination.quantity),
        request_line_id: 0, detail_remark: String(row.detailRemark || '').trim()
      })
      lineCount += 1
    }
  }
  if (groups.size > 200 || lineCount > 500 || [...groups.values()].some((group) => group.lines.length > 100)) {
    throw new Error('一次最多生成 200 张草稿、500 条明细，每张草稿最多 100 项。')
  }
  return [...groups.values()]
}
