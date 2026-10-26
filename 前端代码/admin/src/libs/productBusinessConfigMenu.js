const BUSINESS_CONFIG_ROUTES = {
  来源设置: 'setting/shop/business-source',
  记账设置: 'setting/shop/accounting'
};

/**
 * 旧菜单数据曾把这两个页面的 menu_path 保存为菜单层级（如 12/1350）。
 * 页面路由已稳定，按可见菜单名归一化，避免层级标识被当作前端地址。
 */
export function normalizeProductBusinessConfigMenu(menuData, prefix) {
  if (!Array.isArray(menuData)) return [];

  return menuData.map((item) => {
    if (!item) return item;
    const title = String(item.title || item.menu_name || '');
    const route = BUSINESS_CONFIG_ROUTES[title];
    const normalized = {
      ...item,
      children: Array.isArray(item.children)
        ? normalizeProductBusinessConfigMenu(item.children, prefix)
        : item.children
    };

    if (route) normalized.path = `${prefix}/${route}`;
    return normalized;
  });
}
