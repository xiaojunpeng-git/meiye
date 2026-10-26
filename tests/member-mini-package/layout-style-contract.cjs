const fs = require('fs'), path = require('path'), cp = require('child_process'), assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const deps = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler = require(deps + '/vue-template-compiler'), parser = require(deps + '/@babel/parser');
const baseline = '39f927b3c1a94797b4a04349b0f62a5af1d1997e';
const paths = { staff: '前端代码/uniapp/pages/index/components/staffList.vue', footer: '前端代码/uniapp/components/pageFooter/index.vue' };
function load(file, prior = false, uni = {}) {
  const raw = prior ? cp.execFileSync('git', ['show', baseline + ':' + file], { cwd: root, encoding: 'utf8' }) : fs.readFileSync(path.join(root, file), 'utf8');
  const sfc = compiler.parseComponent(raw);
  assert.deepEqual(compiler.compile(sfc.template.content).errors, []);
  let script = sfc.script.content;
  const edits = parser.parse(script, { sourceType: 'module' }).program.body.flatMap(n => n.type === 'ImportDeclaration' ? [[n.start, n.end, '']] : n.type === 'ExportDefaultDeclaration' ? [[n.start, n.declaration.start, 'return ']] : []);
  for (const [a,b,v] of edits.sort((a,b) => b[0]-a[0])) script = script.slice(0,a)+v+script.slice(b);
  return { sfc, options: new Function('uni','mapGetters','getNavigation','getDiyVersion', script)(uni, () => ({}), () => {}, () => {}) };
}
const staff = load(paths.staff), oldStaff = load(paths.staff, true);
assert.equal(staff.sfc.script.content, oldStaff.sfc.script.content, 'Staff data, requests and config math unchanged');
assert.equal(staff.sfc.styles[0].content, oldStaff.sfc.styles[0].content, 'Staff CSS unchanged');
const normalize = value => value.replace(/<!--[^]*?-->/g, '').replace(/:style="\[(moduleStyle|titleStyle|textStyle|cardStyle|buttonStyle)\]"/g, ':style="$1"').trim();
assert.equal(normalize(staff.sfc.template.content), normalize(oldStaff.sfc.template.content));
assert.equal((staff.sfc.template.content.match(/:style="\[/g)||[]).length, 9);
for (const side of [0, 12, 22, 30]) {
  const context = { dataConfig: { prConfig: {val:side}, topConfig: {val:12}, bottomConfig:{val:8}, mbConfig:{val:3} }, ...staff.options.methods };
  assert.equal(staff.options.computed.moduleStyle.call(context).padding, `24rpx ${side*2}rpx 16rpx`);
}
let measured = 90.2, removed, selected, callback;
const uni = { createSelectorQuery() { return { in(c) { selected=c; return this; }, select(s) { assert.equal(s,'.page-footer-fixed'); return this; }, boundingClientRect(cb) { callback=cb; return this; }, exec() { callback({height:measured}); } }; }, offWindowResize(cb) { removed=cb; } };
const footer = load(paths.footer, false, uni), oldFooter=load(paths.footer,true);
// All pre-existing navigation/subscription/cache commands retain their exact
// implementation; this task changes only geometry and content reservation.
for (const [name, method] of Object.entries(oldFooter.options.methods)) assert.equal(String(footer.options.methods[name]),String(method),name);
for (const name of ['txtActiveColor','txtColor','footerToneStyle','bgColor']) assert.equal(String(footer.options.computed[name]),String(oldFooter.options.computed[name]),name);
const context={showTabBar:true,footerHeight:0,$nextTick:fn=>fn()};
footer.options.methods.measureFooter.call(context);
assert.equal(selected,context);assert.equal(context.footerHeight,91);
context.showTabBar=false;footer.options.methods.measureFooter.call(context);assert.equal(context.footerHeight,0);
context.footerResizeHandler=()=>{};footer.options.beforeDestroy.call(context);assert.equal(removed,context.footerResizeHandler);
let count=0;context.showTabBar=true;context._isDestroyed=true;context.$nextTick=fn=>{count++;fn();};footer.options.methods.measureFooter.call(context);assert.equal(count,1);assert.equal(context.footerHeight,0);
const color=x=>({color:[{item:x}]});
for (const floating of [0,1]) for(const navStyle of [0,1,2]) {
  const newData={name:'navigation',navConfig:{tabVal:floating},navStyleConfig:{tabVal:navStyle},prConfig:{val:20},mbConfig:{val:10},topConfig:{val:4},bottomConfig:{val:6},fillet:{val:8,type:0},bgColor:color('#ffffff'),bgColor2:color('#102030')};
  const style=footer.options.computed.componentStyle.call({newData});
  assert.equal(style['padding-top'],'8rpx');
  if(floating) { assert.equal(style['margin-left'],'40rpx');assert.equal(style['margin-bottom'],'20rpx');assert.equal(style['padding-bottom'],'12rpx'); }
  else {assert.equal(style['--footer-bottom-padding'],'12rpx');assert(!('padding-bottom' in style));}
}
assert(!footer.sfc.template.content.includes('safe-area-inset-bottom'));
assert.match(footer.sfc.styles[0].content,/\.page-footer--docked\s*\{[^}]*env\(safe-area-inset-bottom\)/);
if (process.argv[2]) {
  // A source contract alone cannot prove native style serialization. Check the
  // emitted WXML and measurements from the actual local simulator separately.
  const directory=path.resolve(process.argv[2]);
  const wxml=fs.readFileSync(path.join(directory,'008-member/pages/index/components/staffList.wxml'),'utf8');
  for(const name of ['moduleStyle','titleStyle','textStyle','cardStyle','buttonStyle']) assert(!wxml.includes('style="{{('+name+')}}"'),'Bare object leaked into WXML: '+name);
  assert(wxml.includes('$root.s0'));
  const geometry=JSON.parse(fs.readFileSync(path.join(directory,'runtime-geometry.json')));
  const system=geometry.system.result.result.result;
  assert.equal(geometry.staffPadding.result,'22px');
  assert(!geometry.staffStyle.result.includes('[object Object]'));
  assert.equal(geometry.barOffset.result.top+geometry.barSize.result.height,system.windowHeight);
  assert.equal(geometry.spacer.result.height,geometry.fixedSize.result.height);
}
console.log('PASS: nine mini style bindings; staff zero/custom margins; six footer modes; actual-height spacer, hide/destroy/cleanup; original navigation/theme methods unchanged.');
module.exports = { root, paths, load };
