/** 业务功能查找其配置顶级大类；不可授权的子入口不是顶级大类。 */
export function storeNavigationGroup(rules, code) {
  const byId = new Map((rules || []).map(row => [Number(row.id), row]));
  let row = (rules || []).find(item => item.feature_code === code);
  const seen = new Set();
  while (row && !seen.has(row.id)) {
    seen.add(row.id);
    if (row.grantable === false && row.parent_id === 0 && !row.navigation_key) return row;
    row = byId.get(Number(row.parent_id));
  }
  return null;
}

/** 从完整配置树投影可见导航：仅保留已授权且有原页面处理器的入口及其祖先，绝不把业务按钮猜成菜单。 */
export function configuredNavigationGroups(rules, entries) {
  const children = new Map();
  (rules || []).forEach(row => {
    if (!children.has(row.parent_id)) children.set(row.parent_id, []);
    children.get(row.parent_id).push(row);
  });
  children.forEach(rows => rows.sort((a, b) => a.sort - b.sort || a.id - b.id));
  const build = (parent, depth) => (children.get(parent) || []).flatMap(rule => {
    if (rule.enabled !== 1) return [];
    const nested = build(rule.id, depth + 1);
    const handlers = entries.filter(entry => rule.navigation_key
      ? entry.navigationKey === rule.navigation_key
      : !entry.navigationKey && entry.featureCode === rule.feature_code);
    if (!handlers.length && !nested.length) return [];
    const rows = handlers.length ? handlers.map(entry => ({ id: rule.id, depth, label: rule.title,
      entry: { ...entry, label: rule.title }, hasChildren: nested.length > 0 }))
      : [{ id: rule.id, depth, label: rule.title, entry: null, hasChildren: true }];
    return [...rows, ...nested];
  });
  return (children.get(0) || []).filter(row => row.grantable === false && !row.navigation_key && row.enabled === 1)
    .map(row => ({ ...row, key: String(row.id), label: row.title, entries: build(row.id, 0) }))
    .filter(row => row.entries.length || row.account_menu);
}
