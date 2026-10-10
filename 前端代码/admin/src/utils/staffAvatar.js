import Setting from '@/setting';
import maleAvatar from '../../public/static/images/staff/avatar_male.png';
import femaleAvatar from '../../public/static/images/staff/avatar_female.png';

// 默认图直接进入前端资源包；表单仍用稳定标识，不能把构建哈希或 data URI 写进人员资料。
export const DEFAULT_STAFF_AVATAR = '/static/images/staff/avatar_male.png';
const DEFAULT_IMAGES = { male: maleAvatar, female: femaleAvatar };

/** 兼容已保存的男女默认图及旧 SVG 地址，不把同名的自定义上传图片误当成默认头像。 */
function defaultAvatarType(url) {
  const match = String(url || '').trim().match(/\/static\/images\/staff\/avatar_(male|female)\.(?:svg|png)(?:[?#]|$)/i);
  return match ? match[1].toLowerCase() : '';
}

/** 列表与编辑预览共用只读投影：空值用蓝色默认图，自定义头像沿用原媒体地址解析。 */
export function resolveStaffAvatar(url) {
  const raw = String(url || '').trim();
  if (!raw) return maleAvatar;
  const type = defaultAvatarType(raw);
  if (type) return DEFAULT_IMAGES[type];
  const origin = String(Setting.apiBaseURL || '')
    .replace(/\/(?:adminapi|storeapi)\/?$/i, '')
    .replace(/\/+$/, '');
  if (raw.startsWith('//')) return raw;
  if (raw.startsWith('/')) return origin + raw;
  if (/^https?:\/\/127\.0\.0\.1\/static\//i.test(raw)) {
    return origin + raw.replace(/^https?:\/\/127\.0\.0\.1/i, '');
  }
  return raw;
}

/** 图片失效只替换当前 DOM 展示，不修改员工/表单数据；默认图也失败时停止重试。 */
export function onStaffAvatarError(event) {
  const image = event && (event.currentTarget || event.target);
  if (image && image.getAttribute('src') !== maleAvatar) image.src = maleAvatar;
}

/** 保存沿用既有默认头像标识；上传原值保留，显示兜底不擅自覆盖已上传的失效地址。 */
export function normalizeStaffAvatarValue(url) {
  const type = defaultAvatarType(url);
  if (type) return `/static/images/staff/avatar_${type}.png`;
  return String(url || '').trim() ? url : DEFAULT_STAFF_AVATAR;
}
