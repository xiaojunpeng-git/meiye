import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { execFileSync } from 'node:child_process'

// R25 deploys only the committed C/F change onto each customer's verified live base.
// Every instance owns its own backup, service restart and public-asset verification.
const [mode, instance] = process.argv.slice(2)
const root = process.cwd()
const output = process.env.R25_OUTPUT || '/private/tmp/mohe-r25-cf-release'
const commit = execFileSync('git', ['rev-parse', 'refs/heads/release/r25-cf-pre-sale-20261015'], { cwd: root, encoding: 'utf8' }).trim()
const manifest = JSON.parse(fs.readFileSync(path.join(output, 'manifest.json'), 'utf8'))
const r24 = JSON.parse(fs.readFileSync('/private/tmp/mohe-r25-r24-repro/manifest.json', 'utf8'))
const files = [
  'app/services/report/StoreUnifiedReportPhaseFourServices.php',
  'app/controller/admin/v1/report/UnifiedReport.php'
]
const expectedBackend = {
  [files[0]]: '03a7f5eb9b1963418051837ca9c5f6664850be2e423f82645a634544dedb3f93',
  [files[1]]: 'd4818f18a1b66d02686527562b20692c76977d284edd8e4685cb6a946305f896'
}
const targets = {
  rh: { host: 'rh-server', site: '/www/wwwroot/rh.cc3798.com', database: 'ruihao', port: '3306', migration: 235,
    restart: '/etc/init.d/ruihao_swoole restart', health: "ss -lnt | grep -q ':20800 '" },
  '008': { host: 'mohe-server', site: '/www/wwwroot/008.cc3798.com', database: 'mohe_008', port: '3307', migration: 28,
    restart: 'systemctl restart mohe-008-swoole.service', health: 'systemctl is-active --quiet mohe-008-swoole.service' }
}
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex')
const run = (file, args, options = {}) => execFileSync(file, args, { cwd: root, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, ...options })
const save = (name, data) => fs.writeFileSync(path.join(output, `${name}.json`), JSON.stringify(data, null, 2) + '\n')
const load = name => JSON.parse(fs.readFileSync(path.join(output, `${name}.json`), 'utf8'))
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b)
const ssh = (host, script) => run('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', host, 'bash -s'], { input: script })
const target = targets[instance]
if (!['prepare', 'preflight', 'deploy', 'verify'].includes(mode)) throw Error('Choose prepare, preflight, deploy or verify')
if (run('git', ['rev-parse', commit]).trim() !== commit) throw Error('R25 commit unavailable')
if (sha(fs.readFileSync(path.join(output, 'backend/后端代码', files[0]))) !== manifest.backend.service
  || sha(fs.readFileSync(path.join(output, 'backend/后端代码', files[1]))) !== manifest.backend.controller) throw Error('Backend candidate changed')

if (mode === 'prepare') {
  const payload = path.join(output, 'payload')
  if (fs.existsSync(payload)) throw Error('Payload already exists')
  for (const file of files) {
    const dest = path.join(payload, 'backend', file)
    fs.mkdirSync(path.dirname(dest), { recursive: true })
    fs.copyFileSync(path.join(output, 'backend/后端代码', file), dest)
  }
  for (const name of ['formal', 'preview']) {
    const dist = path.join(output, name, '前端代码/cashier-v3/dist')
    fs.cpSync(dist, path.join(payload, name), { recursive: true })
  }
  const archive = path.join(output, 'payload.tar.gz')
  run('tar', ['-czf', archive, '-C', payload, '.'])
  save('payload-manifest', { commit, sha256: sha(fs.readFileSync(archive)), backend: manifest.backend, builds: manifest.builds })
  console.log(JSON.stringify(load('payload-manifest'), null, 2))
  process.exit(0)
}

