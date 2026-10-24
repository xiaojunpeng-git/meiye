/** A browser choice is only a draft; final checkout locks the allowance again. */
export function allowanceAmountCents(raw, coupon, lineCapacityCents) {
  if (!/^[1-9][0-9]{0,8}$/.test(String(raw))) return null
  const amount = Number(raw) * 100
  return validAllowanceCents(amount, coupon, lineCapacityCents) ? amount : null
}

/** Never round/clamp a command: the entered whole-yuan amount is authoritative intent. */
export function validAllowanceCents(amount, coupon, lineCapacityCents) {
  const remaining = Number(coupon?.remainingAmountCents)
  const cap = Math.min(Number(coupon?.discountAmountCents), Number(lineCapacityCents))
  return Number(coupon?.couponType) === 3 && Number.isSafeInteger(amount) && amount > 0
    && amount % 100 === 0 && Number.isSafeInteger(remaining) && amount <= remaining
    && Number.isSafeInteger(cap) && amount <= cap
}
