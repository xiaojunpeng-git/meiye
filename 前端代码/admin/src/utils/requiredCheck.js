/**
 * 按「从左到右、从上到下」的字段顺序检查必填，只返回第一个未通过项。
 * @param {Array<{ key: string, message: string, tab?: string, isEmpty?: Function, validate?: Function }>} order
 * @param {Object} form
 * @returns {{ key: string, message: string, tab: string } | null}
 */
export function findFirstRequiredError(order, form) {
  if (!Array.isArray(order) || !form) return null;
  for (let i = 0; i < order.length; i++) {
    const item = order[i];
    if (!item || !item.key) continue;
    const value = form[item.key];
    const empty = typeof item.isEmpty === 'function'
      ? item.isEmpty(value, form)
      : value === undefined
        || value === null
        || value === ''
        || (Array.isArray(value) && value.length === 0);
    if (empty) {
      return {
        key: item.key,
        message: item.message || `${item.key}未填写`,
        tab: item.tab || '',
      };
    }
    if (typeof item.validate === 'function') {
      const msg = item.validate(value, form);
      if (msg) {
        return {
          key: item.key,
          message: msg,
          tab: item.tab || '',
        };
      }
    }
  }
  return null;
}
