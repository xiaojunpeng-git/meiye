/* 检查固定候选中的实际文件；PHP使用与本地运行一致的7.4容器。 */
const fs = require('node:fs'), path = require('node:path'), { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..'), evidence = path.resolve(root, '../任务管理/进行中/美业大学成长管理/证据');
const candidate = JSON.parse(fs.readFileSync(path.join(evidence, '固定验收候选.json')));
const files = Object.values(candidate.groups).flat().map(r => r.path), results = [];
function check(name, executable, args, cwd = root) {
  const r = spawnSync(executable, args, { cwd, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
  results.push({ name, status: r.status === 0 ? 'PASS' : 'FAIL', output: (r.stdout || '') + (r.stderr || '') });
  console.log(name + ': ' + results.at(-1).status);
}
const phpFiles = files.filter(p => p.endsWith('.php'));
check('PHP7.4语法', 'docker', ['exec', 'mohe-growth-local-app', 'php', '-r', '$bad=false;foreach(array_slice($argv,1) as $file){passthru("php -l ".escapeshellarg($file),$code);if($code)$bad=true;}exit($bad?1:0);', ...phpFiles.map(p => '/workspace/' + p)]);
const admin = path.join(root, '前端代码/admin');
check('Vue2 ESLint', process.execPath, ['node_modules/eslint/bin/eslint.js', 'src/pages/growth', 'src/api/growth.js', 'src/router/modules/growth.js'], admin);
const compiler = require(path.join(admin, 'node_modules/vue-template-compiler'));
for (const name of fs.readdirSync(path.join(admin, 'src/pages/growth')).filter(n => n.endsWith('.vue'))) {
  const source = compiler.parseComponent(fs.readFileSync(path.join(admin, 'src/pages/growth', name), 'utf8'));
  const compiled = compiler.compile(source.template.content);
  results.push({ name: 'Vue2模板 ' + name, status: compiled.errors.length ? 'FAIL' : 'PASS', output: compiled.errors.join('\n') });
}
for (const file of files.filter(p => p.endsWith('.cjs'))) check('JS语法 ' + file, process.execPath, ['--check', file]);
const mobilePages = JSON.parse(fs.readFileSync(path.join(root, '前端代码/mobile-vue3/pages.json')));
results.push({ name: '手机原启动入口保留', status: mobilePages.pages[0].path === 'src/app/pages/bootstrap/index' ? 'PASS' : 'FAIL', output: mobilePages.pages[0].path });
for(const file of files.filter(p=>p.endsWith('.sh')))check('Shell语法 '+file,'/bin/sh',['-n',file]);
check('Python语法','/usr/bin/python3',['-c','import ast,sys;[ast.parse(open(p,encoding="utf8").read(),filename=p) for p in sys.argv[1:]]',...files.filter(p=>p.endsWith('.py'))]);
check('旧路由与工作台内容保留', process.execPath, ['tests/growth/legacy-entry-contract.cjs']);
check('既有规范身份契约', '/opt/homebrew/bin/php', ['tests/mobile-auth/php/canonical-identity-contract.php']);
check('既有员工访问契约', '/opt/homebrew/bin/php', ['tests/mobile-auth/php/mobile-employee-access-contract.php']);
fs.writeFileSync(path.join(evidence, 'source-check-result.json'), JSON.stringify({ candidate: candidate.candidate, checked_at: new Date().toISOString(), results }, null, 2));
if (results.some(r => r.status !== 'PASS')) { for (const r of results.filter(r => r.status !== 'PASS')) console.error(r); process.exitCode = 1; }
