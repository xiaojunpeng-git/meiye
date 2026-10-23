import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'

// Narrow local-only aggregate verification. The database fixture guards its
// target and rolls back. No production access or historical backfill occurs.
const run = (name, args, options = {}) => execFileSync(name, args,
  { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024, ...options })
const backend = '后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php'
const frontend = '前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue'
const paths = [backend, frontend, 'tests/cashier-v3/php/r50-member-recharge-total-local.php',
  'tests/cashier-v3/js/r50-member-recharge-total.mjs']
const fingerprints = () => Object.fromEntries(paths.map(path => [path,
  createHash('sha256').update(readFileSync(path)).digest('hex')]))
const before = fingerprints()
const php = run('docker', ['exec', '-i', '-e', 'R50_LOCAL_ONLY=1', '-w', '/var/www/html', 'mohe-cashier-app', 'php'],
  { input: readFileSync(paths[2]) })
const js = run(process.execPath, [paths[3]])
const lint = run('docker', ['exec', '-w', '/var/www/html', 'mohe-cashier-app', 'php', '-l', backend.slice('后端代码/'.length)])
if (JSON.stringify(before) !== JSON.stringify(fingerprints())) throw Error('Source changed during verification')
writeFileSync('tests/cashier-v3/evidence/R50-recharge-total-tests.json', JSON.stringify({
  testedAt: new Date().toISOString(), fingerprints: before, status: 'PASS', php, js, lint,
  authority: 'Successful recharge and recharge-debt principal balance facts, including signed reversals',
  boundary: 'Local synthetic transaction fully rolled back; no production access or historical changes',
  visualQA: 'Browser interaction tool unavailable; no real-screen verification claimed',
}, null, 2) + '\n')
console.log(php + js + lint)
