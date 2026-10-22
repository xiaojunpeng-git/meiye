import request from '@/plugins/request';

// 接口位于现有 adminapi/user 路由组，保留组内 user 前缀，与单人调店一致。
// 批次由后端冻结名单；浏览器只提交门店、原因、确认人数和幂等键，不上传会员UID列表。
export function previewMemberStoreTransfer(storeId) {
  return request({ url: `user/user/store_transfer_batch/preview/${storeId}`, method: 'get' });
}
export function createMemberStoreTransfer(data) {
  return request({ url: 'user/user/store_transfer_batch', method: 'post', data });
}
export function memberStoreTransferStatus(batchNo, page = 1) {
  return request({ url: `user/user/store_transfer_batch/${batchNo}`, method: 'get', params: { page }});
}
export function resumeMemberStoreTransfer(batchNo) {
  return request({ url: `user/user/store_transfer_batch/${batchNo}/resume`, method: 'post' });
}
