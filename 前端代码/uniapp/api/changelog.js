import request from '@/utils/request.js';

/**
 * 更新日志列表
 */
export function getChangelogList(params) {
  return request.get('changelog/list', params, { noAuth: true });
}

/**
 * 更新日志详情
 */
export function getChangelogDetail(id) {
  return request.get(`changelog/detail/${id}`, {}, { noAuth: true });
}

/**
 * 未读信息
 */
export function getChangelogUnread() {
  return request.get('changelog/unread', {}, { noAuth: true });
}