if (!target) throw Error('Explicit rh or 008 instance required')
const payload = load('payload-manifest')
if (payload.commit !== commit || payload.sha256 !== sha(fs.readFileSync(path.join(output, 'payload.tar.gz')))) throw Error('Payload changed')
const fronts = instance === 'rh' ? [
  { name: 'formal', path: target.site + '/public/view_cashier_v3' },
  { name: 'preview', path: '/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3' }
] : [{ name: 'formal', path: target.site + '/public/view_cashier_v3' }]

function probe() {
  const php = fs.readFileSync(path.join(root, 'scripts/r24-usage-release-probe.php'))
  const result = JSON.parse(run('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', target.host,
    `php -r 'eval("?>" . stream_get_contents(STDIN));' -- ${instance}`], { input: php }))
  const lines = run('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', target.host,
    `sha256sum ${files.map(file => `'${target.site}/${file}'`).join(' ')}`]).trim().split('\n')
  result.backend = Object.fromEntries(lines.map((line, index) => [files[index], line.trim().split(/\s+/)[0]]))
  return result
}

function assertBefore(before) {
  if (before.instance !== instance || before.database !== target.database || before.port !== target.port
    || Number(before.migration?.id) !== target.migration
    || before.migration?.upgrade_key !== '20261015-002-product-category-statistics-switch') throw Error('Wrong instance or migration')
  if (!same(before.backend, expectedBackend) || before.fronts.length !== fronts.length) throw Error('Backend or frontend count changed')
  for (let i = 0; i < fronts.length; i++) {
    const f = before.fronts[i], expected = r24.builds[fronts[i].name]
    if (f.path !== fronts[i].path || f.index_sha256 !== expected.index || !same(f.assets, expected.assets)) {
      throw Error(`${instance} ${fronts[i].name} changed since known live base`)
    }
  }
}

if (mode === 'preflight') {
  const before = probe()
  assertBefore(before)
  save(`${instance}-before`, before)
  console.log(JSON.stringify(before, null, 2))
  process.exit(0)
}

