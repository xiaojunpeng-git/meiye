import request from '@/plugins/request';

/**
 * 更新日志列表（只读）
 */
export function changelogListApi(params) {
  return request({
    url: 'changelog',
    method: 'get',
    params,
  });
}

/**
 * 更新日志详情（只读）
 */
export function changelogInfoApi(id) {
  return request({
    url: `changelog/${id}`,
    method: 'get',
  });
}

/**
 * 未读信息
 */
export function changelogUnreadApi() {
  return request({
    url: 'changelog/unread',
    method: 'get',
  });
}
