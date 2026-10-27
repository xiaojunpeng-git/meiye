import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'

// Canonical, pinned planner executes only in remote CLI memory. The PHP adapter
// is permanently READ ONLY; this runner cannot authorize a migration or deploy.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const sha = value => createHash('sha256').update(value).digest('hex')
if (process.argv.length !== 2 || fs.realpathSync(root) !== '/Users/xiaojunpeng/Desktop/魔核/美业/美容源码') throw Error('CANONICAL_NO_ARGUMENTS_REQUIRED')
const plannerPath = path.join(root, 'scripts/lib/r51-010-staff-identity-plan.php')
const source = fs.readFileSync(plannerPath)
const payload = Buffer.from(JSON.stringify({ source:source.toString('base64'), sha256:sha(source) })).toString('base64')
const script = fs.readFileSync(path.join(root, 'scripts/r51-010-staff-identity-dryrun.php'), 'utf8').replace('__R51_IDENTITY_DRYRUN__', payload)
const output = path.join(root, `tests/report-basic/evidence/010-staff-identity/identity-approved-dryrun-${sha(source).slice(0,12)}.json`)
if (fs.existsSync(output)) throw Error('RECEIPT_EXISTS')
let result
try {
  result = JSON.parse(execFileSync('ssh', ['-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
    '-o', 'ConnectTimeout=12', 'mohe-server', '/www/server/php/74/bin/php'],
  { input:script, timeout:120000, maxBuffer:4*1024*1024, stdio:['pipe','pipe','pipe'] }).toString())
} catch { throw Error('010_READ_ONLY_DRYRUN_FAILED_NO_WRITES') }
if (result.result !== 'PASS' || result.business_writes !== false || result.mapping_applied !== false
  || sha(fs.readFileSync(plannerPath)) !== sha(source)) throw Error('RESULT_OR_SOURCE_CHANGED')
fs.writeFileSync(output, JSON.stringify({ ...result, adapter_sha256:sha(script), observedAt:new Date().toISOString() }, null, 2)+'\n', {flag:'wx', mode:0o600})
console.log(JSON.stringify({ result:result.result, counts:result.plan.counts, replay:result.replay_counts, businessWrites:false, output }))
