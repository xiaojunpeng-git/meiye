/** 执行真实Vue方法，保护旧商品只读回显、缺失金额保存门禁及普通规格兼容。 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { createRequire } = require('node:module');
const root = path.resolve(__dirname, '../../..');
const requireAdmin = createRequire(path.join(root, '前端代码/admin/package.json'));
const source = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/product/productAdd/index.vue'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
const ast = requireAdmin('@babel/parser').parse(script, { sourceType: 'module' });
const component = ast.program.body.find((node) => node.type === 'ExportDefaultDeclaration').declaration;
const methods = component.properties.find((node) => node.key.name === 'methods').value;
function method(name, bindings = {}) {
  const node = methods.properties.find((item) => item.key && item.key.name === name);
  return new Function(...Object.keys(bindings), `return ({${script.slice(node.start, node.end)}}).${name}`)(...Object.values(bindings));
}
function fixture(spec = 0) {
  return { id: 290295, store_name: '水漾至美套盒', unit_name: '套', is_del: 1, product_type: 0, type: 0, spec_type: spec,
    attr: { price: 0, cost: 0, ot_price: 0 }, attrs: [], items: [], description: '', coupons: [], brand_id: [],
    is_limit: false, limit_type: 0, auto_off_time: '', delivery_type: [], store_delivery_type: [] };
}
function context(attrValue = []) {
  return { attrValue, levelList: [{ id: 1, discount: 100 }], formData: { id: 0, store_name: '', unit_name: '', is_del: 0, product_type: 0,
      type: 0, spec_type: 0, slider_image: [], attr: {}, coupons: [], brand_id: [], delivery_type: [], store_delivery_type: [], limit_type: 0, is_limit: false },
    $route: { params: { id: '290295' }, query: {} }, $refs: {}, oneFormBatch: [{}],
    $set: (object, key, value) => { object[key] = value; }, generateHeader() {},
    $computes: { Mul: (a, b) => a * b, Div: (a, b) => a / b },
    $Message: { warning(message) { this.last = message; }, error(message) { this.last = message; } } };
}
async function main() {
  const infoData = method('infoData');
  const missing = context();
  infoData.call(missing, fixture());
  assert.equal(missing.formData.store_name, '水漾至美套盒');
  assert.equal(missing.formData.unit_name, '套');
  assert.equal(missing.formData.is_del, 1, '读取不恢复回收站商品');
  assert.equal(missing.specificationMissing, true);
  for (const key of ['price', 'cost', 'ot_price']) assert.equal(missing.formData.attr[key], null);
  const submit = method('handleSubmit');
  await submit.call(missing);
  assert.equal(missing.currentTab, '2');
  assert.match(missing.$Message.last, /补全规格/);
  missing.formData.attr = { price: 0, cost: 0, ot_price: 0 };
  missing.normalizeRetailDeliveryBeforeSave = () => { throw new Error('PASSED_COMPLETION_GATE'); };
  await assert.rejects(submit.call(missing), /PASSED_COMPLETION_GATE/, '明确填0仍合法，不强制正价');
  const normal = context([{ suk: '默认', vip_price: 3980, brokerage: 2, brokerage_two: 1, level_price: null }]);
  const data = fixture(); data.attr = { price: 3980, cost: 2000, ot_price: 4000 };
  infoData.call(normal, data);
  assert.equal(normal.specificationMissing, false);
  assert.equal(normal.formData.attr.price, 3980);
  assert.equal(normal.formData.attr.cost, 2000);
  assert.equal(normal.formData.attr.vip_price, 3980);
  // 使用反馈商品的真实形态：有正常SKU但轮播图为null，验证子组件所需数组及既有封面均保留。
  const legacyImage = context([{ suk: '默认', vip_price: 0, level_price: [] }]);
  const legacyData = fixture();
  Object.assign(legacyData, { id: 498505, store_name: '焕能净透洁面泡', slider_image: null, image: 'https://example.test/original.png' });
  legacyData.attr = { price: 168, cost: 0, ot_price: 168 };
  infoData.call(legacyImage, legacyData);
  assert.equal(legacyImage.formData.store_name, '焕能净透洁面泡');
  assert.deepEqual(legacyImage.formData.slider_image, [legacyData.image]);
  assert.equal(legacyImage.formData.slider_image.length, 1, '子组件读取图片数量不再抛错');
  assert.equal(legacyImage.formData.attr.price, 168);
  const noImage = context(); const noImageData = fixture(); noImageData.slider_image = null;
  infoData.call(noImage, noImageData);
  assert.deepEqual(noImage.formData.slider_image, [], '无原封面时不伪造图片');
  const existingImages = context(); const existingImagesData = fixture();
  existingImagesData.slider_image = ['https://example.test/a.png', 'https://example.test/b.png'];
  infoData.call(existingImages, existingImagesData);
  assert.deepEqual(existingImages.formData.slider_image, existingImagesData.slider_image, '合法轮播图顺序不变');
  const multi = context([{ suk: '小瓶', vip_price: 20, level_price: [] }]);
  const multiData = fixture(1); multiData.attrs = [{ attr_arr: ['小瓶'], price: 30, cost: 10, ot_price: 40 }];
  infoData.call(multi, multiData);
  assert.equal(multi.manyFormValidate[1].vip_price, 20);
  const missingMulti = context(); infoData.call(missingMulti, fixture(1));
  await submit.call(missingMulti); assert.match(missingMulti.$Message.last, /补全规格/);
  const partialMulti = context(); const partialData = fixture(1);
  partialData.attrs = [{ attr_arr: ['遗留规格'], price: 10, cost: 5, ot_price: 20 }];
  infoData.call(partialMulti, partialData);
  assert.equal(partialMulti.manyFormValidate[1].price, null, '缺失规格空金额不能被数字转换为0');
  await submit.call(partialMulti); assert.match(partialMulti.$Message.last, /补全规格/);
  const failed = context();
  const getInfo = method('getInfo', { productInfoApi: () => Promise.reject(new Error('详情读取异常')) });
  getInfo.call(failed); await new Promise(setImmediate);
  assert.equal(failed.spinShow, false);
  assert.equal(failed.$Message.last, '详情读取异常', '原生Error不能再次引发消息组件异常');
  assert.match(source, /<Alert v-if="specificationMissing"/);
  console.log('PRODUCT_EDIT_MISSING_SPEC_OK: basic data, no restore, blank amounts, save guard, zero amount, normal SKU, multi SKU, empty multi SKU, error message');
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
