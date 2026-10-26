// Compile canonical SFCs in memory; test adapters replace infrastructure only.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const deps = path.join(root, '前端代码/admin/node_modules');
const compiler = require(path.join(deps, 'vue-template-compiler'));
const stylus = require(path.join(deps, 'stylus'));
const sass = require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/compile-dart-sass/node_modules/sass');
const postcss = require(path.join(deps, 'postcss'));
const paths = {
  preview: '前端代码/admin/src/components/mobilePage/home_store_list.vue',
  settings: '前端代码/admin/src/components/mobileConfig/c_home_store_list.vue',
  member: '前端代码/uniapp/pages/index/components/storeList.vue',
  checkbox: '前端代码/admin/src/components/mobileConfigRight/c_checkbox.vue',
  radio: '前端代码/admin/src/components/mobileConfigRight/c_radio.vue'
};
const upgradeScript = fs.readFileSync(path.join(root, '前端代码/admin/src/utils/diyStoreListConfig.js'), 'utf8').replace('export function', 'function');
const upgrade = new Function(upgradeScript + ';return upgradeStoreListConfig;')();
const mapState = () => ({ defaultArray() { return this.$store.state.admin.mobildConfig.defaultArray; } });
const tools = new Proxy({}, { get: (_, name) => ({ name }) });
function readComponent(key) {
  const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, paths[key]), 'utf8'));
  const script = sfc.script.content.replace(/^\s*import [^\n]+;\s*$/gm, '').replace('export default', 'return');
  const compiled = compiler.compile(sfc.template.content);
  if (compiled.errors.length) throw Error(key + ': ' + JSON.stringify(compiled.errors));
  const options = new Function('mapState', 'Setting', 'require', 'toolCom', 'rightBtn', 'upgradeStoreListConfig', 'homeStoreList', script)(
    mapState, { apiBaseURL: '/adminapi' }, name => '/asset/' + path.basename(name), tools, {}, upgrade, () => Promise.resolve({ data: [] })
  );
  const style = sfc.styles[0];
  let css = style && style.lang === 'stylus' ? stylus.render(style.content) : style && style.lang === 'scss' ? sass.renderSync({ data: style.content }).css.toString() : '';
  if (key === 'preview' || key === 'member') {
    if (key === 'member') css = css.replace(/(-?[\d.]+)rpx/g, (_, n) => 'calc(' + n + ' * var(--rpx))').replace(/__badges text/g, '__badges uni-text');
    const parsed = postcss.parse(css);
    parsed.walkRules(rule => { rule.selectors = rule.selectors.map(s => '.' + (key === 'preview' ? 'editor-pane' : 'member-pane') + ' ' + s); });
    css = parsed.toString();
  }
  return { script, template: sfc.template.content, options, css };
}
function config() {
  const c = readComponent('preview').options.data.call({ num: 0 }).defaultConfig;
  c.titleConfig.tabVal = 1;
  c.titleTxtConfig.value = '推荐门店';
  c.styleConfig.tabVal = 0;
  c.numberConfig.val = 4;
  c.storeStyleConfig.tabVal = 3;
  c.storeNumber.val = 12;
  return c;
}
function stores() {
  return ['云溪美疗中心旗舰店', '江畔店', '这是用于验证超长名称不会挤压下一张卡片的门店', '城南店'].map((name, i) => ({
    id: i + 101, name, image: '/asset/shan.png', background_image: '/asset/storeBg.png',
    address: '苏州市', detailed_address: '示例路' + (i + 1) + '号三层', day_time: '10:00 - 20:00',
    delivery_type: ['1', '2'], distance: i === 1 ? undefined : 1240, product: [{ id: 20 + i, store_name: '示例项目', image: '/asset/store.png', price: 199 }]
  }));
}
module.exports = { root, deps, paths, readComponent, upgrade, upgradeScript, config, stores };
