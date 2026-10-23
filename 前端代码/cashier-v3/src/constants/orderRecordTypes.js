/** 订单查询类型的共同登记：顶部入口与原查询页共用身份和名称，路由只携带原 tab，不增加业务权限。 */
export const ORDER_RECORD_TYPES = Object.freeze([
  { key: 'all', label: '全部订单' },
  { key: 'sales', label: '消费订单' },
  { key: 'recharge', label: '充值订单' },
  { key: 'refund', label: '退款记录' },
  { key: 'debt', label: '欠款管理' },
  { key: 'service', label: '服务记录' },
  { key: 'supplement', label: '补交记录' },
  { key: 'gift', label: '赠送记录' },
  { key: 'card_operation', label: '卡操作记录' }
])
export const orderRecordTypeLabel = key => ORDER_RECORD_TYPES.find(type => type.key === key)?.label || ''
