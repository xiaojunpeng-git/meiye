import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs'
import { createHash } from 'node:crypto'

// Ordered per-instance deployment. Every mutation requires a fixed accepted
// commit, an unchanged live predecessor, an independent backup and guarded rollback.
const evidence = 'tests/cashier-v3/evidence/R49-release'
const [mode, instance] = process.argv.slice(2)
const run = (bin, args, options = {}) => execFileSync(bin, args, { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, ...options })
const git = (...args) => run('git', args).trim()
const sha = value => createHash('sha256').update(value).digest('hex')
const q = v => "'" + String(v).replaceAll("'", "'\\''") + "'"
const checked = (file, hash) => `test "$(sha256sum ${q(file)} | cut -d' ' -f1)" = '${hash}'`
// Some platforms retain 10,000+ immutable old chunks. One checksum process
// validates the same full set without spawning three subprocesses per asset.
const batchChecked = entries => {
  if (entries.some(([file, hash]) => /[\r\n\\]/.test(file) || !/^[a-f0-9]{64}$/.test(hash))) throw Error('Invalid checksum manifest')
  return "sha256sum -c --quiet <<'R49_CHECKSUM_MANIFEST'\n"
    + entries.map(([file, hash]) => hash + '  ' + file).join('\n') + '\nR49_CHECKSUM_MANIFEST\n'
}
const accepted = JSON.parse(readFileSync(`${evidence}/fixed-candidate.json`))
const receipt = JSON.parse(readFileSync(`${evidence}/commit-receipt.json`))
const commit = receipt.commit
if (!accepted.pmAccepted || git('rev-parse', commit + '^{tree}') !== accepted.candidateTree) throw Error('Fixed acceptance/commit mismatch')
const build = JSON.parse(readFileSync(`${evidence}/candidate-cashier-build.json`))
if (build.tree !== accepted.candidateTree) throw Error('Build is not the accepted tree')
const paths = accepted.scope.filter(p => p.startsWith('后端代码/app/'))
const sql = accepted.scope.find(p => p.endsWith('/01-正式升级.sql'))
const helper = 'scripts/r49-config-release.php'
const fingerprints = Object.fromEntries([...paths, sql].map(p => [p, sha(run('git', ['show', commit + ':' + p], { encoding: null }))]))
const fixed = JSON.parse(readFileSync('tests/cashier-v3/evidence/R49-fixed-files.json'))
for (const f of fixed.files) if (sha(readFileSync(f.path)) !== f.sha256) throw Error('Shared accepted source changed: ' + f.path)
if (git('rev-parse', 'HEAD') !== accepted.head) throw Error('Shared HEAD changed after acceptance')
if (mode === 'prepare') {
  const directory = mkdtempSync('/private/tmp/mohe-r49-payload-')
  const backendTar = directory + '/backend.tar', cashierTar = directory + '/cashier.tar.gz'
  writeFileSync(backendTar, run('git', ['archive', commit, ...paths, sql], { encoding: null }))
  for (const [p, hash] of Object.entries(build.assets)) if (sha(readFileSync(build.output + '/' + p)) !== hash) throw Error('Built asset changed')
  run('tar', ['-czf', cashierTar, '-C', build.output, ...Object.keys(build.assets)])
  const manifest = { commit, tree: accepted.candidateTree, directory, paths, sql, fingerprints,
    assets: build.assets, backendTar, cashierTar, backendSha: sha(readFileSync(backendTar)), cashierSha: sha(readFileSync(cashierTar)),
    helperSha: sha(readFileSync(helper)), platformStatic: 'UNCHANGED' }
  writeFileSync(directory + '/manifest.json', JSON.stringify(manifest, null, 2) + '\n')
  writeFileSync(`${evidence}/payload.json`, JSON.stringify({ directory, manifest: directory + '/manifest.json' }, null, 2) + '\n')
  console.log(JSON.stringify({ directory, commit, backendFiles: paths.length, assets: Object.keys(build.assets).length })); process.exit(0)
}
const sites = { rh: { host: '47.96.103.107', domain: 'rh.cc3798.com', restart: '/etc/init.d/ruihao_swoole restart', health: "ss -lnt | grep -q ':20800 '" },
  '008': { host: '121.41.109.138', domain: '008.cc3798.com', restart: 'systemctl restart mohe-008-swoole.service', health: 'systemctl is-active --quiet mohe-008-swoole.service' } }
