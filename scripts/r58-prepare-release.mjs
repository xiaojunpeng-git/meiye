import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { execFileSync } from 'node:child_process'

// Generated build trees only: preserve each verified live baseline, overlay the accepted R58 diff.
// Never build the dirty checkout, modify a development server mount, or import server source.
const root = process.cwd()
const output = '/private/tmp/mohe-r58-release-20261015'
const release = JSON.parse(fs.readFileSync(path.join(output, 'commit.json')))
const run = (tool, args, options = {}) => execFileSync(tool, args, { cwd: root, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, ...options })
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex')
const frontendFiles = release.files.filter(file => file.startsWith('前端代码/'))
const patch = run('git', ['diff', release.base, release.commit, '--', ...frontendFiles])
const before = JSON.parse(fs.readFileSync(path.join(output, 'rh-before.json')))
const other = JSON.parse(fs.readFileSync(path.join(output, '008-before.json')))
if (JSON.stringify(before.files) !== JSON.stringify(other.files)) throw Error('Customer backend baselines differ')
if (before.fronts[0].index_sha256 !== other.fronts[0].index_sha256) throw Error('Formal frontend baselines differ')
const fingerprint = dir => {
  const html = fs.readFileSync(path.join(dir, 'index.html'))
  const refs = [...html.toString().matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(m => 'assets/' + path.basename(m[1]))
  if (refs.length !== 2) throw Error('Unexpected entry assets')
  return { index_sha256: sha(html), assets: Object.fromEntries(refs.map(ref => [ref, sha(fs.readFileSync(path.join(dir, ref)))])) }
}
const sourceFiles = dir => {
  const result = {}
  const walk = current => {
    for (const item of fs.readdirSync(current, { withFileTypes: true })) {
      if (['node_modules', 'dist', '.git'].includes(item.name)) continue
      const file = path.join(current, item.name)
      if (item.isDirectory()) walk(file)
      else if (item.isFile()) result[path.relative(dir, file)] = sha(fs.readFileSync(file))
    }
  }
  walk(path.join(dir, '前端代码'))
  return result
}
const builds = {}
const sourceManifests = {}
for (const [name, index] of [['formal', 0], ['preview', 1]]) {
  const control = path.join(output, name + '-control')
  const candidate = path.join(output, name + '-candidate')
  if (fs.existsSync(control) || fs.existsSync(candidate)) throw Error('Build directory exists; inspect before retry')
  fs.mkdirSync(control)
  if (name === 'formal') {
    // This committed source was independently reproduced to the current formal entry fingerprint.
    const archive = run('git', ['archive', 'bff99741', '前端代码/cashier-v3', '前端代码/inventory-vue3', '前端代码/shared'], { encoding: null })
    run('tar', ['-xf', '-', '-C', control], { input: archive })
  } else {
    // R25's local generated preview baseline retains that entry's previously published features.
    const baseline = '/private/tmp/mohe-r25-cf-release/preview/前端代码'
    for (const part of ['cashier-v3', 'inventory-vue3', 'shared']) {
      fs.cpSync(path.join(baseline, part), path.join(control, '前端代码', part), { recursive: true,
        filter: file => !file.split(path.sep).some(p => ['node_modules', 'dist', '.git'].includes(p)) })
    }
  }
  sourceManifests[name] = { before: sourceFiles(control) }
  fs.cpSync(control, candidate, { recursive: true })
  run('git', ['apply', '--check', '-'], { cwd: candidate, input: patch })
  run('git', ['apply', '-'], { cwd: candidate, input: patch })
  sourceManifests[name].after = sourceFiles(candidate)
  const changed = Object.keys(sourceManifests[name].after).filter(p => sourceManifests[name].before[p] !== sourceManifests[name].after[p])
  if (JSON.stringify(changed.sort()) !== JSON.stringify([...frontendFiles].sort())) throw Error('Unexpected frontend integration changes')
  for (const [variant, dir] of [['control', control], ['candidate', candidate]]) {
    for (const part of ['cashier-v3', 'inventory-vue3']) {
      fs.symlinkSync(path.join(root, '前端代码', part, 'node_modules'), path.join(dir, '前端代码', part, 'node_modules'))
    }
    const app = path.join(dir, '前端代码/cashier-v3')
    const log = fs.openSync(path.join(output, `${name}-${variant}.log`), 'w')
    try { run('node', [path.join(root, '前端代码/cashier-v3/node_modules/vite/bin/vite.js'), 'build'], { cwd: app, stdio: ['ignore', log, log] }) }
    finally { fs.closeSync(log) }
    builds[`${name}-${variant}`] = { dist: path.join(app, 'dist'), ...fingerprint(path.join(app, 'dist')) }
    if (variant === 'control') {
      const expected = before.fronts[index]
      if (builds[`${name}-${variant}`].index_sha256 !== expected.index_sha256
        || JSON.stringify(builds[`${name}-${variant}`].assets) !== JSON.stringify(expected.assets)) throw Error(`${name} control build does not match live`)
    }
    console.log(`PASS ${name}-${variant}: ${builds[`${name}-${variant}`].index_sha256}`)
  }
}
const payload = path.join(output, 'payload')
const backend = {}
for (const [file, previous] of Object.entries(before.files)) {
  if (previous !== null) {
    const old = run('git', ['show', `${release.base}:后端代码/${file}`], { encoding: null })
    if (sha(old) !== previous) throw Error(`Backend baseline changed: ${file}`)
  }
  const content = run('git', ['show', `${release.commit}:后端代码/${file}`], { encoding: null })
  const dest = path.join(payload, 'backend', file)
  fs.mkdirSync(path.dirname(dest), { recursive: true })
  fs.writeFileSync(dest, content)
  backend[file] = sha(content)
}
for (const name of ['formal', 'preview']) fs.cpSync(builds[`${name}-candidate`].dist, path.join(payload, name), { recursive: true })
run('tar', ['-czf', path.join(output, 'payload.tar.gz'), '-C', payload, '.'])
fs.writeFileSync(path.join(output, 'sources.json'), JSON.stringify(sourceManifests, null, 2))
const pack = { commit: release.commit, base: release.base, patchSha256: sha(patch), builds, backend,
  sourcesSha256: sha(JSON.stringify(sourceManifests)), payloadSha256: sha(fs.readFileSync(path.join(output, 'payload.tar.gz'))) }
fs.writeFileSync(path.join(output, 'package.json'), JSON.stringify(pack, null, 2))
console.log(JSON.stringify(pack, null, 2))
