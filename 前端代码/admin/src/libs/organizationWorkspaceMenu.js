export function normalizeOrganizationWorkspaceMenu(menuData, prefix) {
  if (!Array.isArray(menuData)) return [];
  const titleOf = (item) => String((item && (item.title || item.menu_name)) || '');
  // 只规范权威人员菜单；旧供应商权限不能再伪装成人员入口。
  const isPeople = item => item && (
    item.unique_auth === 'setting-staff-index' ||
    String(item.path || item.menu_path || '').replace(/\/$/, '') === `${prefix}/setting/staff/index`
  );
  const isStore = (item) => {
    const path = String((item && (item.path || item.menu_path)) || '').replace(/\/$/, '');
    return item && (titleOf(item) === '门店管理' || path.endsWith('/store/store/index') || path.endsWith('/store'));
  };
  const isSetting = (item) => {
    const path = String((item && (item.path || item.menu_path)) || '').replace(/\/$/, '');
    return item && (titleOf(item) === '门店设置' || path.endsWith('/store/setting') || path.endsWith('/store/system/base'));
  };
  const normalizeSiblings = (items) => {
    // 历史供应商列表已经重定向到人员页面，移除这个重复导航；原供应商其他功能保留。
    return items.filter(item => !String(item && (item.path || item.menu_path) || '').replace(/\/$/, '').endsWith('/supplier/menu/list')).map((item) => {
      if (!item) return item;
      const normalized = {
        ...item,
        children: Array.isArray(item.children) ? normalizeSiblings(item.children) : item.children
      };
      if (isPeople(item)) {
        normalized.title = '人员管理';
        normalized.menu_name = '人员管理';
        normalized.path = `${prefix}/setting/staff/index`;
      }
      if (isStore(item)) {
        normalized.path = `${prefix}/store/store/index`;
      }
      if (isSetting(item)) {
        normalized.path = `${prefix}/store/system/base`;
      }
      return normalized;
    });
  };
  return normalizeSiblings(menuData);
}
