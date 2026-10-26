// 只消费后端对本卡签发的可转金额和资格；不得由余次、购买价或会员总欠款推算。
export function cardToBalanceUnavailableReason(source = {}) {
  const transfer = source.balanceTransfer
  if (!transfer || transfer.eligible !== true) {
    return String(transfer?.unavailableReason || '该卡当前不可转余额，请重新加载权益。')
  }
  const amount = transfer.amountCents
  if (!Number.isSafeInteger(amount) || amount <= 0 || amount % 100 !== 0) {
    return '该卡没有可转出的整数元剩余金额。'
  }
  return ''
}

// 该对象就是本次唯一业务快照，确认金额用于服务端锁定后比对，绝非入账权威。
export function cardToBalancePayload(source, memberId, sourceCardHolderId, reason) {
  const unavailable = cardToBalanceUnavailableReason(source)
  if (unavailable) throw new Error(unavailable)
  if (!/^[1-9]\d*$/.test(String(memberId || '')) || !/^[1-9]\d*$/.test(String(sourceCardHolderId || ''))) {
    throw new Error('会员或来源卡数据不完整，请重新选择。')
  }
  const normalizedReason = String(reason || '').trim()
  if (!normalizedReason || normalizedReason.length > 500) throw new Error('请填写不超过500字的操作原因。')
  return {
    operationType: 'card_to_balance',
    memberId: String(memberId),
    sourceCardHolderId: String(sourceCardHolderId),
    confirmedAmountCents: source.balanceTransfer.amountCents,
    reason: normalizedReason
  }
}
