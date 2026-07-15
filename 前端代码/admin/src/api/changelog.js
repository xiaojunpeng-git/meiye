import request from '@/plugins/request';

/**
 * 更新日志列表
 */
export function changelogListApi(params) {
  return request({
    url: 'setting/changelog',
    method: 'get',
    params,
  });
}

/**
 * 更新日志详情
 */
export function changelogInfoApi(id, params = {}) {
  return request({
    url: `setting/changelog/${id}`,
    method: 'get',
    params,
  });
}

/**
 * 新增更新日志
 */
export function changelogCreateApi(data) {
  return request({
    url: 'setting/changelog',
    method: 'post',
    data,
  });
}

/**
 * 编辑更新日志
 */
export function changelogUpdateApi(id, data) {
  return request({
    url: `setting/changelog/${id}`,
    method: 'put',
    data,
  });
}

/**
 * 发布更新日志
 */
export function changelogPublishApi(id) {
  return request({
    url: `setting/changelog/publish/${id}`,
    method: 'put',
  });
}

/**
 * 下架更新日志
 */
export function changelogOfflineApi(id, data) {
  return request({
    url: `setting/changelog/offline/${id}`,
    method: 'put',
    data,
  });
}

/**
 * 删除草稿
 */
export function changelogDeleteApi(id) {
  return request({
    url: `setting/changelog/${id}`,
    method: 'delete',
  });
}

/**
 * 复制为草稿
 */
export function changelogCopyApi(id) {
  return request({
    url: `setting/changelog/copy/${id}`,
    method: 'post',
  });
}
