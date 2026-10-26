// Execute the actual Vue2 picker with the actual service tree from MySQL 5.7.
// Only HTTP transport is stubbed; no production login, selection or DIY save.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const result = JSON.parse(execFileSync('/opt/homebrew/bin/php', [path.join(__dirname, 'mysql.php')], { encoding: 'utf8' }));
const compiler = require(path.join(root, '前端代码/admin/node_modules/vue-template-compiler'));
const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, '前端代码/admin/src/components/linkaddress/index.vue'), 'utf8'));
assert.equal(compiler.compile(sfc.template.content).errors.length, 0);
let requests = [];
const errors = [];
const clone = value => JSON.parse(JSON.stringify(value));
const api = {
  pageCategory: async () => ({ data: clone(result.tree) }),
  pageLink: async (id, form) => {
    requests.push({ id, form });
    return { data: { list: clone(result.links.filter(x => x.cate_id === String(id))), count: 60 } };
  },
  positionList: async () => ({ data: { list: [{ id: 10001, name: '008 local position', status: 1 }] } })
};
const context = vm.createContext({ ...api, module: { exports: {} } });
vm.runInContext(sfc.script.content.replace(/^import .*?;\s*$/gm, '').replace('export default', 'module.exports =').replaceAll('$Message.error(err.msg)', '$Message.error(err.stack || err.msg)'), context);
const component = context.module.exports;
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const ctx = { ...component.data(), $Message: { error: e => errors.push(e), warning: e => errors.push(e) } };
  for (const [name, method] of Object.entries(component.methods)) ctx[name] = method.bind(ctx);
  ctx.getSort(); await tick(); await tick();
  assert.equal(errors.length, 0, JSON.stringify(errors));
  assert.equal(ctx.categoryData.length, 4);
  assert.equal(ctx.categoryData[0].children[0].selected, true);
  assert.equal(String(requests[0].id), '5');
  assert(ctx.basicsList.some(x => x.url === '/pages/index/index'));
  assert(ctx.userList.some(x => x.url === '/pages/users/user_card_list/index'));
  const home = ctx.basicsList.find(x => x.id === '47');
  let emitted;
  ctx.$emit = (...args) => { emitted = args; };
  ctx.getUrl(home); ctx.ok();
  assert.deepEqual(emitted, ['linkUrl', '/pages/index/index', '商城首页']);
  ctx.handleCheckChange('', ctx.categoryData[0].children.find(x => x.id === '6')); await tick();
  assert(ctx.coupon.length > 0 && ctx.integral.length > 0 && ctx.luckDraw.length > 0);
  ctx.handleCheckChange('', ctx.categoryData[0].children[0]); await tick();
  const staff = ctx.basicsList.find(x => x.id === 'staff_list');
  ctx.getUrl(staff); await tick();
  ctx.updateStaffListUrl([10001, 10001]);
  assert.equal(ctx.currenUrl, '/pages/activity/therapist_list/index?position_ids=10001');
  assert.equal(ctx.staffPositionOptions.length, 1);
  assert.deepEqual(JSON.parse(JSON.stringify(result.links)).length, 60);
  console.log('PASS MySQL5.7:', result.cases.join(', '));
  console.log('PASS actual Vue2 picker: SFC compile, category tree, initial links, link emit, marketing groups, existing staff-position filtering. No business frontend source changed.');
})().catch(e => { console.error(e); process.exitCode = 1; });
