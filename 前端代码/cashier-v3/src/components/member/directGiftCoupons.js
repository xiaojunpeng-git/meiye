/** The picker carries template capability; only opted-in reusable coupons submit a face. */
export function giftCouponEntry(item) {
  const couponType = Number(item.coupon_type || 1)
  return {
    couponType,
    faceEditable: couponType === 3 && Number(item.allow_gift_face_edit) === 1,
    // Database monetary strings use .00; an editable whole-yuan draft displays digits only.
    couponFaceValue: couponType === 3 ? String(item.coupon_price ?? '').replace(/\.0+$/, '') : String(item.coupon_price ?? '')
  }
}

export function validGiftFace(value) {
  // Same whole-yuan boundary as the reusable allowance ledger; no template-price comparison.
  return /^[1-9]\d{0,8}$/.test(String(value ?? ''))
}

export function giftFacePayload(item) {
  return item.kind === 'coupon' && item.faceEditable
    ? { couponFaceValue: String(item.couponFaceValue) } : {}
}
