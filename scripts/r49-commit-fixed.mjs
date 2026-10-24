import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

// A private reviewed index protects unrelated staged work. No checkout reset,
// broad add, worktree, push or production side effect occurs in this script.
const directory = 'tests/cashier-v3/evidence/R49-release'
const accepted = JSON.parse(readFileSync(`${directory}/fixed-candidate.json`))
if (!accepted.pmAccepted) throw Error('PM fixed-candidate acceptance required')
if (existsSync(`${directory}/commit-receipt.json`)) throw Error('Inspect existing commit receipt; do not duplicate')
const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }).trim()
if (git('rev-parse', 'HEAD') !== accepted.head) throw Error('Shared HEAD changed')
const index = resolve(git('rev-parse', '--git-path', 'index'))
const indexHash = () => createHash('sha256').update(readFileSync(index)).digest('hex')
const oldIndex = indexHash()
const env = { ...process.env, GIT_INDEX_FILE: resolve(git('rev-parse', '--git-dir'), 'r49-release', 'commit-index') }
const staged = (...args) => execFileSync('git', args, { env, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }).trim()
const merged = git('merge-tree', '--write-tree', accepted.head, accepted.previousAcceptedRelease)
staged('read-tree', accepted.baselineTree)
const baselinePaths = staged('diff', '--cached', '--name-only', '-z', merged).split('\0').filter(Boolean)
if (baselinePaths.some(p => !accepted.baselineArchive.some(f => f.path === p))) throw Error('Unexpected archived baseline path')
const baseline = git('commit-tree', accepted.baselineTree, '-p', accepted.head, '-p', accepted.previousAcceptedRelease,
  '-m', 'R49-BASE 保留既有R48及已上线开关退款依赖', '-m',
  '仅归档两站正在使用的既有已验收依赖。合入上一轮提交链，保留当前共享HEAD；网关与系统配置来源92a02ef1、92533c0a，逐文件现网指纹已核对。不计作R49新增。')
staged('read-tree', accepted.candidateTree)
const paths = staged('diff', '--cached', '--name-only', '-z', baseline).split('\0').filter(Boolean)
if (paths.length !== accepted.scope.length || paths.some(p => !accepted.scope.includes(p))) throw Error('Unexpected round path')
const diff = staged('diff', '--cached', '--stat', baseline)
if (staged('write-tree') !== accepted.candidateTree) throw Error('Reviewed candidate changed')
const commit = git('commit-tree', accepted.candidateTree, '-p', baseline, '-m',
  'R49-00001 普通卡逐项增额增次与唯一快照结账闭环', '-m',
  '产品经理固定候选补验通过。逐行金额/次数不减，增额同步售价；加购与结账入口前置校验；修复跨会员旧挂单引用及已成功快照重放。仅本轮18文件，默认关闭配置迁移已单独授权。')
const branch = 'release/r49-ordinary-card-20261023'
git('update-ref', 'refs/heads/' + branch, commit, '0000000000000000000000000000000000000000')
if (indexHash() !== oldIndex || git('rev-parse', 'HEAD') !== accepted.head) throw Error('Shared source/index changed concurrently; inspect created ref')
const receipt = { branch, baseline, commit, tree: accepted.candidateTree, sourceHead: accepted.head, baselinePaths,
  paths, diff, sharedHeadUnchanged: true, sharedIndexUnchanged: true, pmAccepted: accepted.pmAccepted,
  createdAt: new Date().toISOString(), state: 'Local commit only; not deployed or pushed' }
writeFileSync(`${directory}/commit-receipt.json`, JSON.stringify(receipt, null, 2) + '\n')
writeFileSync(`${directory}/remaining-workspace-status.txt`, execFileSync('git', ['status', '--short'], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }))
console.log(JSON.stringify({ baseline, commit, branch, roundFiles: paths.length, sharedIndexUnchanged: true }))
