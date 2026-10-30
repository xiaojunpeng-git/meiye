// Exercise canonical Vue2 scripts: load failure must never submit defaults.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/accepted-service-menu.json')));
const storeScript = read('前端代码/admin/src/store/modules/admin/modules/userTemplateConfig.js')
  .replace(/^import .*;\s*$/gm, '').replace('export default', 'module.exports =');
const source = read('前端代码/admin/src/pages/setting/devise/users.vue');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/^import .*;\s*$/gm, '').replace('export default', 'module.exports =');
function evaluate(code, values) {
  const ctx = {module: {exports: {}}, ...values};
  vm.runInNewContext(code, ctx);
  return ctx.module.exports;
}
function pageOptions(memberSave) {
  const names = ['uploadPic', 'Member', 'Order', 'OrderStatic', 'Poster', 'Menus', 'MerMenus',
    'AgentMenus', 'StoreMenus', 'MemberConfig', 'OrderConig', 'OrderStaticConfig',
    'PosterConfig', 'MenuConfig', 'MerMenuConfig', 'AgentMenuConfig', 'StoreMenuConfig'];
  return evaluate(script, {...Object.fromEntries(names.map(n => [n, {}])), mapState: () => ({}), memberSave});
}
(async () => {
  let saved = 0, lastPayload;
  const options = pageOptions(async payload => { saved++; lastPayload = JSON.parse(JSON.stringify(payload)); return {msg: 'ok'}; });
  const messages = [];
  const state = {member: {style: 2}, menu: fixture, poster: {is_show: 0, list: []},
    merMenu: {list: []}, agentMenu: {list: []}, storeMenu: {list: []}};
  const page = {...options.data(), $Message: {error: m => messages.push(m), success() {}},
    $store: {state: {admin: {userTemplateConfig: state}}, dispatch: () => Promise.reject(new Error('500'))}};
  options.created.call(page);
  await new Promise(r => setImmediate(r));
  assert.equal(page.configurationLoaded, false);
  options.methods.submit.call(page);
  assert.equal(saved, 0);
  assert.match(messages[0], /加载失败/);
  page.configurationLoaded = true;
  await options.methods.submit.call(page);
  assert.equal(saved, 1, 'icon-only menu must save without requiring images');
  assert.equal(page.loadingExist, false);
  page.loadingExist = true;
  options.methods.submit.call(page);
  assert.equal(saved, 1, 'duplicate click submitted twice');
  assert.match(source, /:disabled="!configurationLoaded"/);
  // Automatic name matching and its generic fallback are both valid saved choices.
  page.loadingExist = false;
  const originalMenu = state.menu;
  for (const item of [
    {name:'我的预约',pic:'',icon:''}, {name:'自定义入口',pic:'',icon:''},
    {name:'我的卡包',pic:[]}, {name:'我的余额'},
    {name:'我的收藏',pic:'https://assets.example/custom.png',icon:''}
  ]) {
    state.menu = {...fixture,list:[{...item,url:'/original-route'}]};
    const before = JSON.stringify(state.menu); const count = saved;
    await options.methods.submit.call(page);
    assert.equal(saved,count+1,'renderable automatic/image entry must save');
    assert.equal(JSON.stringify(state.menu),before,'save must not synthesize an icon/image');
    assert.equal(JSON.stringify(lastPayload.member.menu),before,'submitted visual choice unchanged');
  }
  for (const [key,message] of [['name','标题'],['url','链接']]) {
    state.menu={...fixture,list:[{name:'我的预约',url:'/original-route',pic:'',icon:'',[key]:''}]};
    const count=saved;options.methods.submit.call(page);assert.equal(saved,count);
    assert(messages[messages.length-1].includes(message),'existing required checks preserved');
  }
  state.menu=originalMenu;


  const loaded = {member: {...state, member: {style: 2}},
    routine_my_menus: fixture.list.map((m, i) => ({...m, pic: i ? '' : 'https://assets.example/icon.png'})),
    routine_my_banner: [], h5_avatar: ''};
  const store = evaluate(storeScript, {getMember: async () => ({data: loaded})});
  let committed;
  await store.actions.getMember({state: store.state, commit: (_, data) => {committed = data;}});
  assert.equal(committed.menu.list.length, 14);
  assert.equal(committed.menu.list[0].pic, 'https://assets.example/icon.png');
  assert.equal(committed.menu.list[1].icon, fixture.list[1].icon);
  const failed = evaluate(storeScript, {getMember: async () => {throw new Error('failed');}});
  await assert.rejects(failed.actions.getMember({state: failed.state, commit() {throw new Error('must not commit');}}));
  const failingPage = pageOptions(async () => {throw new Error('save failed');});
  page.loadingExist = false;
  await assert.rejects(failingPage.methods.submit.call(page));
  assert.equal(page.loadingExist, false);
  console.log('member-center editor PASS: failed load blocks save, icon-only, URL/array compatibility, duplicate click, failed save resets');
})().catch(error => {console.error(error); process.exitCode = 1;});
