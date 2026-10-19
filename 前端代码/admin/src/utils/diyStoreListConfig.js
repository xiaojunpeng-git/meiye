/** Extend only the store-list editor schema, preserving saved display choices.
 * Legacy layouts always displayed store names: add the new toggle as selected
 * once, but never reselect it after a user explicitly hides the name.
 */
export function upgradeStoreListConfig(config) {
  if (!config) return config;
  const style = config.storeStyleConfig;
  if (style && Array.isArray(style.tabList) && style.tabList.length === 3) {
    style.tabList.push({ name: '样式四' });
  }
  const info = config.checkboxInfo;
  if (info && Array.isArray(info.list) && !info.list.some(item => Number(item.id) === 4)) {
    info.list.unshift({ id: 4, name: '门店名称' });
    info.type = Array.from(new Set([...(info.type || []), 4]));
  }
  return config;
}
