import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'

// Fixed-tree services against the guarded local database; business fixtures
// always roll back. No production connection or mutable test-source copy.
const evidence = 'tests/cashier-v3/evidence/R49-release'
const { candidateTree } = JSON.parse(readFileSync(`${evidence}/trees.json`))
const target = '/tmp/r49-fixed-' + candidateTree.slice(0, 12)
const container = 'mohe-cashier-app'
const docker = (...args) => execFileSync('docker', ['exec', container, ...args], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 })
docker('mkdir', '-p', target)
const archive = execFileSync('git', ['archive', candidateTree, '后端代码/app', '后端代码/config', '后端代码/mohe',
  '后端代码/route', '后端代码/database', '后端代码/composer.json', 'tests/cashier-v3/php'], { maxBuffer: 128 * 1024 * 1024 })
execFileSync('docker', ['exec', '-i', container, 'tar', '-xf', '-', '-C', target], { input: archive })
const backend = target + '/后端代码'
docker('ln', '-sfn', '/var/www/html/vendor', backend + '/vendor')
docker('ln', '-sfn', '/var/www/html/.env', backend + '/.env')
docker('mkdir', '-p', backend + '/runtime')
docker('chmod', '-R', 'a-w', backend + '/app', backend + '/config', backend + '/mohe', backend + '/route', backend + '/database', target + '/tests')
const results = []
for (const name of ['r49-checkout-cross-local.php', 'r49-ordinary-card-local.php', 'r48-coupon-allowance-mysql.php',
  'r47-sales-debt-balance-local.php', 'r47-sales-debt-v3-allocation-local.php']) {
  const file = target + '/tests/cashier-v3/php/' + name
  const launcher = `$loader=require '${backend}/vendor/autoload.php'; $loader->setPsr4('app\\\\','${backend}/app'); $loader->setPsr4('mohe\\\\','${backend}/mohe'); require '${file}';`
  try {
    const output = docker('env', 'R49_LOCAL_ONLY=1', 'R48_LOCAL_ONLY=1', 'R47_LOCAL_TEST=1',
      'R49_CONFIG_FIXTURE=enabled', 'BACKEND_ROOT=' + backend, 'CASHIER_V3_BACKEND=' + backend, 'php', '-r', launcher)
    results.push({ name, status: 'PASS', output }); console.log(name + ': PASS\n' + output.slice(-1200))
  } catch (e) {
    results.push({ name, status: 'FAIL', output: String(e.stdout || '') + String(e.stderr || '') })
    console.log(name + ': FAIL\n' + String(e.stdout || '').slice(-1200) + String(e.stderr || '').slice(-2000))
  }
}
writeFileSync(`${evidence}/isolated-tests.json`, JSON.stringify({ candidateTree, target, results }, null, 2) + '\n')
if (results.some(r => r.status !== 'PASS')) process.exitCode = 1
