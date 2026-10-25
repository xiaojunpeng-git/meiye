// Excel 只负责名称与现有目标契约的转换；最终权限、版本及落库仍由方案服务校验。
export const TARGET_HEADERS = ['月份', '门店名称', '指标名称', '目标范围', '分类或商品名称', '目标值'];
const LEGACY_HEADERS = ['月份', '门店ID', '指标代码', '对象类型', '对象ID', '目标值（元或个）'];
const SCOPES = { store: '全店', category: '商品分类', product: '商品' };
const text = value => String(value == null ? '' : value).trim();
const namedId = (name, id) => `${name}（编号：${id}）`;

/** 分类使用完整路径消歧；完全重名的目录项才附带编号，普通用户不需要查询 ID。 */
function choices(catalog) {
  const stores = catalog.stores.map(row => ({ ...row, label: text(row.name) }));
  const categories = catalog.categories.map(row => {
    const names = [text(row.cate_name)]; const visited = new Set([Number(row.id)]);
    let parent = catalog.categories.find(item => Number(item.id) === Number(row.pid));
    while (parent && !visited.has(Number(parent.id))) {
      names.unshift(text(parent.cate_name)); visited.add(Number(parent.id));
      parent = catalog.categories.find(item => Number(item.id) === Number(parent.pid));
    }
    return { ...row, label: names.join('/') };
  });
  [stores, categories].forEach(rows => {
    const counts = new Map(); rows.forEach(row => counts.set(row.label, (counts.get(row.label) || 0) + 1));
    rows.forEach(row => { if (counts.get(row.label) > 1) row.label = namedId(row.label, row.id); });
  });
  return { stores, categories };
}

/** 填写页不放示例金额，避免用户把示例当作真实目标保存；示例与说明单独放在末页。 */
export function createTargetTemplate(XLSX, catalog, month, selectedStores = []) {
  const options = choices(catalog); const book = XLSX.utils.book_new();
  const add = (name, rows, widths) => {
    const sheet = XLSX.utils.aoa_to_sheet(rows);
    sheet['!cols'] = widths.map(wch => ({ wch }));
    XLSX.utils.book_append_sheet(book, sheet, name);
  };
  const selected = options.stores.filter(store => selectedStores.map(Number).includes(Number(store.id)));
  add('目标明细', [TARGET_HEADERS, ...(selected.length ? selected : [{ label: '' }]).map(store => [month, store.label, '', '全店', '', ''])], [16, 30, 24, 20, 40, 22]);
  add('可选门店', [['门店名称'], ...options.stores.map(store => [store.label])], [45]);
  add('可选指标', [['指标名称', '目标值单位', '支持的目标范围'], ...catalog.metrics.map(metric => [metric.name, metric.unit === 'fen' ? '元' : '个/人次', metric.scopes.map(scope => SCOPES[scope]).join('、')])], [26, 22, 40]);
  add('可选分类', [['分类名称（复制到分类或商品名称列）'], ...options.categories.map(category => [category.label])], [60]);
  add('填写说明', [
    ['填写项', '填写方式'],
    ['月份', '按年-月填写，例如 2026-10。'],
    ['门店名称', '从可选门店页复制名称，只能导入当前账号有权限的门店。'],
    ['指标名称', '从可选指标页复制中文名称，并按该指标的单位填写目标值。'],
    ['目标范围', '填写全店、商品分类或商品，须在该指标支持的范围内。'],
    ['分类或商品名称', '全店留空；分类从可选分类页复制；商品填写对应门店的完整商品名称。'],
    ['重名商品', '导入提示重名时，按提示填写带编号的商品名称，不按第一条自动匹配。'],
    ['目标值', '金额填元，最多两位小数；数量填非负整数。0 是有效目标，空白不是 0。'],
    ['导入与保存', '仅导入目标明细页，替换页面草稿；检查后保存草稿，再按原流程启用。'],
    ['示例（不会导入）', `${month}，门店名称，实际业绩，全店，分类或商品名称留空，10000`]
  ], [25, 100]);
  return book;
}

function unique(rows, value, label, nameKey) {
  // 先合并所有精确候选再判唯一，防止名称恰好等于另一项的路径/消歧标签。
  const matches = rows.filter(row => row.label === value || text(row[nameKey]) === value);
  if (matches.length !== 1) throw new Error(matches.length ? `${label}“${value}”重名，请使用可选目录中的完整名称` : `${label}“${value}”不存在或不在当前可选范围，请重新下载模板核对`);
  return matches[0];
}