const before = load(`${instance}-before`)
if (mode === 'deploy') {
  assertBefore(before)
  if (!same(probe(), before)) throw Error('Instance changed after preflight')
  const backup = `/www/backups/${path.basename(target.site)}/20261015-r25-cf-${commit.slice(0, 8)}`
  const hash = 'hash() { sha256sum "$1" | cut -d\' \' -f1; }'
  const guards = [
    ...files.map(file => `test "$(hash '${target.site}/${file}')" = '${expectedBackend[file]}'`),
    ...fronts.flatMap(front => {
      const expected = r24.builds[front.name]
      return [`test "$(hash '${front.path}/index.html')" = '${expected.index}'`,
        ...Object.entries(expected.assets).map(([file, digest]) => `test "$(hash '${front.path}/${file}')" = '${digest}'`)]
    })
  ].join('\n')
  const setup = `set -euo pipefail\n${hash}\n${target.health}\n${guards}\ntest ! -e '${backup}'\nmkdir -p '${backup}/stage'\n` +
    `tar -czf '${backup}/backend-before.tar.gz' -C '${target.site}' ${files.map(file => `'${file}'`).join(' ')}\n` +
    fronts.map((front, index) => `tar -czf '${backup}/front-${index}-before.tar.gz' -C '${front.path}' index.html ${Object.keys(r24.builds[front.name].assets).map(file => `'${file}'`).join(' ')}`).join('\n') +
    `\ngzip -t '${backup}'/*before.tar.gz\nsha256sum '${backup}'/*before.tar.gz\n`
  const backupResult = ssh(target.host, setup)
  save(`${instance}-backup`, { backup, backupResult })
  run('scp', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', path.join(output, 'payload.tar.gz'), `${target.host}:${backup}/payload.tar.gz`])
  const stage = `${backup}/stage`
  let script = `set -euo pipefail\n${hash}\ntest "$(hash '${backup}/payload.tar.gz')" = '${payload.sha256}'\ntar -xzf '${backup}/payload.tar.gz' -C '${stage}'\n${target.health}\n${guards}\n`
  for (const file of files) {
    const expected = file === files[0] ? manifest.backend.service : manifest.backend.controller
    script += `test "$(hash '${stage}/backend/${file}')" = '${expected}'\nphp -l '${stage}/backend/${file}'\n`
  }
  for (const front of fronts) {
    const build = manifest.builds[front.name]
    script += `test "$(hash '${stage}/${front.name}/index.html')" = '${build.index}'\n`
    for (const [file, digest] of Object.entries(build.assets)) script += `test "$(hash '${stage}/${front.name}/${file}')" = '${digest}'\n`
  }
  script += `rollback() {\nset +e\ntar -xzf '${backup}/backend-before.tar.gz' -C '${target.site}'\n` +
    fronts.map((front, index) => `tar -xzf '${backup}/front-${index}-before.tar.gz' -C '${front.path}' index.html`).join('\n') +
    `\n${target.restart}\necho R25_ROLLBACK_ATTEMPTED >&2\n}\ntrap rollback ERR\n`
  for (const front of fronts) script += `while IFS= read -r -d '' file; do rel="\${file#'${stage}/${front.name}/'}"; dest='${front.path}'/"$rel"; if test -e "$dest"; then cmp -s "$file" "$dest"; else install -D -o www -g www -m 644 "$file" "$dest"; fi; done < <(find '${stage}/${front.name}' -type f ! -name index.html -print0)\n`
  for (const file of files) script += `install -o www -g www -m 644 '${stage}/backend/${file}' '${target.site}/${file}.r25'\nmv -f '${target.site}/${file}.r25' '${target.site}/${file}'\n`
  script += `${target.restart}\n${target.health}\n`
  for (const front of fronts) script += `install -o www -g www -m 644 '${stage}/${front.name}/index.html' '${front.path}/index.html.r25'\nmv -f '${front.path}/index.html.r25' '${front.path}/index.html'\n`
  script += `trap - ERR\necho R25_SWITCH_OK\n`
  const result = ssh(target.host, script)
  save(`${instance}-switch`, { backup, result })
  console.log(JSON.stringify({ instance, backup, result: result.trim(), backupResult: backupResult.trim() }, null, 2))
  process.exit(0)
}

const after = probe()
if (!same(after.migration, before.migration) || after.backend[files[0]] !== manifest.backend.service
  || after.backend[files[1]] !== manifest.backend.controller) throw Error('Backend or migration mismatch')
for (let i = 0; i < fronts.length; i++) {
  const f = after.fronts[i], build = manifest.builds[fronts[i].name]
  if (f.path !== fronts[i].path || f.index_sha256 !== build.index || !same(f.assets, build.assets)) throw Error('Disk frontend mismatch')
}
const publicResults = []
for (const front of fronts) {
  const url = `https://${path.basename(target.site)}${front.name === 'preview' ? '/preview-8080' : ''}/view_cashier_v3/`
  const html = run('curl', ['--fail', '--silent', '--show-error', '--max-time', '45', url])
  const refs = [...html.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(match => match[1])
  if (refs.length !== 2) throw Error('Unexpected public asset references')
  const assets = {}
  for (const ref of refs) {
    const file = `assets/${path.basename(ref)}`
    assets[file] = sha(run('curl', ['--fail', '--silent', '--show-error', '--max-time', '60', new URL(ref, url).href], { encoding: null }))
  }
  if (!same(assets, manifest.builds[front.name].assets)) throw Error(`${front.name} public assets differ`)
  publicResults.push({ url, assets })
}
const verified = { instance, commit, migration: after.migration, backend: after.backend, fronts: after.fronts,
  public: publicResults, backup: load(`${instance}-switch`).backup }
save(`${instance}-verified`, verified)
console.log(JSON.stringify(verified, null, 2))
