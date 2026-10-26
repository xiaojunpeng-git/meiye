// R49 edits use per-row TOTAL amounts. Never divide by times or overwrite catalogue unit prices.
export function ordinaryCardRows(item) {
  const purchase = item?.cardPurchaseSnapshot
  const base = purchase?.ordinarySaleBaseline?.definition?.purchase
  if (purchase?.ruleType !== 'normal' || !Array.isArray(base?.components)) return []
  return purchase.components.map((row, index) => {
    const original = base.components[index]
    const minimumCents = Number(original.configuredPriceCents) * Number(original.writeTimes)
    return {
      relationId: row.relationId, name: row.nameSnapshot,
      specification: row.skuNameSnapshot,
      minimumCents, minimumTimes: original.writeTimes,
      amount: String((row.configuredAmountCents ?? minimumCents) / 100),
      times: String(row.writeTimes)
    }
  })
}

function integer(value, maximum) {
  const text = String(value)
  if (!/^(0|[1-9][0-9]*)$/.test(text)) throw new Error('金额和次数必须填写整数。')
  const number = Number(text)
  if (!Number.isSafeInteger(number) || number > maximum) throw new Error('金额或次数超出允许范围。')
  return number
}

// The preview, cart and checkout all validate the same business values. This
// is an early UX guard, not a second authority snapshot or a signature verifier.
export function validateOrdinaryCardSale(purchase, amountCents, quantity = 1) {
  if (purchase?.ruleType !== 'normal' || !purchase.ordinarySaleBaseline) return
  const baseline = purchase.ordinarySaleBaseline.definition
  const original = baseline?.purchase?.components
  if (!Array.isArray(original) || !original.length
    || !Array.isArray(purchase.components) || original.length !== purchase.components.length) {
    throw new Error('卡内项目不完整，请重新选择。')
  }
  let increase = 0
  let changed = false
  original.forEach((base, index) => {
    const row = purchase.components[index]
    const label = base.nameSnapshot || `第${index + 1}个项目`
    // Row identity may never be replaced to offset another row's minimum.
    for (const key of ['relationId', 'projectId', 'skuId', 'unique']) {
      if (String(row[key] ?? '') !== String(base[key] ?? '')) throw new Error(`${label}不得更换或删减。`)
    }
    const times = integer(row.writeTimes, 99999999)
    const minimum = Number(base.configuredPriceCents) * Number(base.writeTimes)
    const total = row.configuredAmountCents ?? minimum
    if (!Number.isSafeInteger(total) || total > 99999999900 || total % 100 !== 0 || total < minimum
      || times < Number(base.writeTimes)) throw new Error(`${label}的总金额和次数不能低于原配置，且必须为整数。`)
    if (times !== Number(base.writeTimes) && row.configuredAmountCents == null) {
      throw new Error(`${label}修改次数时必须保留项目总金额。`)
    }
    increase += total - minimum
    changed ||= total !== minimum || times !== Number(base.writeTimes)
  })
  // Unedited cards retain the pre-existing manual-price business rule.
  if (!changed) return
  const minimumPrice = (Number(baseline.priceCents) + increase) * quantity
  if (!Number.isSafeInteger(quantity) || quantity < 1 || !Number.isSafeInteger(amountCents)
    || amountCents % 100 !== 0 || !Number.isSafeInteger(minimumPrice) || amountCents < minimumPrice) {
    throw new Error('普通卡售价不能低于原售价加各项目金额增加额。')
  }
}

// Produces a new sale intent; the signed baseline and every noneditable field remain intact.
export function applyOrdinaryCardRows(item, rows) {
  if (item?.ordinaryCardSaleEditable !== true) throw new Error('普通卡销售修改未开启。')
  const next = JSON.parse(JSON.stringify(item))
  const originalRows = ordinaryCardRows(item)
  if (!originalRows.length || rows.length !== originalRows.length) throw new Error('卡内项目不完整，请重新选择。')
  let increase = 0
  next.cardPurchaseSnapshot.components.forEach((component, index) => {
    const baseline = originalRows[index]
    const row = rows[index]
    const amountCents = integer(row.amount, 999999999) * 100
    const times = integer(row.times, 99999999)
    if (row.relationId !== baseline.relationId || amountCents < baseline.minimumCents || times < baseline.minimumTimes) {
      throw new Error(`${baseline.name}的总金额和次数不能低于原配置。`)
    }
    component.configuredAmountCents = amountCents
    component.writeTimes = times
    increase += amountCents - baseline.minimumCents
    next.cardPreview.components[index].writeTimes = times
  })
  const price = next.cardPurchaseSnapshot.ordinarySaleBaseline.definition.priceCents + increase
  if (!Number.isSafeInteger(price) || price % 100 !== 0) throw new Error('整卡金额必须为整数元。')
  next.price = price / 100
  validateOrdinaryCardSale(next.cardPurchaseSnapshot, price)
  return next
}
