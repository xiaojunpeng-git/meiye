import { execFileSync, spawnSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { createHash } from 'node:crypto'

// A private index computes ONLY this round's delta against captured dirty blobs.
// It never changes the shared checkout index or includes other tasks' pending edits.
const before = JSON.parse(readFileSync('tests/cashier-v3/evidence/R49-before.json'))
before.files.push(...JSON.parse(readFileSync('tests/cashier-v3/evidence/R49-hang-before.json')).files)
const added = [
  '后端代码/app/services/cashier/v3/card/CashierV3OrdinaryCardSaleAdjustment.php',
  '后端代码/database/upgrades/2026-10-22-普通卡销售可修改/01-正式升级.sql',
  '前端代码/cashier-v3/src/services/ordinaryCardSaleAdjustment.js',
  'tests/cashier-v3/php/r49-ordinary-card-sale-adjustment.php',
  'tests/cashier-v3/php/r49-ordinary-card-local.php',
  'tests/cashier-v3/php/r49-checkout-cross-local.php',
  'tests/cashier-v3/js/r49-ordinary-card-sale-adjustment.mjs',
  'tests/cashier-v3/js/r49-final-checkout-snapshot.mjs',
  'scripts/r49-capture-local-baseline.mjs', 'scripts/r49-verify-local.mjs', 'scripts/r49-freeze-local.mjs'
]
const directory = mkdtempSync(join(tmpdir(), 'r49-index-'))
const env = { ...process.env, GIT_INDEX_FILE: join(directory, 'index') }
const git = (...args) => execFileSync('git', args, { env, encoding: 'utf8', maxBuffer: 5 * 1024 * 1024 })
try {
  git('read-tree', before.head)
  for (const file of before.files) git('update-index', '--add', '--cacheinfo', `100644,${file.blob},${file.path}`)
  let patch = git('diff', '--no-ext-diff', '--', ...before.files.map(file => file.path))
  for (const file of added) {
    const diff = spawnSync('git', ['diff', '--no-index', '--', '/dev/null', file], { encoding: 'utf8' })
    if (diff.status !== 1) throw new Error(`Cannot freeze added file ${file}`)
    patch += diff.stdout
  }
  const sha = value => createHash('sha256').update(value).digest('hex')
  writeFileSync('tests/cashier-v3/evidence/R49-fixed.patch', patch)
  const paths = [...before.files.map(file => file.path), ...added]
  writeFileSync('tests/cashier-v3/evidence/R49-fixed-files.json', JSON.stringify({
    baselineHead: before.head, currentHeadAtFreeze: git('rev-parse', 'HEAD').trim(),
    patchSha256: sha(patch), remote: '不适用：尚未授权发布',
    priorIndexPreserved: before.files.map(file => ({ path: file.path,
      before: file.indexBlob, now: execFileSync('git', ['rev-parse', `:${file.path}`], { encoding: 'utf8' }).trim() })),
    files: paths.map(path => ({ path, sha256: sha(readFileSync(path)), blob: git('hash-object', '-w', '--', path).trim() }))
  }, null, 2) + '\n')
  console.log(`R49 fixed patch ${sha(patch)} (${paths.length} files)`)
} finally { rmSync(directory, { recursive: true, force: true }) }
