import { execFileSync, spawnSync } from 'node:child_process'
import { readFileSync, writeFileSync, existsSync, chmodSync, readdirSync } from 'node:fs'

// Test the exact immutable frontend already used for the candidate build.
// Add only same-tree PHP test dependencies; no exported business file is edited.
const evidence = 'tests/cashier-v3/evidence/R49-release'
const build = JSON.parse(readFileSync(`${evidence}/candidate-cashier-build.json`))
const input = build.input, tree = build.tree, dependency = '后端代码/app/services/cashier/v3'
if (!existsSync(input + '/' + dependency)) {
  chmodSync(input, 0o755)
  const archive = execFileSync('git', ['archive', tree, dependency], { maxBuffer: 32 * 1024 * 1024 })
  execFileSync('tar', ['-xf', '-', '-C', input], { input: archive })
  const freeze = path => {
    for (const item of readdirSync(path, { withFileTypes: true })) {
      const child = path + '/' + item.name
      if (item.isDirectory()) freeze(child); else chmodSync(child, 0o444)
    }
    chmodSync(path, 0o555)
  }
  freeze(input + '/后端代码'); chmodSync(input, 0o555)
}
const files = execFileSync('git', ['ls-tree', '-rz', '--name-only', tree, '--', dependency], { encoding: 'utf8' }).split('\0').filter(Boolean)
for (const path of files) {
  if (!readFileSync(input + '/' + path).equals(execFileSync('git', ['show', tree + ':' + path], { maxBuffer: 4 * 1024 * 1024 }))) throw Error('Dependency drift')
}
const results = []
for (const [program, file] of [
  ['node', 'tests/cashier-v3/js/r49-ordinary-card-sale-adjustment.mjs'],
  ['node', 'tests/cashier-v3/js/r49-final-checkout-snapshot.mjs'],
  ['node', 'tests/cashier-v3/js/r47-sales-debt-balance-contract.mjs'],
  ['node', 'tests/cashier-v3/js/r48-coupon-allowance.mjs'],
  ['node', 'tests/cashier-v3/js/entitlement-experience-checkout-snapshot.mjs'],
  ['php', 'tests/cashier-v3/php/r49-ordinary-card-sale-adjustment.php']
]) {
  const r = spawnSync(program, [file], { cwd: input, encoding: 'utf8' })
  results.push({ file, status: r.status, output: r.stdout + r.stderr })
  console.log((r.status === 0 ? 'PASS ' : 'FAIL ') + file + '\n' + (r.stdout + r.stderr).slice(-800))
}
writeFileSync(`${evidence}/isolated-frontend.json`, JSON.stringify({ candidateTree: tree, results }, null, 2) + '\n')
if (results.some(r => r.status !== 0)) process.exitCode = 1
