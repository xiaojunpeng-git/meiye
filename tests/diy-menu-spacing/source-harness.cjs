// Compile the canonical Vue2 components; fixtures never maintain business copies.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const compiler = require(path.join(root, '前端代码/admin/node_modules/vue-template-compiler'));
const stylus = require(path.join(root, '前端代码/admin/node_modules/stylus'));
const paths = {
  preview: '前端代码/admin/src/components/mobilePage/home_menu.vue',
  settings: '前端代码/admin/src/components/mobileConfig/c_home_menu.vue',
  member: '前端代码/uniapp/pages/index/components/menus.vue'
};
const mapState = () => ({ defaultArray() { return this.$store.state.admin.mobildConfig.defaultArray; } });
function readComponent(key) {
  const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, paths[key]), 'utf8'));
  const script = sfc.script.content.replace(/^import .*;\s*$/gm, '').replace('export default', 'return');
  const compiled = compiler.compile(sfc.template.content);
  if (compiled.errors.length) throw new Error(JSON.stringify(compiled.errors));
  const tools = new Proxy({}, { get: (_, name) => ({ name }) });
  const options = new Function('mapState', 'toolCom', 'rightBtn', script)(mapState, tools, {});
  const style = sfc.styles.find(s => s.lang === 'stylus');
  return { script, template: sfc.template.content, options, css: style ? stylus.render(style.content) : '' };
}
function config() {
  const c = readComponent('preview').options.data.call({ num: 0 }).defaultConfig;
  c.id = 'spacing-test';
  c.menuConfig.list = ['优惠券', '渼约币', '推广海报', '全部项目'].map((label, i) => ({
    show: true, img: '/asset/' + ['rank1_icon.png', 'rank2_icon.png', 'rank3_icon.png', 'presale.png'][i],
    info: [{ value: label }, { value: '/test-link-' + i }]
  }));
  c.number.tabVal = 1;
  c.showConfig.tabVal = 0;
  return c;
}
module.exports = { root, paths, readComponent, config };
