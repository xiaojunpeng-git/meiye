import fs from 'node:fs';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { strict as assert } from 'assert';

// 直接执行正本转换器及页面方法；XLS/XLSX 都经过真实序列化，不复制业务算法。
const app = process.env.ADMIN_ROOT || '/app';
const require = createRequire(app + '/package.json');
const XLSX = require('xlsx'); const compiler = require('vue-template-compiler');
const source = fs.readFileSync(app + '/src/utils/targetSchemeExcel.js', 'utf8');
const helper = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const { createTargetTemplate, parseTargetWorkbook, TARGET_HEADERS } = helper;
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; console.log('PASS ' + label); };
const catalog = {
    stores: [{ id: 23, name: '扬州欧尚店' }, { id: 24, name: '江苏淮盛店' }],
    categories: [{ id: 1, pid: 0, cate_name: '生美' }, { id: 2, pid: 1, cate_name: '卡项' }, { id: 3, pid: 0, cate_name: '六维' }, { id: 4, pid: 3, cate_name: '卡项' }],
    metrics: [{ code: 'actual_performance', name: '实际业绩', unit: 'fen', scopes: ['store', 'category', 'product'] }, { code: 'sales_quantity', name: '销售数量', unit: 'count', scopes: ['store'] }], reports: {}
};
const queries = [];
const productLoad = async params => {
    queries.push(params);
    if (params.store_id !== 23) return [];
    const products = [{ id: 101, store_name: '护理' }, { id: 102, store_name: '护理' }, { id: 103, store_name: '精华产品' }];
    return products.filter(p => params.product_id ? p.id === params.product_id : p.store_name === params.exact_name);
};
const legacyHeader = ['月份', '门店ID', '指标代码', '对象类型', '对象ID', '目标值（元或个）'];
const row = ['2026-10', '扬州欧尚店', '实际业绩', '全店', '', 10000];
function workbook(rows, header = TARGET_HEADERS) {
    const book = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet([header, ...rows]), '目标明细'); return book;
}
function roundtrip(book, bookType = 'xlsx') { return XLSX.read(XLSX.write(book, { type: 'buffer', bookType }), { type: 'buffer', cellStyles: true }); }
const parse = (book, c = catalog) => parseTargetWorkbook(XLSX, roundtrip(book), c, productLoad);
async function rejects(book, pattern, label, c = catalog) {
    await assert.rejects(() => parse(book, c), pattern); check(true, label);
}
const template = roundtrip(createTargetTemplate(XLSX, catalog, '2026-11', [23, 999]));
check(template.Sheets['目标明细'].A2.v === '2026-11', '模板月份跟随页面，不固定2026-10');
check(template.Sheets['目标明细'].B2.v === '扬州欧尚店', '只预填已选择且授权的门店名称');
check(template.Sheets['目标明细'].F2.v === '', '主填写页不包含示例目标值');
check(template.Sheets['可选分类'].A3.v === '生美/卡项', '分类目录提供完整中文路径');
const visible = template.SheetNames.map(name => XLSX.utils.sheet_to_csv(template.Sheets[name])).join('\n');
check(!/actual_performance|store,|门店ID|指标代码|对象ID/.test(visible), '新模板可见内容没有内部英文代码/ID列');
check(template.Sheets['目标明细']['!cols'][4].wch >= 39, '导出后保留可读列宽');
let imported = await parse(workbook([row, ['2026-11', '扬州欧尚店', '实际业绩', '商品分类', '生美/卡项', 12.34], ['2026-11', '扬州欧尚店', '实际业绩', '商品', '精华产品', 0]]));
check(imported.length === 3 && imported[0].store_id === 23 && imported[0].metric_code === 'actual_performance', '中文门店和指标正确还原稳定ID及代码');
check(imported[1].scope_id === 2 && imported[1].displayValue === '12.34', '分类路径与金额精度不丢失');
check(imported[2].scope_id === 103 && imported[2].displayValue === '0', '商品读取本店精确身份，0不会变空白');
check(queries[0].exact_name === '精华产品', '不使用模糊前100条商品目录');
const old = workbook([['2026-10', 23, 'actual_performance', 'store', 0, 0], ['2026-11', 23, 'actual_performance', 'category', 2, 1.23], ['2026-12', 23, 'actual_performance', 'product', 103, 100]], legacyHeader);
check((await parse(old)).length === 3, '旧模板三类对象继续可导入');
check((await parseTargetWorkbook(XLSX, roundtrip(old, 'biff8'), catalog, productLoad)).length === 3, '旧XLS真实编码可导入');
const formatted = workbook([row]); formatted.Sheets['目标明细'].F2.z = '"￥"#,##0.00';
check((await parse(formatted))[0].displayValue === '10000', 'Excel货币与千分位显示格式不影响数值');
const reordered = workbook([row]); XLSX.utils.book_append_sheet(reordered, XLSX.utils.aoa_to_sheet([['说明']]), '说明'); reordered.SheetNames.reverse();
check((await parse(reordered)).length === 1, '工作表重新排序仍只读取目标明细');
await rejects(workbook([row, row]), /第3行.*第2行/, '重复目标显示具体冲突行');
await rejects(workbook([[...row.slice(0, 5), '']]), /第2行.*空白/, '空目标不默认为0');
await rejects(workbook([[...row.slice(0, 5), -1]]), /第2行.*非负/, '拒绝负值');
await rejects(workbook([[...row.slice(0, 5), 1.234]]), /两位小数/, '金额不超过两位小数');
await rejects(workbook([['2026-10', row[1], '销售数量', '全店', '', 1.5]]), /非负整数/, '数量拒绝小数');
await rejects(workbook([[...row.slice(0, 5), 10000000000000]]), /过大/, '金额分值不超后端15位上限');
await rejects(workbook([['2026-13', ...row.slice(1)]]), /第2行.*月份/, '拒绝无效月份');
await rejects(workbook([['1999-10', ...row.slice(1)]]), /月份/, '月份边界与后端一致');
await rejects(workbook([['2026-10', '其他门店', ...row.slice(2)]]), /门店.*不存在/, '拒绝未知或越权名称');
await rejects(workbook([['2026-10', 999, 'actual_performance', 'store', 0, 100]], legacyHeader), /超出当前权限/, '旧模板同样拒绝越权门店');
await rejects(workbook([['2026-10', row[1], '未知指标', ...row.slice(3)]]), /指标不存在/, '拒绝无效指标');
await rejects(workbook([['2026-10', row[1], '销售数量', '商品分类', '生美', 1]]), /不支持/, '拒绝指标不支持的范围');
await rejects(workbook([['2026-10', row[1], row[2], '全店', '生美', 100]]), /应留空/, '全店行不静默忽略分类');
await rejects(workbook([['2026-10', row[1], row[2], '商品分类', '卡项', 100]]), /分类.*重名/, '重名分类不取第一条');
await rejects(workbook([['2026-10', row[1], row[2], '商品', '护理', 100]]), /护理（编号：101）.*护理（编号：102）/, '重名商品明确提示消歧名称');
check((await parse(workbook([['2026-10', row[1], row[2], '商品', '护理（编号：102）', 100]])))[0].scope_id === 102, '带编号的重名商品核对名称后匹配');
await rejects(workbook([['2026-10', row[1], row[2], '商品', '改名（编号：102）', 100]]), /名称已变化/, '伪造名称加正确编号也拒绝');
await rejects(workbook([['2026-10', '江苏淮盛店', row[2], '商品', '精华产品', 100]]), /该门店没有此商品/, '不能借用其他门店的同名商品');
await rejects(workbook([['2026-10', 24, 'actual_performance', 'product', 103, 100]], legacyHeader), /该门店没有此商品/, '旧模板商品ID也检查所属门店');
const duplicateCatalog = { ...catalog, stores: [{ id: 23, name: '重名店' }, { id: 24, name: '重名店' }] };
await rejects(workbook([['2026-10', '重名店', ...row.slice(2)]]), /重名/, '同名门店不能自动选第一家', duplicateCatalog);
check((await parse(workbook([['2026-10', '重名店（编号：24）', ...row.slice(2)]]), duplicateCatalog))[0].store_id === 24, '重名门店可按目录的消歧名称导入');
const formula = workbook([row]); formula.Sheets['目标明细'].F2.f = '1+1';
await rejects(formula, /不使用公式/, '拒绝依赖过期缓存结果的公式');
await rejects(workbook([[...row, '额外数据']]), /模板以外/, '不悄悄忽略额外数据列');
await rejects(workbook([], ['错误表头']), /表头/, '拒绝错误表头');
await rejects(workbook([]), /1至3000/, '拒绝空文件');
await rejects(workbook(Array.from({ length: 3001 }, () => row)), /1至3000/, '拒绝超过3000条');
const manyStores = Array.from({ length: 3000 }, (_, i) => ({ id: i + 1, name: `门店${i + 1}` }));
check((await parse(workbook(manyStores.map(store => [row[0], store.name, ...row.slice(2)])), { ...catalog, stores: manyStores })).length === 3000, '3000条边界可导入');
const blanks = workbook([row, [], ['2026-11', ...row.slice(1)]]);
check((await parse(blanks)).length === 2, '空行跳过但真实行号不丢失');

