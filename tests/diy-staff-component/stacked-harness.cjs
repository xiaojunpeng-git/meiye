// Canonical Vue2 SFC compilation; adapt uni-app host tags/units only for a local
// browser fixture. Never fetch production staff, authentication or configuration.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const deps = path.join(root, '前端代码/admin/node_modules');
const compiler = require(path.join(deps, 'vue-template-compiler'));
const postcss = require(path.join(deps, 'postcss'));
const sass = require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/compile-dart-sass/node_modules/sass');
const paths = { preview: '前端代码/admin/src/components/mobilePage/home_staff_list.vue', member: '前端代码/uniapp/pages/index/components/staffList.vue' };
function component(key) {
  const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, paths[key]), 'utf8'));
  const script = sfc.script.content.replace(/^\s*import [^\n]+;\s*$/gm, '').replace('export default', 'return');
  let template = sfc.template.content;
  if (key === 'member') template = template.replace(/<image\b([^>]*?)\/>/g, '<img$1>').replace(/scroll-view/g, 'div').replace(/(<\/?)(view|text)\b/g, (_, p, tag) => p + (tag === 'view' ? 'div' : 'span')).replace(/@tap/g, '@click');
  const compiled = compiler.compile(template);
  if (compiled.errors.length) throw new Error(JSON.stringify(compiled.errors));
  let css = key === 'member' ? sass.renderSync({ data: sfc.styles[0].content }).css.toString().replace(/(-?[\d.]+)rpx/g, (_, n) => 'calc('+n+' * var(--rpx))') : sfc.styles[0].content;
  const parsed = postcss.parse(css);
  parsed.walkRules(rule => { rule.selectors = rule.selectors.map(s => '.'+key+'-pane '+s); });
  return { script, template, css: parsed.toString() };
}
function source() {
  const preview = component('preview'), member = component('member');
  const options = new Function('mapState', 'require', preview.script)(() => ({}), () => '/avatar.png');
  const config = options.data.call({ num: 0 }).defaultConfig;
  config.numberConfig.tabVal = 1;
  config.checkboxInfo.type = [0,1,2,3];
  const people = ['林老师', '这是用于验证截断的超长员工姓名', '张老师', '陈老师', '王老师', '李老师'].map((name,i) => ({ id: i+1, staff_name: name, avatar: '/avatar.png', position_label: '美容师', position_level_label: '高级', staff_intro: '用心服务每位顾客' }));
  return { preview, member, config, people };
}
module.exports = { root, deps, component, source };
