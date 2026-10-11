/** 只替换赠送草稿中的产品选择；项目、券及已有产品的数量和有效期保持原样。 */
export function reconcileGiftProducts(selected, rows) {
  const existing = new Map(selected.filter((item) => item.kind === 'product').map((item) => [String(item.catalogItemId), item]))
  const products = new Map()
  for (const row of rows) {
    const id = String(row.sku_id)
    if (!Number.isSafeInteger(Number(id)) || Number(id) <= 0) continue
    products.set(id, {
      ...row, key: `catalog:${id}`, kind: 'product', catalogItemId: id,
      name: row.product_name, specification: row.sku_name || '',
      quantity: existing.get(id)?.quantity ?? 1, validityEnd: existing.get(id)?.validityEnd || ''
    })
  }
  return [...selected.filter((item) => item.kind !== 'product'), ...products.values()]
}