const componentSource = fs.readFileSync(app + '/src/pages/target/scheme/Edit.vue', 'utf8');
const parsed = compiler.parseComponent(componentSource);
check(compiler.compile(parsed.template.content).errors.length === 0, '实际Vue模板编译通过');
let catalogFail = false; let catalogPromise; let saved;
const sandbox = { module: { exports: {} }, XLSX, ...helper, OrganizationStoreScopePicker: {},
    schemeCatalog: async () => { if (catalogFail) throw Error('权限目录读取失败'); if (catalogPromise) await catalogPromise; return { data: catalog }; },
    schemeProducts: async params => ({ data: await productLoad(params) }), schemeSaveLines: async payload => { saved = payload; }
};
vm.runInNewContext(parsed.script.content.replace(/^import .*;$/gm, '').replace('export default', 'module.exports ='), sandbox);
const definition = sandbox.module.exports;
const page = { ...definition.data(), ...definition.methods, isDraft: true, selectedId: 95, detail: { scheme: { version: 4 } }, catalog, $Message: { success() {} } };
page.openScheme = async () => {};
function event(book) {
    const bytes = XLSX.write(book, { type: 'buffer', bookType: 'xlsx' });
    return { target: { value: 'selected.xlsx', files: [{ size: bytes.length, arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) }] } };
}
await page.importExcel(event(workbook([row, ['2026-11', row[1], row[2], '商品分类', '生美/卡项', 12.34]])));
check(page.lines.length === 2 && !page.error && !saved, '实际页面导入只更新草稿，不自动保存');
await page.saveLines();
check(saved.expected_version === 4 && saved.scheme_id === 95 && saved.lines[0].target_value === '1000000' && saved.lines[1].target_value === '1234', '保存沿用现有版本契约并正确将元转分');
if (process.env.EXPORT_PAYLOAD) fs.writeFileSync(process.env.EXPORT_PAYLOAD, JSON.stringify(saved.lines));
const before = JSON.stringify(page.lines);
await page.importExcel(event(workbook([row, ['2026-11', '越权门店', ...row.slice(2)]])));
check(JSON.stringify(page.lines) === before && /第3行/.test(page.error), '全表失败时原草稿完整保留，不部分覆盖');
catalogFail = true; await page.importExcel(event(workbook([row]))); catalogFail = false;
check(JSON.stringify(page.lines) === before && /权限目录读取失败/.test(page.error), '目录读取失败不得使用旧缓存权限');
let release; catalogPromise = new Promise(resolve => { release = resolve; });
const pending = page.importExcel(event(workbook([row])));
await new Promise(resolve => setTimeout(resolve, 0)); page.lines[0].displayValue = '88'; release(); await pending; catalogPromise = null;
check(page.lines[0].displayValue === '88' && /明细已变化/.test(page.error), '异步导入不覆盖期间人工编辑');
page.isDraft = false; await page.importExcel(event(workbook([row])));
check(page.lines[0].displayValue === '88', '非草稿不可导入');
console.log('R95_EXCEL_CHECKS=' + checks);
