import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'

// Reconstruct deployment fingerprints only from canonical accepted Git sources.
// Server hashes are comparison targets, never input source text or replacement files.
const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 })
const sha = value => createHash('sha256').update(value).digest('hex')
const path = '后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php'
const commits = git('log', '--all', '--full-history', '--format=%H', '--', path).trim().split('\n')
const patches = ['30582fd009384a1a8aa88d7954d9d2ae06015bd4', '92a02ef16c4802a05e170c32d44c45e208de9d8c', 'e2c36cc036433cf3abbdd1f7240f8fae66f727f7']
  .map(commit => ({ commit, diff: git('diff', commit + '^', commit, '--', path) }))

/** Exact context-only application: ambiguities reject instead of guessing a merge. */
function apply(source, patch) {
  for (const hunk of patch.split(/^@@[^\n]*\n/m).slice(1)) {
    const lines = hunk.split('\n').filter(line => /^[ +\-]/.test(line))
    const old = lines.filter(l => l[0] !== '+').map(l => l.slice(1)).join('\n') + '\n'
    const next = lines.filter(l => l[0] !== '-').map(l => l.slice(1)).join('\n') + '\n'
    const position = source.indexOf(old)
    if (position < 0 || source.indexOf(old, position + 1) >= 0) return null
    source = source.slice(0, position) + next + source.slice(position + old.length)
  }
  return source
}

const targets = Object.fromEntries(['rh', '008'].map(site => [site,
  JSON.parse(readFileSync('tests/cashier-v3/evidence/R48-release/' + site + '-before.json')).files[path.slice(5)]]))
const matches = []
for (const commit of commits) {
  let source
  try { source = git('show', commit + ':' + path) } catch { continue }
  // Existing deployments composed independently accepted fixes on different bases.
  const sequences = [[]];
  const extend = (sequence, remaining) => { for (const p of remaining) { const next = [...sequence, p]; sequences.push(next); extend(next, remaining.filter(x => x !== p)) } };
  extend([], patches);
  for (const sequence of sequences) {
    let composed = source
    for (const patch of sequence) { composed = apply(composed, patch.diff); if (composed === null) break }
    if (composed === null) continue
    for (const [site, expected] of Object.entries(targets)) {
      if (sha(composed) !== expected) continue
      const blob = execFileSync('git', ['hash-object', '-w', '--stdin'], { input: composed, encoding: 'utf8' }).trim()
      const match = { site, path, sourceCommit: commit, patches: sequence.map(p => p.commit), blob, sha256: expected }
      matches.push(match); console.log(JSON.stringify(match))
    }
  }
}
writeFileSync('tests/cashier-v3/evidence/R48-release/member-source-provenance.json', JSON.stringify({ targets, matches }, null, 2))
if (Object.keys(targets).some(site => !matches.some(m => m.site === site))) process.exitCode = 1
