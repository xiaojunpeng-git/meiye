import Setting from '@/setting';
import { includeArray } from '@/libs/system';
import { STORE_OPERATION_REPORT_CODES } from '@/libs/storeOperationReports';
import { PHASE_SIX_REPORT_CODES } from '@/libs/phaseSixReports';

/** 登录选页与路由守卫共用权限解析，包括需要按报表编码鉴权的子路由。 */
export function resolveRouteAccessMeta(to) {
  // 动态子路由可继承父级权限；不能假定末级路由自身声明 auth。
  const permissionRoute = [...to.matched]
    .reverse()
    .find(route => route.meta && route.meta.auth);
  let meta = permissionRoute ? permissionRoute.meta : {};
  // Menu ancestors are navigation containers, not additional job grants.
  // Resolve only registered frame reports, then use the same exact grant for
  // cached and refreshed checks. The backend still enforces report/data scope.
  if (meta.storeOperationReport) {
    const report = String(to.params.report || '');
    const prefix = STORE_OPERATION_REPORT_CODES.includes(report)
      ? 'admin-report-store-operations-'
      : PHASE_SIX_REPORT_CODES.includes(report) ? 'admin-report-phase-six-' : '';
    if (!prefix) return null;
    meta = { ...meta, auth: [`${prefix}${report}`] };
  }
  return meta;
}

/** 沿用守卫对登录接口返回的管理员身份的判定。 */
export function isPlatformRootAdmin(userInfo) {
  const account = String(userInfo.account || userInfo.username || '').trim().toLowerCase();
  return (
    (userInfo.level === 0 || userInfo.level === '0') && Number(userInfo.admin_type || userInfo.adminType || 0) !== 3
  ) || account === 'admin';
}

/** 验证站内入口的实际目标路由，失效路径和错误页不能作为业务入口。 */
export function canOpenPlatformPath(router, path, user, access, aiVerified = false) {
  if (typeof path !== 'string' || !path.startsWith(`${Setting.roterPre}/`) || path.includes('\\')) return false;
  const { route } = router.resolve(path);
  const meta = resolveRouteAccessMeta(route);
  if (meta && meta.moheAiMaintainer) return aiVerified && !meta.isAgentRoute;
  return Boolean(meta && meta.auth && !meta.isAgentRoute &&
    (isPlatformRootAdmin(user) || includeArray(meta.auth, access)));
}

/** 只裁剪平台站内功能，菜单分组随最后一个可用子项一起隐藏。 */
export function filterPlatformMenuRoutes(menus, canOpen) {
  return menus.reduce((visible, menu) => {
    if (!menu) return visible;
    if (menu.children && menu.children.length) {
      const children = filterPlatformMenuRoutes(menu.children, canOpen);
      if (children.length) visible.push({ ...menu, children });
    } else if (!String(menu.path || '').startsWith(`${Setting.roterPre}/`) || canOpen(menu.path)) {
      visible.push(menu);
    }
    return visible;
  }, []);
}
