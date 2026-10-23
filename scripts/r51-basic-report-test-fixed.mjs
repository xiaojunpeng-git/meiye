import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdirSync, readdirSync, chmodSync, existsSync } from 'node:fs'
import { createHash } from 'node:crypto'
import path from 'node:path'
import os from 'node:os'

// Isolated, read-only Git export. Only guarded local MySQL fixtures may write;
// no production address, shared index or development business file is modified.
const evidence = process.env.R51_FIXED_EVIDENCE || 'tests/report-basic/evidence/release'
if (!['tests/report-basic/evidence/release', 'tests/report-basic/evidence/release-a2'].includes(evidence)) throw new Error('Explicit R51 evidence directory required')
const { candidateTree } = JSON.parse(readFileSync(`${evidence}/trees.json`))
const repo = process.cwd()
// Colima shares the user's home, not the macOS /private/tmp namespace.
const target = path.join(os.homedir(), '.cache/mohe-r51-immutable-tests', candidateTree.slice(0, 12))
const backend = '/var/www/html'
const network = execFileSync('docker', ['inspect', '-f', '{{range $name, $v := .NetworkSettings.Networks}}{{$name}}{{"\\n"}}{{end}}', 'mohe-cashier-app'], { encoding: 'utf8' }).trim().split('\n')[0]
if (network !== 'mohe-net') throw new Error('Expected explicitly local fixture network')
mkdirSync(path.dirname(target), { recursive: true }); mkdirSync(target)
const archive = execFileSync('git', ['archive', candidateTree, '后端代码/app', '后端代码/config', '后端代码/mohe',
  '后端代码/route', '后端代码/database', '后端代码/composer.json', 'tests/report-basic/php', 'tests/report-r51-financial/php',
  'tests/cashier-v3/php', 'tests/unified-query/php'], { maxBuffer: 128 * 1024 * 1024 })
execFileSync('tar', ['-xf', '-', '-C', target], { input: archive })
// The whole application root is mounted from the immutable export, not merely
// a PSR prefix override: ThinkPHP's default root otherwise follows vendor back
// into the hot workspace and can silently read its configuration/common file.
for (const directory of ['vendor', 'runtime']) mkdirSync(path.join(target, '后端代码', directory))
writeFileSync(path.join(target, '后端代码/.env'), '')
const freeze = directory => {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name)
    if (entry.isDirectory()) freeze(file); else chmodSync(file, 0o444)
  }
  chmodSync(directory, 0o555)
}
freeze(target)
const salarySha = createHash('sha256').update(readFileSync(path.join(target, '后端代码/app/services/report/BasicSalaryReportServices.php'))).digest('hex')
const tests = [
  'report-basic/php/catalog-contract.php', 'report-basic/php/registration-local.php', 'report-basic/php/navigation-local.php',
  'report-basic/php/business-host-local.php', 'report-basic/php/activity-local.php', 'report-basic/php/activity-host-local.php',
  'report-basic/php/entitlement-contract.php', 'report-basic/php/entitlement-local.php',
  'report-basic/php/salary-contract.php', 'report-basic/php/salary-local.php',
  'report-r51-financial/php/contract.php', 'report-r51-financial/php/local-readonly.php',
  'report-r51-financial/php/member-day-contract.php', 'report-r51-financial/php/member-day-local-readonly.php',
  'report-r51-financial/php/store-summary-contract.php', 'report-r51-financial/php/store-summary-local-readonly.php',
  'cashier-v3/php/r50-member-recharge-total-local.php', 'cashier-v3/php/r49-ordinary-card-local.php',
  'cashier-v3/php/r48-coupon-allowance-mysql.php', 'cashier-v3/php/r47-sales-debt-balance-local.php'
]
if (existsSync(path.join(target, 'tests/report-basic/php/entitlement-readonly-card-lock-local.php')))
  tests.push('report-basic/php/entitlement-readonly-card-lock-local.php')
const results = []
for (const file of tests) {
  // Standalone contracts require their class themselves; checking its bytes
  // must not preload that class under a different mounted path.
  const provenance = file === 'report-basic/php/salary-contract.php'
    ? `if(hash_file('sha256','${backend}/app/services/report/BasicSalaryReportServices.php')!=='${salarySha}')throw new RuntimeException('Fixed source hash mismatch');`
    : `$reflection=new ReflectionClass('app'.chr(92).'services'.chr(92).'report'.chr(92).'BasicSalaryReportServices');if($reflection->getFileName()!=='${backend}/app/services/report/BasicSalaryReportServices.php'||hash_file('sha256',$reflection->getFileName())!=='${salarySha}')throw new RuntimeException('Fixed source autoload mismatch');`
  const launcher = `require '${backend}/vendor/autoload.php';${provenance}require '/fixed/tests/${file}';`
  try {
    const output = execFileSync('docker', ['run', '--rm', '--network', network, '--entrypoint', 'php', '-w', backend,
      '-v', target + ':/fixed:ro', '-v', path.join(target, '后端代码') + ':' + backend + ':ro',
      '-v', path.join(repo, '后端代码/vendor') + ':' + backend + '/vendor:ro',
      '-v', path.join(repo, '后端代码/.env') + ':' + backend + '/.env:ro',
      '--tmpfs', backend + '/runtime:rw,noexec,nosuid,size=128m',
      '-e', 'R51_LOCAL_TEST=1', '-e', 'R50_LOCAL_ONLY=1',
      '-e', 'R49_LOCAL_ONLY=1', '-e', 'R49_CONFIG_FIXTURE=enabled', '-e', 'R48_LOCAL_ONLY=1', '-e', 'R47_LOCAL_TEST=1',
      '-e', 'BACKEND_ROOT=' + backend, '-e', 'CASHIER_V3_BACKEND=' + backend, 'mohe-app:local', '-r', launcher],
    { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 })
    results.push({ file, result: 'PASS', output }); console.log(file + ': PASS\n' + output.slice(-900))
  } catch (error) {
    const output = String(error.stdout || '') + String(error.stderr || '')
    results.push({ file, result: 'FAIL', output }); console.log(file + ': FAIL\n' + output.slice(-2500))
  }
  writeFileSync(`${evidence}/isolated-fixed-container-tests.json`, JSON.stringify({ candidateTree, target, applicationRootMountedFromFixedTree: true,
    autoloadPathVerified: true, results, complete: results.length === tests.length }, null, 2) + '\n')
}
if (results.some(test => test.result !== 'PASS')) process.exitCode = 1
