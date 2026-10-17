const RECEIPT_TYPES = Object.freeze({
  recharge: { numberKey: 'rechargeOrderNo', title: '充值订单小票', heading: '充值明细' },
  supplement: { numberKey: 'supplementOrderNo', title: '补交记录小票', heading: '补交明细' },
  refund: { numberKey: 'refundOrderNo', title: '退款记录小票', heading: '退款明细' }
})

function validAmount(value) {
  if (value === null || value === undefined || String(value).trim() === '') return null
  const amount = Number(value)
  return Number.isFinite(amount) && amount >= 0 ? amount : null
}

/**
 * 打印前按业务单号和业务日重新读取服务端授权列表，并用原始业务 ID 精确匹配。
 * 只读投影可能同名或已作废；不使用页面旧行补足缺失金额或状态。
 */
export function businessReceiptRefreshQuery(recordType, record) {
  const config = RECEIPT_TYPES[recordType]
  const id = String(record?.id || '').trim()
  const orderNo = String(record?.[config?.numberKey] || '').trim()
  const businessDate = String(record?.businessDate || '').trim()
  if (!config || !id || !orderNo) return null
  const exactDate = /^\d{4}-\d{2}-\d{2}$/.test(businessDate) && businessDate !== '0000-00-00'
    ? { businessDateFrom: businessDate, businessDateTo: businessDate } : {}
  return { recordType, keyword: orderNo, ...exactDate, dataScope: 'all', page: 1, pageSize: 100 }
}

export function refreshedBusinessReceiptRecord(partition, recordType, original) {
  const config = RECEIPT_TYPES[recordType]
  const rows = partition?.recordsByType?.[recordType]
  if (!config || !Array.isArray(rows)) return null
  const id = String(original?.id || '').trim()
  const orderNo = String(original?.[config.numberKey] || '').trim()
  return rows.find((row) => String(row?.id || '') === id && String(row?.[config.numberKey] || '') === orderNo) || null
}

/**
 * 订单中心的充值、补交、退款小票只搬运业务权威投影。
 * 充值历史实收未就绪时不把充值面额当收款；退款金额是系统登记的现金退款，
 * 不声称支付渠道已完成对外退款。
 */
export function businessReceiptFromRecord(recordType, record) {
  const config = RECEIPT_TYPES[recordType]
  if (!config || !record || typeof record !== 'object') return null
  const orderNo = String(record[config.numberKey] || '').trim()
  const common = {
    salesOrderNo: orderNo,
    storeName: record.storeName,
    memberName: record.memberName,
    businessDate: record.businessDate,
    cashierName: record.operatorName,
    receiptMode: 'sales',
    items: [],
    paymentDetails: [],
    businessReceipt: { title: config.title, detailHeading: config.heading, metaRows: [], summaryRows: [], showPaymentSection: false }
  }
  if (!orderNo) return null

  if (recordType === 'recharge') {
    const recharge = validAmount(record.rechargeAmount)
    const gift = validAmount(record.giftAmount)
    const received = record.economicsDataStatus === 'ready' ? validAmount(record.actualReceivedAmount) : null
    if (recharge === null || gift === null) return null
    common.items = [{ name: record.rechargePlan || '会员储值', quantity: 1, amount: recharge }]
    common.businessReceipt.summaryRows = [
      { label: '充值金额', value: recharge },
      { label: '赠送金额', value: gift },
      ...(received === null ? [] : [{ label: '记账收款', value: received }])
    ]
    common.businessReceipt.metaRows = [
      { label: '状态', value: record.orderStatus || record.paymentStatus },
      { label: '收款方式', value: record.paymentMethod },
      ...(received === null ? [{ label: '实收数据', value: '未就绪' }] : [])
    ]
    // 充值列表只给收款方式文本和记账收款合计，不能虚构各渠道分摊明细。
    return common
  }

  if (recordType === 'supplement') {
    const amount = validAmount(record.supplementAmount)
    if (amount === null) return null
    common.items = [{ name: record.productName || record.debtSummary || '欠款补交', quantity: 1, amount }]
    common.paymentDetails = [{ methodName: record.paymentMethod || '未标注', amount }]
    common.businessReceipt.showPaymentSection = true
    common.businessReceipt.summaryRows = [{ label: '补交金额', value: amount }]
    common.businessReceipt.metaRows = [
      { label: '原单号', value: record.sourceOrderNo },
      { label: '欠款单号', value: record.debtNo },
      { label: '状态', value: record.paymentStatus }
    ]
    return common
  }

  const refund = validAmount(record.refundAmount)
  if (refund === null) return null
  common.items = [{ name: record.productName || '退款业务', quantity: 1, amount: refund }]
  common.businessReceipt.summaryRows = [{ label: '登记现金退款', value: refund }]
  common.businessReceipt.metaRows = [
    { label: '原单号', value: record.sourceOrderNo },
    { label: '状态', value: record.refundStatus },
    { label: '登记方式', value: record.refundMethod },
    { label: '退款内容', value: record.refundSummary }
  ]
  return common
}
