/**
 * Give duplicate menu entries a stable URL marker without creating a second
 * business route. This preserves the parent menu while reusing the same page.
 */
const ENTRY_QUERY_KEY = 'menu_entry';

function splitPath(path) {
  const value = String(path || '');
  const index = value.indexOf('?');
  return index === -1
    ? { pathname: value, query: new URLSearchParams() }
    : { pathname: value.slice(0, index), query: new URLSearchParams(value.slice(index + 1)) };
}

function routeKey(path) {
  const { pathname, query } = splitPath(path);
  query.delete(ENTRY_QUERY_KEY);
  const queryString = query.toString();
  return pathname + (queryString ? `?${queryString}` : '');
}

function withEntry(path, entry) {
  const { pathname, query } = splitPath(path);
  query.set(ENTRY_QUERY_KEY, String(entry));
  return `${pathname}?${query.toString()}`;
}

function collectLeaves(items, leaves = []) {
  (Array.isArray(items) ? items : []).forEach((item) => {
    if (!item) return;
    if (Array.isArray(item.children) && item.children.length) {
      collectLeaves(item.children, leaves);
      return;
    }
    const path = String(item.path || '');
    if (path) leaves.push({ item, path });
  });
  return leaves;
}

/** Mark only duplicate leaf routes; unique routes remain unchanged. */
export function disambiguateDuplicateMenuPaths(menuData) {
  if (!Array.isArray(menuData)) return [];

  const leaves = collectLeaves(menuData);
  const counts = leaves.reduce((result, leaf) => {
    const key = routeKey(leaf.path);
    result[key] = (result[key] || 0) + 1;
    return result;
  }, {});
  const seen = {};

  const visit = (items) => (Array.isArray(items) ? items.map((item) => {
    if (!item) return item;
    const normalized = {
      ...item,
      children: Array.isArray(item.children) ? visit(item.children) : item.children
    };
    if (normalized.children && normalized.children.length) return normalized;

    const path = String(normalized.path || '');
    const key = routeKey(path);
    if (!path || counts[key] < 2) return normalized;

    seen[key] = (seen[key] || 0) + 1;
    const entry = normalized.id || `${normalized.unique_auth || key}-${seen[key]}`;
    normalized.path = withEntry(path, entry);
    return normalized;
  }) : []);

  return visit(menuData);
}
