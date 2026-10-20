// Local simulator-only projection test. No build, upload, customer API write,
// authentication change or persisted configuration change is performed.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const assert = require('node:assert/strict');
const project = process.env.R8_MINI_PROJECT;
if (!project) throw new Error('R8_MINI_PROJECT must name the existing 008 simulator artifact');
assert.equal(JSON.parse(fs.readFileSync(path.join(project, 'project.config.json'))).appid, 'wx85d4bfd0b4b6aafb');
const api = JSON.parse(fs.readFileSync(process.env.R8_MENU_API || '/private/tmp/member-center-r8-api.json'));
const cli = process.env.WECHATIDE || '/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide';
function call(tool, args) {
  const out = cp.execFileSync(cli, ['-c', 'Codex', tool, '--project', project, ...args],
    {encoding: 'utf8', timeout: 60000});
  const parsed = JSON.parse(out.slice(out.indexOf('{')));
  if (!parsed.ok || parsed.result.success === false) throw new Error(out);
  return parsed.result;
}
call('automation_navigate', ['--action', 'switchTab', '--url', '/pages/user/index']);
try {
  const measured = call('automation_evaluate', ['--fn-source', `function() {
    var response = ${JSON.stringify(api)};
    var page = getCurrentPages().slice(-1)[0], vm = page.$vm;
    page.__r8OriginalDiy = vm.diyData;
    vm.diyData = response.data.diy_data;
    return new Promise(function(resolve) { vm.$nextTick(function() {
      resolve({route: page.route, menuCount: vm.diyData.menu.list.length,
        names: vm.diyData.menu.list.map(function(m) {return m.name;})});
    }); });
  }`]);
  const state = measured.result.result;
  assert.equal(state.menuCount, api.data.diy_data.menu.list.length);
  assert(state.names.includes('我的预约'));
  const shot = call('simulator_screenshot', ['--path', '/private/tmp/member-center-r8-mini.jpg']);
  fs.writeFileSync(path.join(__dirname, 'evidence/mini-result.json'), JSON.stringify({status: 'PASS',
    scope: 'Existing 008 package with local service-generated API response; not a production fix or physical-device acceptance',
    state, screenshot: shot.path}, null, 2));
  console.log(JSON.stringify({status: 'PASS', state, screenshot: shot.path}));
} finally {
  call('automation_evaluate', ['--fn-source', `function() {
    var page = getCurrentPages().slice(-1)[0];
    if (page.__r8OriginalDiy) {page.$vm.diyData = page.__r8OriginalDiy; delete page.__r8OriginalDiy;}
    return {restored: true};
  }`]);
}
