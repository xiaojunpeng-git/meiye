import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'

// Reproducible local PHP7.4 checks. Only canonical sources enter the container
// via stdin; no second editable tree and no SSH/production access are created.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
if (process.argv.length !== 2 || fs.realpathSync(root) !== '/Users/xiaojunpeng/Desktop/魔核/美业/美容源码') throw Error('CANONICAL_NO_ARGUMENTS_REQUIRED')
const sha = value => createHash('sha256').update(value).digest('hex')
const files = [
  '后端代码/app/services/cashier/v3/checkout/provider/CashierV3MemberBalanceProvider.php',
  'scripts/lib/r51-010-staff-identity-plan.php',
  'scripts/lib/r51-010-staff-identity-apply.php',
  'tests/cashier-v3-member-balance/php/contract.php',
  'tests/cashier-v3-member-balance/php/read-only-readiness-contract.php',
  'tests/cashier-v3-member-balance/php/read-only-minimum-privilege-mysql.php',
  'tests/report-basic/php/010-staff-identity-plan.php',
  'tests/report-basic/php/010-staff-identity-mysql.php',
]
const sources = Object.fromEntries(files.map(file => [file, fs.readFileSync(path.join(root,file))]))
const hashes = Object.fromEntries(files.map(file => [file, sha(sources[file])]))
const results = []
for (const file of files.filter(file => file.startsWith('tests/'))) {
  let input = sources[file].toString()
  if (file.endsWith('010-staff-identity-plan.php')) {
    const marker = "require_once dirname(__DIR__,3).'/scripts/lib/r51-010-staff-identity-plan.php';"
    if (input.split(marker).length !== 2) throw Error('PLANNER_TEST_LOADER_CHANGED')
    const encoded = sources['scripts/lib/r51-010-staff-identity-plan.php'].toString('base64')
    input = input.replace(marker, `eval(substr(base64_decode('${encoded}', true), 5));`)
  }
  if (file.endsWith('010-staff-identity-mysql.php')) {
    const marker = "if (!class_exists('R51StaffIdentityPlanner', false)) require_once __DIR__.'/r51-010-staff-identity-plan.php';"
    const applier = sources['scripts/lib/r51-010-staff-identity-apply.php'].toString()
    if (applier.split(marker).length !== 2) throw Error('APPLIER_LOADER_CHANGED')
    const libraries = [sources['scripts/lib/r51-010-staff-identity-plan.php'], Buffer.from(applier)]
      .map(source => `eval(substr(base64_decode('${source.toString('base64')}', true), 5));`).join('\n')
    const strict = 'declare(strict_types=1);'
    if (input.split(strict).length !== 2) throw Error('MYSQL_TEST_HEADER_CHANGED')
    input = input.replace(strict, `${strict}\n${libraries}`)
  }
  const output = execFileSync('docker', ['exec','-i','-e','R51_LOCAL_TEST=1','-e','R51_IDENTITY_LOCAL_TEST=1','-e',
    'CHECKOUT_BALANCE_BACKEND_ROOT=/var/www/html','mohe-app','php'],
  { input, cwd:root, timeout:120000, maxBuffer:4*1024*1024, stdio:['pipe','pipe','pipe'] }).toString().trim()
  const last = output.split('\n').at(-1)
  if (!last || /failed=[1-9]|FAIL|"pass":false/.test(output)) throw Error(`LOCAL_CHECK_FAILED:${file}`)
  results.push({file, result:'PASS', summary:last, output_sha256:sha(output)})
}
for (const file of files) if (sha(fs.readFileSync(path.join(root,file))) !== hashes[file]) throw Error('SOURCE_CHANGED_DURING_CHECKS')
const report = { result:'PASS', head:execFileSync('git',['rev-parse','HEAD'],{cwd:root}).toString().trim(),
  source_sha256:hashes, results, remote_access:false, persistent_business_writes:false,
  observedAt:new Date().toISOString() }
const output = path.join(root,`tests/report-basic/evidence/010-balance-read-repair/local-checks-${sha(JSON.stringify(hashes)).slice(0,12)}.json`)
fs.writeFileSync(output,JSON.stringify(report,null,2)+'\n',{flag:'wx',mode:0o600})
console.log(JSON.stringify({result:report.result,results,output}))
