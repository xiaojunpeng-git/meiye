import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'

// Local synthetic database tests only. Business scripts independently guard the
// Docker database identity and roll back every generated order and entitlement.
const run = (command, args, options = {}) => execFileSync(command, args,
  { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, ...options })
const hash = file => createHash('sha256').update(readFileSync(file)).digest('hex')
const source = '后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php'
const sourceHash = hash(source)
const container = 'mohe-cashier-app'
const temp = '/tmp/r50-contract-' + sourceHash.slice(0, 12)
run('docker', ['exec', container, 'mkdir', '-p', temp])
// Immutable contract inputs, not a second editable application. No frontend
// build or page changes are needed for this backend-only correction.
const frontend = ['src/services/cashierV3ActionManifest.js', 'src/views/CashierWorkbenchView.vue']
const archive = run('tar', ['-cf', '-', '-C', '前端代码/cashier-v3', ...frontend], { encoding: null })
run('docker', ['exec', '-i', container, 'tar', '-xf', '-', '-C', temp], { input: archive })
run('docker', ['exec', container, 'chmod', '-R', 'a-w', temp])
const results = []
for (const [name, env] of [
  ['custom-card-configuration-contract.php', ['CASHIER_V3_BACKEND_ROOT=/var/www/html', 'CASHIER_V3_FRONTEND_ROOT=' + temp]],
  ['r49-ordinary-card-local.php', ['R49_LOCAL_ONLY=1', 'R49_CONFIG_FIXTURE=enabled']],
  ['r49-checkout-cross-local.php', ['R49_LOCAL_ONLY=1', 'R49_CONFIG_FIXTURE=enabled']],
  ['m010-custom-card-template-local.php', ['M010_LOCAL_TEST=1']],
]) {
  const path = 'tests/cashier-v3/php/' + name
  try {
    const output = run('docker', ['exec', '-i', '-w', '/var/www/html', container, 'env', ...env, 'php'],
      { input: readFileSync(path) })
    results.push({ name, sha256: hash(path), status: 'PASS', output })
    console.log(name + ': PASS\n' + output.slice(-250))
  } catch (error) {
    results.push({ name, sha256: hash(path), status: 'FAIL', output: String(error.stdout || '') + String(error.stderr || '') })
    console.log(name + ': FAIL\n' + results[results.length - 1].output.slice(-1800))
  }
}
const lint = run('docker', ['exec', '-w', '/var/www/html', container, 'php', '-l', source.slice('后端代码/'.length)])
if (hash(source) !== sourceHash) throw Error('Source changed during tests; results are not a fixed candidate')
writeFileSync('tests/cashier-v3/evidence/R50-local-tests.json', JSON.stringify({
  source, sourceHash, head: run('git', ['rev-parse', 'HEAD']).trim(),
  testedAt: new Date().toISOString(), results, lint,
  boundary: 'Local synthetic transactions rolled back; no production access or user checkout retry',
}, null, 2) + '\n')
if (results.some(r => r.status !== 'PASS')) process.exitCode = 1
