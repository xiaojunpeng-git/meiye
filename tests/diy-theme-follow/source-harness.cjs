// Compile the canonical SFCs; only framework/API dependencies are stubbed.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const dependencies = path.join(root, '前端代码/admin/node_modules');
const compiler = require(path.join(dependencies, 'vue-template-compiler'));
const parser = require(path.join(dependencies, '@babel/parser'));
const paths = {
  preview: '前端代码/admin/src/components/mobilePage/home_comb.vue',
  member: '前端代码/uniapp/pages/index/components/homeComb.vue'
};
const uni = {
  getSystemInfoSync: () => ({ statusBarHeight: 0 }),
  getWindowInfo: () => ({ windowWidth: 375 }),
  getSystemInfo: ({ success }) => success({ windowWidth: 375 }),
  getImageInfo: ({ success }) => success({ width: 750, height: 320 }),
  setStorageSync() {}
};
const location = { props: ['storeInfor'], methods: { selfLocation() {}, chooseLocation() {} } };
const mapState = () => ({ defaultArray() { return this.$store.state.admin.mobildConfig.defaultArray; } });
function component(key) {
  const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, paths[key]), 'utf8'));
  const edits = parser.parse(sfc.script.content, { sourceType: 'module' }).program.body
    .filter(node => ['ImportDeclaration', 'ExportDefaultDeclaration'].includes(node.type))
    .map(node => node.type === 'ImportDeclaration'
      ? { start: node.start, end: node.end, value: '' }
      : { start: node.start, end: node.declaration.start, value: 'return ' });
  let script = sfc.script.content;
  for (const edit of edits.sort((a, b) => b.start - a.start)) script = script.slice(0, edit.start) + edit.value + script.slice(edit.end);
  const compiled = compiler.compile(sfc.template.content);
  if (compiled.errors.length) throw Error(JSON.stringify(compiled.errors));
  return { sfc, script, options: new Function('uni', 'location', 'mapState', script)(uni, location, mapState) };
}
function config() {
  const data = component('preview').options.data.call({ num: 0 }).defaultConfig;
  data.searchBox.tabVal = 1;
  data.titleConfig.value = '门店';
  data.classConfig.tabVal = 1;
  data.swiperConfig.list = [0, 1, 2].map(index => ({
    img: '/asset.png', info: [{ value: 'Banner ' + index }, { value: '/test-link-' + index }]
  }));
  // Deliberately different from the theme: stale custom values must not leak.
  data.dotColor.color[0].item = '#e93323';
  data.dotBgColor.color[0].item = '#102030';
  return data;
}
module.exports = { root, dependencies, compiler, component, config, uni, location, mapState, paths };