/** 新旧表头均归一为同一草稿结构。全表校验成功后才交给页面替换，不产生部分导入。 */
export async function parseTargetWorkbook(XLSX, book, catalog, loadProducts) {
  const sheet = book.Sheets['目标明细'] || book.Sheets[book.SheetNames[0]];
  if (!sheet || !sheet['!ref']) throw new Error('Excel 中没有目标明细');
  const range = XLSX.utils.decode_range(sheet['!ref']);
  if (range.s.r !== 0 || range.s.c !== 0) throw new Error('请将模板表头保留在第一行，从A列开始');
  if (range.e.r > 10000 || range.e.c > 50) throw new Error('模板范围过大，请删除多余行列后重试');
  const cells = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false, defval: '' });
  const header = (cells[0] || []).map(text);
  while (header.length && !header[header.length - 1]) header.pop();
  const legacy = header.join('|') === LEGACY_HEADERS.join('|');
  if (!legacy && header.join('|') !== TARGET_HEADERS.join('|')) throw new Error('Excel 表头与模板不一致，请重新下载模板');
  const rows = cells.slice(1).map((values, index) => ({ values: values.map(text), rowNumber: index + 2 })).filter(row => row.values.some(Boolean));
  if (!rows.length || rows.length > 3000) throw new Error('请导入1至3000条目标');
  const options = choices(catalog); const seen = new Map(); const productCache = new Map(); const result = [];
  for (const { values, rowNumber } of rows) {
    try {
      for (let col = 0; col < 6; col++) {
        const cell = sheet[XLSX.utils.encode_cell({ r: rowNumber - 1, c: col })];
        if (cell && (cell.f || cell.t === 'e')) throw new Error('请填写具体值，不使用公式或错误值');
      }
      if (values.slice(6).some(Boolean)) throw new Error('存在模板以外的数据列');
      // 金额/旧编号读取原始数值，Excel 的千分位或货币格式不应改变导入值。
      const normalized = values.slice();
      for (let col = 1; col < 6; col++) {
        const cell = sheet[XLSX.utils.encode_cell({ r: rowNumber - 1, c: col })];
        if (cell && cell.t === 'n') normalized[col] = text(cell.v);
      }
      const [month, storeValue, metricValue, scopeValue, objectValue, amount] = normalized;
      if (!/^20\d{2}-(0[1-9]|1[0-2])$/.test(month)) throw new Error('月份应为2000至2099年的年-月，例如2026-10');
      const store = legacy ? catalog.stores.find(item => String(item.id) === storeValue) : unique(options.stores, storeValue, '门店', 'name');
      if (!store) throw new Error('门店不存在或超出当前权限');
      const metric = catalog.metrics.find(item => (legacy ? item.code : item.name) === metricValue);
      if (!metric) throw new Error('指标不存在，请从可选指标中选择');
      const scope = legacy ? scopeValue : Object.keys(SCOPES).find(key => SCOPES[key] === scopeValue);
      if (!metric.scopes.includes(scope)) throw new Error('该指标不支持所填目标范围');
      let scopeId = 0; let scopeName = ''; let productOptions = [];
      if (scope === 'store') {
        if (objectValue && !(legacy && objectValue === '0')) throw new Error('全店目标的分类或商品名称应留空');
      } else if (scope === 'category') {
        const category = legacy ? catalog.categories.find(item => String(item.id) === objectValue) : unique(options.categories, objectValue, '分类', 'cate_name');
        if (!category) throw new Error('分类不存在或已不可选');
        scopeId = Number(category.id);
      } else {
        if (!objectValue) throw new Error('请填写商品名称');
        const qualified = !legacy && objectValue.match(/^(.*)（编号：([1-9]\d*)）$/);
        const params = { store_id: Number(store.id) };
        if (legacy || qualified) {
          const id = legacy ? objectValue : qualified[2];
          if (!/^[1-9]\d*$/.test(id) || !Number.isSafeInteger(Number(id))) throw new Error('商品编号无效');
          params.product_id = Number(id);
        } else params.exact_name = objectValue;
        const key = JSON.stringify(params);
        if (!productCache.has(key)) productCache.set(key, await loadProducts(params));
        const products = productCache.get(key);
        // SQL 排序规则可能忽略大小写/尾空格，这里继续核对完整名称，不猜测相似项。
        const matches = products.filter(item => params.product_id ? Number(item.id) === params.product_id && (!qualified || item.store_name === qualified[1]) : item.store_name === objectValue);
        if (!params.product_id && products.length > 1) throw new Error(`商品名称不唯一，请核对后填写：${products.map(item => namedId(item.store_name, item.id)).join('、')}`);
        if (matches.length !== 1) throw new Error('该门店没有此商品，或商品名称已变化，请核对完整名称');
        scopeId = Number(matches[0].id); scopeName = matches[0].store_name; productOptions = matches;
      }
      const numericPattern = metric.unit === 'fen' ? /^\d+(\.\d{1,2})?$/ : /^\d+$/;
      if (!numericPattern.test(amount || '')) throw new Error(metric.unit === 'fen' ? '金额目标须为非负数，最多两位小数，空白不能代替0' : '数量目标须为非负整数，空白不能代替0');
      const parts = amount.split('.');
      const stored = metric.unit === 'fen' ? Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0')) : Number(amount);
      if (!Number.isSafeInteger(stored) || stored > 999999999999999) throw new Error('目标值过大');
      const key = [store.id, month, metric.code, scope, scopeId].join(':');
      if (seen.has(key)) throw new Error(`与第${seen.get(key)}行是同一目标，请合并后导入`);
      seen.set(key, rowNumber);
      result.push({ month_key: month, store_id: Number(store.id), metric_code: metric.code, scope_type: scope, scope_id: scopeId, displayValue: amount, scope_name: scopeName, productOptions });
    } catch (error) { throw new Error(`第${rowNumber}行：${error.message || '读取失败'}`); }
  }
  return result;
}
