import assert from 'node:assert/strict'
import { giftCouponEntry, giftFacePayload, validGiftFace } from '../../../前端代码/cashier-v3/src/components/member/directGiftCoupons.js'
import { nextWholeYuanInputValue } from '../../../前端代码/cashier-v3/src/services/refundWholeYuanInput.js'

const coupon = { coupon_type: 3, coupon_price: '297.00' }
assert.equal(giftCouponEntry(coupon).faceEditable, false)
assert.equal(giftCouponEntry(coupon).couponFaceValue, '297')
assert.equal(giftCouponEntry({ ...coupon, coupon_price: '100.00' }).couponFaceValue, '100')
assert.equal(giftCouponEntry({ ...coupon, allow_gift_face_edit: 1 }).faceEditable, true)
for (const type of [1, 2]) assert.equal(giftCouponEntry({ ...coupon, coupon_type: type, allow_gift_face_edit: 1 }).faceEditable, false)
assert.deepEqual(giftFacePayload({ kind: 'project', faceEditable: true, couponFaceValue: '100' }), {})
assert.deepEqual(giftFacePayload({ kind: 'coupon', faceEditable: false, couponFaceValue: '100' }), {})
assert.deepEqual(giftFacePayload({ kind: 'coupon', faceEditable: true, couponFaceValue: '100' }), { couponFaceValue: '100' })
for (const value of ['1', '100', '1000', '297']) assert.equal(validGiftFace(value), true)
for (const value of ['', '0', '-1', '2.5', '100.00', 'abc', '1e2']) assert.equal(validGiftFace(value), false)
for (const value of ['100.5', '100.00', '1e3', '-100', 'abc']) assert.equal(nextWholeYuanInputValue('100', value), '100')
assert.equal(nextWholeYuanInputValue('100', '250'), '250')
assert.equal(nextWholeYuanInputValue('100', ''), '')
console.log('PASS coupon type/switch, lower and higher whole-yuan inputs, direct-gift-only payload')
