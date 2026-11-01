/** 保留合法的总部读取范围；原单若属于门店仓，不让门店仓 ID 冒充总部仓 ID。 */
export function copyHeadquartersLocationId(kind, row, currentId, headquartersIds) {
  // 平台请货列表包含门店发起的单据；门店仓 ID 绝不能作为总部详情读取身份。
  const sourceIsHq = kind === 'request' ? row?.request_party_type === 'HQ'
    : kind === 'transfer' ? row?.from_party_type === 'HQ' : true
  const candidate = Number(kind === 'transfer' ? row?.from_location_id : row?.location_id)
  return sourceIsHq && headquartersIds.some((id) => Number(id) === candidate)
    ? candidate : Number(currentId) || 0
}

/** 只复制新单可编辑的商品身份和数量；原单的事实、批次分配、状态、流水号不能进入新命令。 */
export function documentCopyLines(kind, detail) {
  if (!Array.isArray(detail?.lines) || !detail.lines.length) throw new Error('原单据没有可复制的商品明细。')
  const lines = detail.lines.map((line) => {
    const productId = Number(kind === 'transfer' ? line.from_product_id : line.product_id)
    const skuId = Number(kind === 'transfer' ? line.from_sku_id : line.sku_id)
    const skuUnique = String((kind === 'transfer' ? line.from_sku_unique : line.sku_unique) ?? '')
    const quantity = kind === 'transfer'
      ? Number(line.requested_quantity_units) / (10 ** Number(line.quantity_scale || 0))
      : Number(line.quantity)
    if (!(productId > 0 && skuId > 0 && skuUnique && quantity > 0)) throw new Error('原单含有无法复制的商品明细，请核对后手工新建。')
    return {
      product_id: productId, sku_id: skuId, sku_unique: skuUnique,
      product_name: String(line.product_name ?? line.product_name_snapshot ?? ''),
      sku_name: String(line.sku_name ?? line.sku_name_snapshot ?? ''),
      stock_unit: String(line.stock_unit ?? line.stock_unit_snapshot ?? ''),
      barcode: String(line.barcode ?? line.barcode_snapshot ?? ''),
      quantity: String(quantity), detail_remark: String(line.detail_remark || ''),
      no_expiry_management: Number(line.no_expiry_management || 0), available_quantity: '-'
    }
  })
  if (kind === 'inbound') return lines.map((row, index) => ({
    ...row, batch_no: String(detail.lines[index].batch_no || ''),
    manufactured_date: String(detail.lines[index].manufactured_date || ''),
    expire_date: String(detail.lines[index].expire_date || ''),
    // 无成本查看权时留空；不能把受保护的历史成本填成零。
    unit_cost: detail.lines[index].unit_cost_cents == null ? '' : String(Number(detail.lines[index].unit_cost_cents) / 100)
  }))
  if (kind === 'outbound' || kind === 'usage') {
    // 已结算单可按批次拆成多条事实，新单仍按商品规格填写一次申请数量。
    const grouped = new Map()
    for (const row of lines) {
      const key = `${row.product_id}:${row.sku_id}:${row.sku_unique}`
      const existing = grouped.get(key)
      if (existing) existing.quantity = String(Number(existing.quantity) + Number(row.quantity))
      else grouped.set(key, row)
    }
    return [...grouped.values()]
  }
  return lines
}
