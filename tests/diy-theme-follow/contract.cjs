// Assert the real computed branches and unchanged non-indicator behavior.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { root, compiler, component, config, paths } = require('./source-harness.cjs');
const baseline = '4670768d6e055173e101a6c62f385af0fb538cbf';
const member = component('member');
const preview = component('preview');
for (const mode of [undefined, 0, '0', 1, '1']) {
  for (const indicator of [0, 1, 2, 3]) {
    const data = config();
    if (mode === undefined) delete data.toneConfig;
    else data.toneConfig.tabVal = mode;
    data.docConfig.tabVal = indicator;
    const before = JSON.stringify(data);
    const restored = JSON.parse(before);
    const ctx = { dataConfig: restored };
    const custom = Number(mode) === 1;
    assert.equal(member.options.computed.dotColor.call(ctx).background, custom ? '#e93323' : 'var(--view-theme)');
    assert.equal(member.options.computed.dotBgColor.call(ctx).background, custom ? '#102030' : '#DDDDDD');
    assert.equal(JSON.stringify(restored), before, 'Color resolution mutated saved JSON');
  }
}
for (const [key, file] of Object.entries(paths)) {
  const previous = compiler.parseComponent(execFileSync('git', ['show', baseline + ':' + file], { cwd: root, encoding: 'utf8' }));
  const current = component(key).sfc;
  assert.equal(current.template.content, previous.template.content, 'Template/navigation changed');
  const styles = sfc => sfc.styles.map(({ content, attrs }) => ({ content, attrs }));
  assert.deepEqual(styles(current), styles(previous), 'Layout/background styles changed');
  if (key === 'preview') assert.equal(current.script.content, previous.script.content);
  else {
    const oldScript = previous.script.content;
    const start = oldScript.indexOf('\t\t\tdotBgColor()');
    const end = oldScript.indexOf('\t\t\timageStyle()', start);
    const newStart = current.script.content.indexOf('\t\t\tdotBgColor()');
    const newEnd = current.script.content.indexOf('\t\t\timageStyle()', newStart);
    assert.equal(current.script.content.slice(0, newStart) + current.script.content.slice(newEnd), oldScript.slice(0, start) + oldScript.slice(end));
  }
}
// Existing slide targets and progression are still provided by this component.
let target;
member.options.methods.goDetail.call({ $util: { JumpPath(value) { target = value; } } }, config().swiperConfig.list[2]);
assert.equal(target, '/test-link-2');
const ctx = { swiperCur: 0, imgUrls: config().swiperConfig.list };
member.options.methods.swiperChange.call(ctx, { detail: { current: 1, source: 'touch' } });
assert.equal(ctx.swiperCur, 1);
assert.equal(member.options.computed.progressValue.call({ swiperCur: 1, dataConfig: config() }).width, `${2 / 3 * 100}%`);
assert(preview.sfc.template.content.includes('colorStyle.theme'));
console.log('PASS: 4 indicators; numeric/string modes; legacy theme fallback; custom colors; saved JSON; unchanged templates/styles/non-color logic; slide navigation/progress.');
