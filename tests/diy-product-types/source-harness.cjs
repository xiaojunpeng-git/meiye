// Load canonical source in memory. Only framework and transport dependencies are adapters.
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const deps = path.join(root, '前端代码/admin/node_modules');
const compiler = require(path.join(deps, 'vue-template-compiler'));
const parser = require(path.join(deps, '@babel/parser'));
const paths = {
  listPreview: '前端代码/admin/src/components/mobilePage/home_goods_list.vue',
  tabPreview: '前端代码/admin/src/components/mobilePage/home_product.vue',
  listSettings: '前端代码/admin/src/components/mobileConfig/c_home_goods_list.vue',
  tabSettings: '前端代码/admin/src/components/mobileConfig/c_home_product.vue',
  selector: '前端代码/admin/src/components/mobileConfigRight/c_catalog_types.vue',
  promotion: '前端代码/admin/src/components/mobileConfigRight/c_promotion.vue',
  goods: '前端代码/admin/src/components/mobileConfigRight/c_goods.vue',
  picker: '前端代码/admin/src/components/goodsList/index.vue',
  member: '前端代码/uniapp/pages/index/components/goodList.vue',
  memberTabs: '前端代码/uniapp/pages/index/components/promotionList.vue'
};
const helpers = fs.readFileSync(path.join(root, '前端代码/admin/src/utils/diyProductTypes.js'), 'utf8').replace(/export /g, '');
const helperOptions = new Function(helpers + ';return {diyProductTypeOptions,createDiyProductTypes,diyProductTypes,filterDiyProducts};')();
const tools = new Proxy({}, { get: (_, name) => ({ name }) });
function source(key) {
  const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, paths[key]), 'utf8'));
  const ast = parser.parse(sfc.script.content, { sourceType: 'module' });
  const edits = ast.program.body.filter(n => n.type === 'ImportDeclaration' || n.type === 'ExportDefaultDeclaration')
    .map(n => ({ start: n.start, end: n.type === 'ImportDeclaration' ? n.end : n.declaration.start, value: n.type === 'ImportDeclaration' ? '' : 'return ' }));
  let script = sfc.script.content;
  for (const e of edits.sort((a,b) => b.start-a.start)) script = script.slice(0,e.start)+e.value+script.slice(e.end);
  const render = compiler.compile(sfc.template.content);
  if (render.errors.length) throw Error(key + ': '+JSON.stringify(render.errors));
  return { script, template: sfc.template.content, styles: sfc.styles };
}
function options(key, extras = {}) {
  const mocks = {
    ...helperOptions, toolCom: tools, rightBtn: {}, catalogTypes: {},
    mapState: () => ({}), mapMutations: () => ({}), mapActions: () => ({}), mapGetters: () => ({}),
    vuedraggable: {}, goodsList: {}, uploadPictures: {}, storeLabelList: {}, productWindow: {}, skuSelect: {}, goodList: {},
    ...extras
  };
  return new Function(...Object.keys(mocks), source(key).script)(...Object.values(mocks));
}
module.exports = { root, deps, compiler, helpers, helperOptions, source, options, paths };
