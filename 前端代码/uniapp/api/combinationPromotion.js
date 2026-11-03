import request from '@/utils/request.js';

// 推广归属固定为当前登录会员，客户端不传会员 ID，也不从佣金订单拼接统计。
export function getCombinationPromotionSummary(params) {
  return request.get('combination/promotion/summary', params);
}
export function getCombinationPromotionOrders(params) {
  return request.get('combination/promotion/orders', params);
}
