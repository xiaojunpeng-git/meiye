export function normalizeOrganizationWorkspaceMenu(menuData, prefix) {
  if (!Array.isArray(menuData)) return [];
  const titleOf = (item) => String((item && (item.title || item.menu_name)) || '');
  const isPeople = (item) => {
    const path = String((item && (item.path || item.menu_path)) || '').replace(/\/$/, '');
    return item && (
      titleOf(item) === '人员管理' ||
      titleOf(item) === '员工管理' ||
      path === `${prefix}/supplier/menu/list` ||
      path.endsWith('/supplier/menu/list') ||
      path === `${prefix}/supplier` ||
      path.endsWith('/supplier')
    );
  };
  const isStore = (item) => {
    const path = String((item && (item.path || item.menu_path)) || '').replace(/\/$/, '');
    return item && (titleOf(item) === '门店管理' || path.endsWith('/store/store/index') || path.endsWith('/store'));
  };
  const isSetting = (item) => {
    const path = String((item && (item.path || item.menu_path)) || '').replace(/\/$/, '');
    return item && (titleOf(item) === '门店设置' || path.endsWith('/store/setting') || path.endsWith('/store/system/base'));
  };
  const normalizeSiblings = (items) => {
    return items.map((item) => {
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
