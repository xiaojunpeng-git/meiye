import request from '@/plugins/request';

/**
 * 更新日志列表（只读）
 */
export function changelogListApi(params) {
  return request({
    url: 'system/changelog',
    method: 'get',
    params,
  });
}

/**
 * 更新日志详情（只读）
 */
export function changelogInfoApi(id) {
  return request({
    url: `system/changelog/${id}`,
    method: 'get',
  });
}
