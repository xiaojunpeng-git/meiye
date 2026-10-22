import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

// Approved fixed trees become two traceable commits on a release ref. Never
// move the shared checkout HEAD or rewrite another task's real staging index.
const directory = 'tests/cashier-v3/evidence/R48-release/'
const accepted = JSON.parse(readFileSync(directory + 'fixed-candidate.json'))
const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }).trim()
const branch = 'release/r48-coupon-allowance-20261022'
if (existsSync(directory + 'commit-receipt.json')) throw Error('Commit receipt already exists; inspect instead of duplicating')
if (git('rev-parse', 'HEAD') !== accepted.head) throw Error('Shared HEAD changed; recheck required')
const index = resolve(git('rev-parse', '--git-path', 'index'))
const indexHash = () => createHash('sha256').update(readFileSync(index)).digest('hex')
const beforeIndex = indexHash()
const environment = { ...process.env, GIT_INDEX_FILE: resolve(git('rev-parse', '--git-dir'), 'r48-candidate', 'commit-index') }
const staged = (...args) => execFileSync('git', args, { env: environment, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }).trim()
const review = (tree, parent, allowed) => {
  staged('read-tree', tree)
  const paths = staged('diff', '--cached', '--name-only', '-z', parent).split('\0').filter(Boolean)
  if (paths.some(path => !allowed.includes(path))) throw Error('Unapproved staged path')
  const diff = staged('diff', '--cached', '--stat', parent)
  if (staged('write-tree') !== tree) throw Error('Reviewed tree moved')
  return { paths, diff }
}
const baselineReview = review(accepted.baselineTree, accepted.head, accepted.changes.map(f => f.path))
const baseline = git('commit-tree', accepted.baselineTree, '-p', accepted.head, '-m',
  'R48-BASE 独立归档两站已上线收银与会员依赖', '-m',
  '产品经理2026-10-22明确确认补验及独立归档。来源与逐文件指纹见R48-release固定候选；保留R47、M011及已上线会员详情功能，不属于R48新增业务。')
const roundReview = review(accepted.candidateTree, baseline, accepted.scope)
const commit = git('commit-tree', accepted.candidateTree, '-p', baseline, '-m',
  'R48-00001 多次抵用券整元额度与结账作废闭环', '-m',
  '产品经理确认固定候选0eabd85d；仅本轮21个文件。券账户独立于钱包及现金，分次抵用、原单作废守恒恢复；新增两表迁移已单独授权。')
git('update-ref', 'refs/heads/' + branch, commit, '0000000000000000000000000000000000000000')
if (indexHash() !== beforeIndex || git('rev-parse', 'HEAD') !== accepted.head) throw Error('Shared index/HEAD changed concurrently; release ref created, inspect before proceeding')
const receipt = { branch, baseline, baselineTree: accepted.baselineTree, commit, tree: accepted.candidateTree,
  baselineReview, roundReview, sourceHead: accepted.head, sharedHeadUnchanged: true, sharedIndexUnchanged: true,
  pmAccepted: '2026-10-22 explicit fixed-candidate supplementary acceptance; baseline archive and ordered release authorized',
  state: 'Committed locally on isolated release ref; not pushed or deployed', createdAt: new Date().toISOString() }
writeFileSync(directory + 'commit-receipt.json', JSON.stringify(receipt, null, 2) + '\n')
writeFileSync(directory + 'remaining-workspace-status.txt', execFileSync('git', ['status', '--short'], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }))
console.log(JSON.stringify({ branch, baseline, commit, baselineFiles: baselineReview.paths.length, roundFiles: roundReview.paths.length, sharedIndexUnchanged: true }))
