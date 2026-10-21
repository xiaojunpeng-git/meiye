// Assert indicator behavior and non-background compatibility. The later R8
// restoration removes only the three obsolete member background overrides.
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
  const styles = sfc => sfc.styles.map(({ content, attrs }) => ({
    content: content.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ').trim(), attrs
  }));
  if (key === 'member') {
    // All other presentation remains the accepted baseline, including search,
    // image radius, layout and custom indicator styles. Comments are nonsemantic.
    for (const declaration of [
      '\tbackground: #fbf7f4;\n',
      '\t.bg-img { opacity: .16; }\n',
      '\t.bag-gradient { background: linear-gradient(180deg, rgba(251,247,244,.74), rgba(251,247,244,.98)); }\n'
    ]) {
      assert.equal(previous.styles[0].content.split(declaration).length, 2, 'Background baseline changed');
      previous.styles[0].content = previous.styles[0].content.replace(declaration, '');
    }
  }
  assert.deepEqual(styles(current), styles(previous), 'Unrelated layout or styles changed');
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
console.log('PASS: 4 indicators; numeric/string modes; legacy theme fallback; custom colors; saved JSON; only three allowed background overrides removed; unchanged templates/scripts/other styles; slide navigation/progress.');
