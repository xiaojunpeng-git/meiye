import Setting from '@/setting';
import util from '@/libs/util';
import { getHeaderName, getHeaderSider, getMenuSider } from '@/libs/system';
import { hasMoheAiManagementMenu } from '@/libs/moheAiManagementMenu';

/**
 * 三种平台登录方式共用成功处理。后端已判断登录资格，菜单/权限为空不再次拒绝。
 * 每次覆盖旧账号的菜单与权限；空菜单进入无业务内容的工作区，不跳首页或旧深链。
 */
export async function finishPlatformLogin(vm, data) {
  const menus = Array.isArray(data.menus) ? data.menus : [];
  const access = Array.isArray(data.unique_auth) ? data.unique_auth : [];
  const user = data.user_info;
  const expires = data.expires_time;
  localStorage.setItem(`adminType_${user.admin_type}`, user.admin_type);
  vm.$store.dispatch('admin/account/setPageTitle');
  util.cookies.set('uuid', user.id, { expires });
  util.cookies.set('token', data.token, { expires });
  util.cookies.set('expires_time', expires, { expires });
  // 统一使用路由守卫实际读取的 localStorage，清除短信登录遗留的旧权限快照。
  localStorage.setItem('unique_auth', access);
  const db = await vm.$store.dispatch('admin/db/database', { user: true });
  db.set('user_info', user).write();
  util.makeMenu(Setting.roterPre, menus);
  vm.$store.commit('admin/menus/getmenusNav', menus);
  // The login response carries the menu scoped to this freshly issued token.
  // Clear any previous account's verification when that entry is absent.
  vm.$store.commit('admin/menu/setMoheAiVerifiedToken', hasMoheAiManagementMenu(menus) ? data.token : null);
  vm.$store.commit('admin/menu/setHeader', getHeaderSider(menus));
  const path = menus.length ? util.resolveDefaultMenuPath(menus) : `${Setting.roterPre}/workspace`;
  const headerName = getHeaderName({ path, query: {}, params: {} }, menus);
  const selected = getMenuSider(menus, headerName);
  vm.$store.commit('admin/menu/setHeaderName', headerName || '');
  vm.$store.commit('admin/menu/setSider', (selected[0] && selected[0].children) || []);
  vm.$store.commit('admin/menu/setActivePath', '');
  vm.$store.commit('admin/menu/setOpenNames', []);
  vm.$store.commit('admin/menus/setIndexPath', path);
  await vm.$store.dispatch('admin/user/set', {
    name: user.account,
    avatar: user.head_pic,
    access,
    logo: data.logo,
    logoSmall: data.logo_square,
    version: data.version,
    newOrderAudioLink: data.newOrderAudioLink
  });
  return vm.$router.replace({ path: menus.length ? (vm.$route.query.redirect || path) : path });
}
