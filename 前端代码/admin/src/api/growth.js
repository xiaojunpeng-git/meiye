import request from '@/plugins/request';
import Vue from 'vue';
import util from '@/libs/util';

/** 成长菜单内通用操作共用请求；待确认请求重试沿用原标识，无表单版本参数。 */
const pending = new Map();
export const growthRead = (path, params = {}) => request({ url: `growth/${path}`, method: 'get', params });
export async function growthWrite(path, data) {
  const signature = `${path}:${JSON.stringify(data)}`;
  if (!pending.has(signature)) { pending.set(signature, `growth-${Date.now()}-${Math.random().toString(36).slice(2)}`); }
  const result = await request({
    url: `growth/${path}`,
    method: 'post',
    data: { ...data, idempotency_key: pending.get(signature) }
  });
  pending.delete(signature);
  return result;
}
export function growthUpload(file, menu = 'content') {
  const data = new FormData();
  data.append('file', file);
  data.append('menu', menu);
  return request({ url: 'growth/asset/upload', method: 'post', data });
}
/** 预览通过认证取二进制，不把可公开猜测的存储地址交给课程。 */
export async function growthPreview(id, menu = 'content', taskId = 0) {
  const token = Vue.prototype.__getToken() || util.cookies.get('token');
  const response = await fetch(
    `/adminapi/growth/asset?id=${encodeURIComponent(id)}&menu=${encodeURIComponent(
      menu
    )}&task_id=${encodeURIComponent(taskId)}`,
    { headers: { 'Authori-zation': `Bearer ${token}`, 'X-Source': 'f76d38d0ee4f854f' }}
  );
  if (!response.ok || (response.headers.get('content-type') || '').includes('json')) { throw new Error('预览失败，请检查资源状态及权限'); }
  return URL.createObjectURL(await response.blob());
}

/** 日期输入使用本地日历日期，避免凌晨被UTC日期回退到前一天。 */
export function growthDate(value = new Date()) {
  return (
    value.getFullYear() +
    '-' +
    String(value.getMonth() + 1).padStart(2, '0') +
    '-' +
    String(value.getDate()).padStart(2, '0')
  );
}
