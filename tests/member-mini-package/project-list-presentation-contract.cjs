const fs = require('fs'), path = require('path'), cp = require('child_process'), assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const deps = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler = require(deps + '/vue-template-compiler'), parser = require(deps + '/@babel/parser');
const baseline = '277ccceb48d07eee9aac4ce27981398620605a67';
const files = ['前端代码/uniapp/pages/users/user_card_list/index.vue', '前端代码/uniapp/pages/goods/member_checkout/index.vue'];
function load(file) {
  const raw = fs.readFileSync(path.join(root, file), 'utf8'), sfc = compiler.parseComponent(raw);
  assert.deepEqual(compiler.compile(sfc.template.content).errors, []);
  const prior = compiler.parseComponent(cp.execFileSync('git', ['show', baseline + ':' + file], {cwd: root, encoding: 'utf8'}));
  // Query ownership, filters, coupons/points, settlement and notification calls
  // are outside this presentation-only patch and must remain byte-identical.
  assert.equal(sfc.script.content, prior.script.content, file + ' business script changed');
  let script = sfc.script.content;
  const edits = parser.parse(script, {sourceType: 'module'}).program.body.flatMap(n => n.type === 'ImportDeclaration' ? [[n.start, n.end, '']] : n.type === 'ExportDefaultDeclaration' ? [[n.start, n.declaration.start, 'return ']] : []);
  for (const [a,b,v] of edits.sort((a,b) => b[0]-a[0])) script = script.slice(0,a)+v+script.slice(b);
  return {sfc, script:'const pageFooter={},baseDrawer={};\n'+script};
}
const cards = [
  {id:1, card_name:'单项目', write_surplus_times:1, status:1, write_valid:1},
  {id:2, card_name:'疗程卡', product_type:5, status:1, related_list:[{id:21,project_name:'卡内项目',write_surplus_times:4,cate_name:'美容项目'}]},
  {id:3, card_name:'历史卡保持一行', product_type:5, historical_display_single:true, status:1, write_surplus_times:3, related_list:[{id:31}]},
  {id:4, card_name:'用完', status:1, write_surplus_times:0},
  {id:5, card_name:'失效', status:2, write_surplus_times:2},
  {id:6, card_name:'已升级', status:1, is_card_upgraded:1, write_surplus_times:2}
];
async function verify() {
  const project = load(files[0]), checkout = load(files[1]);
  assert.doesNotMatch(project.sfc.template.content, /<\/?scroll-view\b/);
  assert.doesNotMatch(project.sfc.styles[0].content.match(/\.content\s*\{[^}]+/)[0], /height:\s*0/);
  const font = fs.readFileSync(path.join(root, '前端代码/uniapp/static/iconfont/iconfont.css'), 'utf8');
  for (const icon of ['icon-ic_money','icon-ic_card','icon-ic_switch','icon-a-ic_wechatpay','icon-a-ic_alipay']) {
    assert(checkout.sfc.template.content.includes(icon)); assert(font.includes('.' + icon + ':before'));
  }
  assert.match(checkout.sfc.styles[0].content, /\.payment-icon\s*\{[^}]+var\(--view-theme\)/);
  assert.doesNotMatch(checkout.sfc.template.content, /value="offline"/);
  const navigations=[], requests=[];
  const options = new Function('userCardList','staffUserInfo','mapGetters','uni','getCurrentPages',project.script)(
    params => { requests.push(params); return Promise.resolve({data:{list:cards}}); }, () => Promise.resolve({data:{}}), () => ({}),
    {navigateTo:v=>navigations.push(v.url)}, () => []);
  const ctx={...options.data(),...options.methods,uid:421,$set:(target,key,v)=>target[key]=v};
  for(const [key,fn] of Object.entries(options.computed)) Object.defineProperty(ctx,key,{get:()=>fn.call(ctx)});
  ctx.initPage(); await new Promise(resolve=>setImmediate(resolve));
  assert.deepEqual(requests,[{uid:421}]); assert.equal(ctx.cardList.length,6); assert.equal(ctx.loading,false); assert.equal(ctx.loadend,true);
  assert.equal(ctx.projectList.length,6); assert.equal(ctx.filteredProjects.length,3);
  assert.equal(ctx.categoryGroups[0].totalRemain,4); assert.equal(ctx.uncategorizedProjects.length,2);
  ctx.switchFilter('all'); assert.equal(ctx.filteredProjects.length,6);
  ctx.toggleCategory('美容项目'); assert.equal(ctx.isCategoryExpanded('美容项目'),false);
  ctx.toggleCategory('美容项目'); assert.equal(ctx.isCategoryExpanded('美容项目'),true);
  ctx.goProject(ctx.projectList[0]); ctx.goProject(ctx.projectList[4]);
  assert.deepEqual(navigations,['/pages/users/user_card/index?id=1']);
  ctx.cardList=[]; assert.equal(ctx.hasDisplayContent,false);
  const failedOptions = new Function('userCardList','staffUserInfo','mapGetters','uni','getCurrentPages',project.script)(
    () => Promise.reject('network failure'), () => Promise.resolve({data:{}}), () => ({}), {}, () => []);
  const failed={...failedOptions.data(),...failedOptions.methods,viewUid:421,isViewingOther:false};
  failed.getCardList(); await new Promise(resolve=>setImmediate(resolve));
  assert.equal(failed.loading,false); assert.equal(failed.loadend,true); assert.deepEqual(failed.cardList,[]);
  if(process.argv[2]) {
    const out=path.join(path.resolve(process.argv[2]),'008-member');
    const wxml=fs.readFileSync(path.join(out,'pages/users/user_card_list/index.wxml'),'utf8');
    assert.doesNotMatch(wxml,/<scroll-view\b/);
    const css=fs.readFileSync(path.join(out,'pages/users/user_card_list/index.wxss'),'utf8');
    assert.doesNotMatch(css.match(/\.content[^}]+/)[0], /height:0/);
    const pay=fs.readFileSync(path.join(out,'pages/goods/member_checkout/index.wxml'),'utf8');
    assert(pay.includes('payment-icon')); assert(!pay.includes('value="offline"'));
  }
  console.log('PASS: two business scripts identical; natural page scrolling; rights/filter/category/history/detail invariants; five packaged glyphs and theme; no offline payment');
}
if(require.main===module) verify().catch(e=>{console.error(e);process.exitCode=1;});
module.exports={root,deps,baseline,files,load,cards};
