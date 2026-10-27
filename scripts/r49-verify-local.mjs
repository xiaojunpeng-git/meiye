import { spawnSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'

// Run only local, non-production checks; preserve complete outputs as acceptance evidence.
const commands = [
  ['node', ['tests/cashier-v3/js/r49-ordinary-card-sale-adjustment.mjs']],
  ['node', ['tests/cashier-v3/js/r49-final-checkout-snapshot.mjs']],
  ['php', ['tests/cashier-v3/php/r49-ordinary-card-sale-adjustment.php']],
  ['node', ['tests/cashier-v3/js/entitlement-experience-checkout-snapshot.mjs']],
  ['node', ['tests/cashier-v3/js/r47-sales-debt-balance-contract.mjs']],
  ['node', ['tests/cashier-v3/js/r48-coupon-allowance.mjs']],
  ['php', ['-d', 'error_reporting=8191', 'tests/cashier-v3/php/card-purchase-issuance-contract.php']],
  ['php', ['-d', 'error_reporting=8191', 'tests/cashier-v3/php/r37-custom-card-entry-switch.php']],
  ['php', ['-d', 'error_reporting=8191', 'tests/cashier-v3/php/hang-sale-only-resume-snapshot-contract.php']],
  ['php', ['-d', 'error_reporting=8191', 'tests/cashier-v3/php/hang-draft-save-contract.php']]
]
const results = []
for (const [command, args] of commands) {
  const result = spawnSync(command, args, { encoding: 'utf8' })
  results.push({ command: [command, ...args], status: result.status, output: result.stdout + result.stderr })
  console.log(`${result.status === 0 ? 'PASS' : 'FAIL'} ${args.at(-1)}`)
}
const integration = spawnSync('docker', ['exec', '-i', '-e', 'R49_LOCAL_ONLY=1', '-e', 'R49_CONFIG_FIXTURE=enabled', 'mohe-cashier-app', 'php'], {
  input: readFileSync('tests/cashier-v3/php/r49-ordinary-card-local.php'), encoding: 'utf8'
})
results.push({ command: 'local transactional issuance and void', status: integration.status, output: integration.stdout + integration.stderr })
console.log(`${integration.status === 0 ? 'PASS' : 'FAIL'} local issuance and void`)
const cross = spawnSync('docker', ['exec', '-i', '-e', 'R49_LOCAL_ONLY=1', '-e', 'R49_CONFIG_FIXTURE=enabled', 'mohe-cashier-app', 'php'], {
  input: readFileSync('tests/cashier-v3/php/r49-checkout-cross-local.php'), encoding: 'utf8'
})
results.push({ command: 'real local gateway cross-checkouts and rollback', status: cross.status, output: cross.stdout + cross.stderr })
console.log(`${cross.status === 0 ? 'PASS' : 'FAIL'} real gateway cross-checkouts`)
const before = JSON.parse(readFileSync('tests/cashier-v3/evidence/R49-before.json'))
before.files.push(...JSON.parse(readFileSync('tests/cashier-v3/evidence/R49-hang-before.json')).files)
for (const path of [...before.files.map(file => file.path).filter(path => path.endsWith('.php')),
  '后端代码/app/services/cashier/v3/card/CashierV3OrdinaryCardSaleAdjustment.php']) {
  const result = spawnSync('docker', ['exec', '-i', 'mohe-cashier-app', 'php', '-l'], { input: readFileSync(path), encoding: 'utf8' })
  results.push({ command: `PHP 7.4 lint ${path}`, status: result.status, output: result.stdout + result.stderr })
}
writeFileSync('tests/cashier-v3/evidence/R49-local-verification.json', JSON.stringify(results, null, 2) + '\n')
process.exit(results.every(result => result.status === 0) ? 0 : 1)
