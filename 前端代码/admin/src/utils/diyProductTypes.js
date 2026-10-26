// DIY uses the existing product catalog codes, not a new category hierarchy.
export const diyProductTypeOptions = [
  { id: 0, name: '产品' },
  { id: 5, name: '卡项' },
  { id: 6, name: '项目' }
];

export function createDiyProductTypes() {
  return { type: [0, 5, 6] };
}

// Missing means legacy/unrestricted; an explicit empty selection means no goods.
export function diyProductTypes(config) {
  return config && config.catalogTypes ? config.catalogTypes.type : null;
}

export function filterDiyProducts(list, config) {
  const types = diyProductTypes(config);
  return types === null ? list : list.filter(item => types.includes(Number(item.product_type)));
}
