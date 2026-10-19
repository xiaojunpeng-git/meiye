import request from '@/utils/request.js';

// Member identities only; server resolves prices, business sources and allocations.
export const quoteMemberCommerce = data => request.post('member/commerce/quote', data);
export const memberCommerceCartEntry = cartIds => request.get('member/commerce/cart-entry', {cart_ids: cartIds});
export const createMemberCommerce = data => request.post('member/commerce/create', data);
export const getMemberCommerce = id => request.get('member/commerce/detail/' + encodeURIComponent(id));
export const listMemberCommerce = data => request.get('member/commerce/list', data || {});
export const payMemberCommerce = (id, data) => request.post('member/commerce/pay/' + encodeURIComponent(id), data);
export const cancelMemberCommerce = id => request.post('member/commerce/cancel/' + encodeURIComponent(id), {});
