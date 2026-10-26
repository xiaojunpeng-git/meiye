/** 即时改价提示只比较商品单价；0为该侧不限，最终授权仍由后端判断。 */
export function salePriceRangeMessage(name, amountCents, quantity, range) {
  if (!range || !Number.isSafeInteger(range.minimumCents) || !Number.isSafeInteger(range.maximumCents)) {
    return '请重新添加该商品后改价。'
  }
  const minimum = range.minimumCents
  const maximum = range.maximumCents
  if ((minimum > 0 && amountCents < minimum * quantity)
    || (maximum > 0 && amountCents > maximum * quantity)) {
    return `${String(name || '该商品')}的售价区间是:最低${Math.ceil(minimum / 100)}元,最高${maximum > 0 ? `${Math.floor(maximum / 100)}元` : '不限'}`
  }
  return ''
}