if (!sites[instance] || !['preflight', 'backup', 'migrate', 'switch', 'verify', 'rollback'].includes(mode)) throw Error('Invalid instance/phase')
const site = sites[instance], base = '/www/wwwroot/' + site.domain
const directory = JSON.parse(readFileSync(`${evidence}/payload.json`)).directory
const manifest = JSON.parse(readFileSync(directory + '/manifest.json'))
if (manifest.commit !== commit || manifest.helperSha !== sha(readFileSync(helper))
  || sha(readFileSync(manifest.backendTar)) !== manifest.backendSha || sha(readFileSync(manifest.cashierTar)) !== manifest.cashierSha) throw Error('Payload changed')
const live = JSON.parse(readFileSync(`${evidence}/${instance}-before.json`))
const backup = '/www/backups/' + site.domain + '/20261023-r49-' + commit.slice(0, 8), stage = backup + '/stage'
const remoteHelper = ['preflight', 'backup'].includes(mode) ? '/tmp/r49-' + commit.slice(0, 8) + '.php' : backup + '/release.php'
const ssh = ['-i', process.env.HOME + '/.ssh/aliyun-ecs', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12']
const remote = body => {
  const input = Buffer.from('set -euo pipefail\numask 077\n' + body)
  run('bash', ['-n'], { input })
  return run('ssh', [...ssh, 'root@' + site.host, 'bash -s'], { input })
}
// Use the existing hosts' SCP-compatible path; every uploaded byte is verified.
const upload = (local, destination) => run('scp', ['-O', ...ssh, local, 'root@' + site.host + ':' + destination])
const save = (phase, data) => { writeFileSync(`${evidence}/${instance}-${phase}.json`, JSON.stringify(data, null, 2) + '\n'); console.log(JSON.stringify(data)) }
const schema = phase => JSON.parse(remote(checked(remoteHelper, manifest.helperSha) + `\nphp ${q(remoteHelper)} ${q(instance)} ${q(phase)} ${q(commit)}\n`))
const sourceGuard = after => {
  const entries = [], absent = []
  for (const [p, old] of Object.entries(live.files)) {
    const full = '后端代码/' + p, expected = after && paths.includes(full) ? fingerprints[full] : old
    if (expected) entries.push([base + '/' + p, expected]); else absent.push(`test ! -e ${q(base + '/' + p)}`)
  }
  return batchChecked(entries) + absent.join('\n')
}
const fronts = Object.values(live.front)
const frontGuard = after => fronts.map(front => Object.entries(after ? manifest.assets : { 'index.html': front.html, ...front.assets })
  .map(([p, hash]) => checked(front.directory + '/' + p, hash)).join('\n')).join('\n')
const platformGuard = batchChecked(Object.entries({ 'system.html': live.platform.html, ...live.platform.assets })
  .map(([p, hash]) => [base + '/public/' + p, hash]))
const guard = after => site.health + '\n' + sourceGuard(after) + '\n' + frontGuard(after) + '\n' + platformGuard + '\n'
if (mode === 'preflight') {
  remote(guard(false) + `test ! -e ${q(backup)}\n`)
  upload(helper, remoteHelper)
  remote(checked(remoteHelper, manifest.helperSha) + `\nphp -l ${q(remoteHelper)} >/dev/null\n`)
  save('preflight', { instance, commit, backup, business: schema('probe'), result: 'PASS' }); process.exit(0)
}
const preflight = JSON.parse(readFileSync(`${evidence}/${instance}-preflight.json`))
if (preflight.commit !== commit || preflight.backup !== backup) throw Error('Preflight mismatch')
if (mode === 'backup') {
  if (instance === '008' && (JSON.parse(readFileSync(`${evidence}/rh-verified.json`)).result !== 'PASS'
    || git('ls-remote', 'origin', 'refs/heads/' + receipt.branch).split(/\s+/)[0] !== commit)) throw Error('RH verification and push must complete first')
  remote(guard(false)); save('database-backup', schema('backup')); upload(helper, backup + '/release.php')
  const old = paths.filter(p => live.files[p.slice('后端代码/'.length)])
  remote(`mkdir -p ${q(stage)}\ntar -czf ${q(backup + '/backend-before.tar.gz')} -C ${q(base)} ${old.map(p => q(p.slice('后端代码/'.length))).join(' ')}\ngzip -t ${q(backup + '/backend-before.tar.gz')}\n`
    + fronts.map((front, i) => `cp -p ${q(front.directory + '/index.html')} ${q(backup + '/front-' + i + '.html')}\n`).join(''))
  upload(manifest.backendTar, backup + '/backend.tar'); upload(manifest.cashierTar, backup + '/cashier.tar.gz')
  remote(checked(backup + '/backend.tar', manifest.backendSha) + '\n' + checked(backup + '/cashier.tar.gz', manifest.cashierSha)
    + `\ntar -xf ${q(backup + '/backend.tar')} -C ${q(stage)}\nmkdir -p ${q(stage + '/cashier')}\ntar -xzf ${q(backup + '/cashier.tar.gz')} -C ${q(stage + '/cashier')}\ncp ${q(stage + '/' + sql)} ${q(stage + '/schema.sql')}\n`
    + paths.map(p => checked(stage + '/' + p, fingerprints[p]) + `\nphp -l ${q(stage + '/' + p)} >/dev/null\n`).join('')
    + Object.entries(manifest.assets).map(([p, hash]) => checked(stage + '/cashier/' + p, hash) + '\n').join(''))
  save('backup', { instance, commit, backup, result: 'PASS', backendSha: manifest.backendSha, cashierSha: manifest.cashierSha }); process.exit(0)
}
if (mode === 'migrate') { remote(guard(false)); save('migration', schema('migrate')); process.exit(0) }
const canRollback = `php ${q(remoteHelper)} ${q(instance)} rollback-guard ${q(commit)}`
const owned = paths.map(p => {
  const file = base + '/' + p.slice('后端代码/'.length), old = live.files[p.slice('后端代码/'.length)]
  return `( ${checked(file, fingerprints[p])} || ${old ? checked(file, old) : `test ! -e ${q(file)}`} )`
}).concat(fronts.map(front => `( ${checked(front.directory + '/index.html', front.html)} || ${checked(front.directory + '/index.html', manifest.assets['index.html'])} )`)).join(' &&\n')
const rollback = `tar -xzf ${q(backup + '/backend-before.tar.gz')} -C ${q(base)}\n`
  + paths.filter(p => !live.files[p.slice('后端代码/'.length)]).map(p => `rm -f ${q(base + '/' + p.slice('后端代码/'.length))}\n`).join('')
  + fronts.map((front, i) => `install -o www -g www -m 644 ${q(backup + '/front-' + i + '.html')} ${q(front.directory + '/index.html.r49-rollback')}\nmv -f ${q(front.directory + '/index.html.r49-rollback')} ${q(front.directory + '/index.html')}\n`).join('')
  + site.restart + '\n'
if (mode === 'rollback') { remote(canRollback + '\n' + owned + '\n' + rollback); console.log('R49_ROLLBACK_ATTEMPTED'); process.exit(0) }
if (mode === 'switch') {
  schema('verify')
  const trap = `recover_r49() { set +e; if ${canRollback} && ${owned}; then ${rollback}\necho R49_ROLLBACK_ATTEMPTED >&2; else echo R49_NEW_FACTS_OR_CONCURRENT_CHANGE_REQUIRES_RECONCILIATION >&2; fi; }\ntrap recover_r49 ERR\n`
  // Install newly introduced leaf classes before files that may autoload them.
  // Existing workers remain backward-compatible until the controlled restart.
  const ordered = [...paths].sort((a, b) => Number(Boolean(live.files[a.slice('后端代码/'.length)]))
    - Number(Boolean(live.files[b.slice('后端代码/'.length)])))
  const backendWrites = ordered.map(p => { const file = base + '/' + p.slice('后端代码/'.length)
    return `install -D -o www -g www -m 644 ${q(stage + '/' + p)} ${q(file + '.r49-new')}\nmv -f ${q(file + '.r49-new')} ${q(file)}\n`
  }).join('')
  const frontWrites = `find ${q(stage + '/cashier')} -type d -exec chmod 755 {} +\nfind ${q(stage + '/cashier')} -type f -exec chmod 644 {} +\n`
    + fronts.map(front => `cp -an ${q(stage + '/cashier/assets/.')} ${q(front.directory + '/assets/')}\n`
      + Object.entries(manifest.assets).filter(([p]) => p !== 'index.html').map(([p, hash]) => checked(front.directory + '/' + p, hash)
        + `\nsu -s /bin/sh -c ${q('test -r ' + q(front.directory + '/' + p))} www\n`).join('')
      + `install -o www -g www -m 644 ${q(stage + '/cashier/index.html')} ${q(front.directory + '/index.html.r49-new')}\nmv -f ${q(front.directory + '/index.html.r49-new')} ${q(front.directory + '/index.html')}\n`).join('')
  const health = `for attempt in $(seq 1 30); do if ${site.health}; then break; fi; sleep 1; done\n${site.health}\n`
  remote(guard(false) + trap + backendWrites + site.restart + '\n' + health + frontWrites + guard(true) + 'trap - ERR\necho R49_SWITCH_PASS\n')
  save('switched', { instance, commit, backup, result: 'PASS' }); process.exit(0)
}
remote(guard(true))
const migration = schema('verify'), business = schema('probe')
if (JSON.stringify(preflight.business.checks) !== JSON.stringify(business.checks)
  || JSON.stringify(preflight.business.catalog) !== JSON.stringify(business.catalog)) throw Error('Read-only business regression differs; stop before push/next site')
const publicChecks = []
// Hash curl's transparently decompressed bytes against the original artifact.
// This keeps full byte verification while avoiding unnecessary multi-MB transfers.
const curl = ['-fsS', '--http1.1', '--compressed', '--connect-timeout', '20', '--max-time', '90']
// Retry each download into a NEW buffer. Curl stdout retries may concatenate a
// partial old response with a fresh response, which must never pass as one file.
const fetch = (url, options = {}, args = curl) => {
  for (let attempt = 0; attempt < 3; attempt++) {
    try { return run('curl', [...args, url], options) }
    catch (error) { if (attempt === 2) throw error }
  }
}
const fetchAsset = (url, path) => {
  const length = readFileSync(build.output + '/' + path).length
  if (!path.endsWith('.png') || length <= 131072) return fetch(url, { encoding: null })
  const chunks = []
  for (let start = 0; start < length; start += 131072) {
    const end = Math.min(length - 1, start + 131071)
    const bytes = fetch(url, { encoding: null }, ['-fsS', '--http1.1', '--range', start + '-' + end,
      '--header', 'Accept-Encoding: identity', '--connect-timeout', '20', '--max-time', '45'])
    if (bytes.length !== end - start + 1) throw Error('Incomplete or ignored HTTP range: ' + path)
    chunks.push(bytes)
  }
  // The caller still verifies the SHA-256 of every byte in the reconstructed file.
  return Buffer.concat(chunks)
}
for (const [i, front] of fronts.entries()) {
  const prefix = i ? '/preview-8080' : '', url = 'https://' + site.domain + prefix + '/view_cashier_v3/'
  const html = fetch(url + '?release=r49-' + commit.slice(0, 8))
  const normalized = prefix ? html.replaceAll('/preview-8080/view_cashier_v3/', '/view_cashier_v3/') : html
  if (sha(Buffer.from(normalized)) !== manifest.assets['index.html']) throw Error('Public entry mismatch: ' + url)
  for (const [p, hash] of Object.entries(manifest.assets).filter(([p]) => p !== 'index.html')) {
    if (sha(fetchAsset(url + p, p)) !== hash) throw Error('Public resource mismatch: ' + p)
  }
  publicChecks.push({ url, html: manifest.assets['index.html'], result: 'PASS' })
}
if (sha(fetch('https://' + site.domain + '/system.html', { encoding: null })) !== live.platform.html) throw Error('Platform static entry changed unexpectedly')
save('verified', { instance, commit, backup, result: 'PASS', migration, business, publicChecks,
  platformStatic: 'Unchanged and fingerprint-verified', verifiedAt: new Date().toISOString(),
  limitations: 'Read-only production checks only; no production checkout/refund/void submitted' })
