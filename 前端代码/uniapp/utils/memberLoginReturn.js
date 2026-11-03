/**
 * 登录只恢复应用内业务深链。OAuth 的 back_url 可经两层编码；只解码路径前缀，
 * 一旦获得 /pages/ 就保留查询值原有编码，避免门店名中的 & 被误当成新参数。
 * 沿用登录服务的页面字符白名单，并拒绝跳转登录页循环及目录穿越。
 */
export function normalizeMemberLoginReturn(value) {
  if (typeof value !== 'string') return '';
  let url = value;
  for (let i = 0; i < 2 && !url.startsWith('/pages/'); i++) {
    try { url = decodeURIComponent(url); } catch (_) { return ''; }
  }
  if (!/^\/pages\/[a-zA-Z0-9_/?=&%.-]+$/.test(url)) return '';
  let pathname = url.split('?')[0];
  try { pathname = decodeURIComponent(pathname); } catch (_) { return ''; }
  if (!/^\/pages\/[a-zA-Z0-9_/-]+$/.test(pathname) || pathname.includes('//')) return '';
  if (/^\/pages\/users\/(login|wechat_login|binding_phone)(\/|$)/.test(pathname)) return '';
  return url;
}

/** 只在登录成功后消费返回地址并重建业务页，使确认单的 onLoad 能重新读取完整参数。 */
export function resumeMemberLoginReturn(cache, preferred = '') {
  const url = normalizeMemberLoginReturn(preferred) || normalizeMemberLoginReturn(cache.get('login_back_url'));
  if (!url) return false;
  cache.clear('login_back_url');
  uni.reLaunch({ url });
  return true;
}
