// R8 background restoration is presentation-only. The editor remains the source
// of configuration semantics; switching indicator tone must not recolor images.
const assert = require('node:assert/strict');
const cp = require('node:child_process');
const path = require('node:path');
const {root, compiler, component, config, paths, dependencies} = require('./source-harness.cjs');
const sass = require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/compile-dart-sass/node_modules/sass');
const postcss = require(path.join(dependencies, 'postcss'));
const baseline = '0f5ea37e314cb1058daf0c3aac21c4c387b3e108';
const current = component('member');
const previous = compiler.parseComponent(cp.execFileSync('git', ['show', baseline + ':' + paths.member], {cwd: root, encoding: 'utf8'}));
assert.equal(current.sfc.template.content, previous.template.content, 'Template changed');
assert.equal(current.sfc.script.content, previous.script.content, 'Script, request or navigation changed');
assert.equal(component('preview').sfc.script.content,
  compiler.parseComponent(cp.execFileSync('git', ['show', baseline + ':' + paths.preview], {cwd: root, encoding: 'utf8'})).script.content,
  'Editor configuration semantics changed');
function declarations(content) {
  const css = sass.renderSync({data: content}).css.toString();
  const entries = [];
  postcss.parse(css).walkDecls(node => entries.push([node.parent.selector, node.prop, node.value]));
  return entries;
}
const removed = [
  ['.page_count', 'background', '#fbf7f4'],
  ['.page_count .bg-img', 'opacity', '.16'],
  ['.page_count .bag-gradient', 'background', 'linear-gradient(180deg, rgba(251, 247, 244, 0.74), rgba(251, 247, 244, 0.98))']
];
const old = declarations(previous.styles[0].content), next = declarations(current.sfc.styles[0].content);
// Sass may normalize numeric literals; match the property value semantically.
const isRemoved = row => removed.some(([selector, prop, value]) => row[0] === selector && row[1] === prop
  && (row[2] === value || (prop === 'opacity' && Number(row[2]) === Number(value))));
assert.equal(old.filter(isRemoved).length, 3, 'Expected exactly three legacy declarations');
assert.deepEqual(next, old.filter(row => !isRemoved(row)), 'CSS changed beyond background restoration');
let cases = 0;
for (const mode of [undefined, 0, '0', 1, '1']) {
  for (const color of ['transparent', '#ffffff', '#2468ac']) {
    const data = config();
    if (mode === undefined) delete data.toneConfig; else data.toneConfig.tabVal = mode;
    data.gradientColor.color[0].item = color;
    const saved = JSON.stringify(data), ctx = {dataConfig: JSON.parse(saved)};
    const gradient = current.options.computed.bgGradientStyle.call(ctx)['background-image'];
    assert.equal(gradient, `linear-gradient(to bottom, rgba(245,245,245,0) 0%, rgba(245,245,245,0) 50%, ${color} 100%)`);
    const editor = component('preview').options.computed.bgGradientStyle.call({gradientColor: color});
    assert.equal(gradient, editor['background-image'], 'Editor/member gradient differs');
    assert.equal(JSON.stringify(ctx.dataConfig), saved, 'Rendering mutated stored configuration');
    cases++;
  }
}
const oldConfig = config(); delete oldConfig.gradientColor;
assert(current.options.computed.bgGradientStyle.call({dataConfig: oldConfig})['background-image'].endsWith('#f5f5f5 100%)'), 'Legacy fallback changed');
const state = {swiperCur: 0, imgUrls: [{img: 'first.jpg'}, {img: 'second.jpg'}]};
current.options.methods.swiperChange.call(state, {detail: {current: 1, source: 'autoplay'}});
assert.equal(state.bgColor, 'second.jpg', 'Background stopped following the current slide');
assert.equal(state.swiperCur, 1);
console.log(`PASS: exactly 3 CSS declarations removed; ${cases} editor/member gradient cases; legacy fallback; current-slide background; unchanged scripts/templates/search/layout.`);
