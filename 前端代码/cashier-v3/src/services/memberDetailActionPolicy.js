// 会员详情的前端动作清单由门店和平台共用；后端仍按账号、组织范围和对象归属复核。
const memberDetailActionsByScope = {
  member: new Set(['open-member-more-actions', 'open-member-editor', 'open-recharge', 'open-gift']),
  'appointment-highlight': new Set(['open-reservation-detail']),
  card: new Set(['open-card-batch', 'open-card-benefits', 'query-member-card-history']),
  'balance-change': new Set(['open-operation-logs']),
  coupon: new Set(['open-operation-logs']),
  'point-change': new Set(['open-operation-logs']),
  'writeoff-record': new Set(['open-writeoff-records']),
  'sales-order': new Set(['open-sales-order-detail']),
  'exclusive-service-staff-change': new Set(['open-operation-logs']),
  'care-task': new Set(['open-operation-logs']),
  'care-record': new Set(['open-operation-logs']),
  'debt-record': new Set(['open-debt-settlements']),
  'gift-source': new Set(['open-sales-order-detail']),
  'gift-record': new Set(['open-gift-records', 'open-sales-order-detail']),
  'gift-coupon': new Set(['open-gift-records'])
}

export function memberDetailActionAllowed(scope, action) {
  return memberDetailActionsByScope[String(scope || '')]?.has(String(action || '')) === true
}
