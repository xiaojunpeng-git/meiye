import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { execFileSync } from 'node:child_process'

// Explicit per-instance release only; each has independent preflight, backup, restart and verification.
// Frozen package and pre-switch hashes prevent overwriting concurrent releases or cross-customer data.
const [mode, instance] = process.argv.slice(2)
const root = process.cwd()
const out = '/private/tmp/mohe-r58-release-20261015'
const targets = {
  rh: { host: 'rh-server', domain: 'rh.cc3798.com', database: 'ruihao', port: '3306',
    restart: '/etc/init.d/ruihao_swoole restart', health: "ss -lnt | grep -q ':20800 '" },
  '008': { host: 'mohe-server', domain: '008.cc3798.com', database: 'mohe_008', port: '3307',
    restart: 'systemctl restart mohe-008-swoole.service', health: 'systemctl is-active --quiet mohe-008-swoole.service' }
}
const target = targets[instance]
if (!target || !['deploy', 'verify'].includes(mode)) throw Error('Choose deploy/verify and rh/008')
const run = (tool, args, options = {}) => execFileSync(tool, args, { cwd: root, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, ...options })
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex')
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b)
const load = name => JSON.parse(fs.readFileSync(path.join(out, name + '.json')))
const save = (name, value) => fs.writeFileSync(path.join(out, name + '.json'), JSON.stringify(value, null, 2))
const pack = load('package'), before = load(instance + '-before')
if (pack.payloadSha256 !== sha(fs.readFileSync(path.join(out, 'payload.tar.gz')))) throw Error('Package changed')
if (before.database !== target.database || before.port !== target.port) throw Error('Wrong database')
const site = '/www/wwwroot/' + target.domain
const fronts = before.fronts.map((front, i) => ({ ...front, name: i === 0 ? 'formal' : 'preview' }))
if (fronts.length !== (instance === 'rh' ? 2 : 1)) throw Error('Unexpected entries')
if (fronts[0].path !== site + '/public/view_cashier_v3'
  || (instance === 'rh' && fronts[1].path !== '/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3')) throw Error('Unexpected entry paths')
const ssh = script => run('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', target.host, 'bash -s'], { input: script })
const php = script => JSON.parse(run('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', target.host,
  `php -r 'eval("?>" . stream_get_contents(STDIN));' -- ${instance}`], { input: fs.readFileSync(script) }))
const probe = () => php('scripts/r58-release-probe.php')
const files = Object.keys(pack.backend)
const hash = 'hash() { sha256sum "$1" | cut -d\' \' -f1; }'
const guards = [
  ...Object.entries(before.files).map(([file, digest]) => digest === null ? `test ! -e '${site}/${file}'` : `test "$(hash '${site}/${file}')" = '${digest}'`),
  ...fronts.flatMap(front => [`test "$(hash '${front.path}/index.html')" = '${front.index_sha256}'`,
    ...Object.entries(front.assets).map(([file, digest]) => `test "$(hash '${front.path}/${file}')" = '${digest}'`)])
].join('\n')
const backup = `/www/backups/${target.domain}/20261015-r58-${pack.commit.slice(0, 8)}`
if (mode === 'deploy') {
  if (instance === '008' && !fs.existsSync(path.join(out, 'push.json'))) throw Error('Verify RH and push before deploying 008')
  if (!same(probe(), before)) throw Error('Live instance changed after preflight; do not overwrite')
  // No schema/data change: back up precisely the overwritten code and entrypoints, retain old hashed assets.
  const existing = files.filter(file => before.files[file] !== null)
  const setup = `set -euo pipefail\n${hash}\n${target.health}\n${guards}\ntest ! -e '${backup}'\nmkdir -p '${backup}/stage'\n` +
    `tar -czf '${backup}/backend-before.tar.gz' -C '${site}' ${existing.map(file => `'${file}'`).join(' ')}\n` +
    fronts.map((front, i) => `tar -czf '${backup}/front-${i}-before.tar.gz' -C '${front.path}' index.html ${Object.keys(front.assets).map(file => `'${file}'`).join(' ')}`).join('\n') +
    `\ngzip -t '${backup}'/*before.tar.gz\nsha256sum '${backup}'/*before.tar.gz\n`
  save(instance + '-backup', { backup, result: ssh(setup) })
  run('scp', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', path.join(out, 'payload.tar.gz'), `${target.host}:${backup}/payload.tar.gz`])
  const stage = backup + '/stage'
  let script = `set -euo pipefail\n${hash}\ntest "$(hash '${backup}/payload.tar.gz')" = '${pack.payloadSha256}'\ntar -xzf '${backup}/payload.tar.gz' -C '${stage}'\n${target.health}\n${guards}\n`
  for (const file of files) script += `test "$(hash '${stage}/backend/${file}')" = '${pack.backend[file]}'\nphp -l '${stage}/backend/${file}'\n`
  for (const front of fronts) {
    const build = pack.builds[front.name + '-candidate']
    script += `test "$(hash '${stage}/${front.name}/index.html')" = '${build.index_sha256}'\n`
    for (const [file, digest] of Object.entries(build.assets)) script += `test "$(hash '${stage}/${front.name}/${file}')" = '${digest}'\n`
  }
  script += `rollback() {\nset +e\ntar -xzf '${backup}/backend-before.tar.gz' -C '${site}'\n` +
    files.filter(file => before.files[file] === null).map(file => `rm -f '${site}/${file}'`).join('\n') + '\n' +
    fronts.map((front, i) => `tar -xzf '${backup}/front-${i}-before.tar.gz' -C '${front.path}' index.html`).join('\n') +
    `\n${target.restart}\necho R58_ROLLBACK_ATTEMPTED >&2\n}\ntrap rollback ERR\n`
  // Hashed assets are additive. An existing name with different bytes is an error, never an overwrite.
  for (const front of fronts) script += `while IFS= read -r -d '' file; do rel="\${file#'${stage}/${front.name}/'}"; dest='${front.path}'/"$rel"; if test -e "$dest"; then cmp -s "$file" "$dest"; else install -D -o www -g www -m 644 "$file" "$dest"; fi; done < <(find '${stage}/${front.name}' -type f ! -name index.html -print0)\n`
  for (const file of files) script += `install -o www -g www -m 644 '${stage}/backend/${file}' '${site}/${file}.r58'\nmv -f '${site}/${file}.r58' '${site}/${file}'\n`
  script += `${target.restart}\n${target.health}\n`
  for (const front of fronts) script += `install -o www -g www -m 644 '${stage}/${front.name}/index.html' '${front.path}/index.html.r58'\nmv -f '${front.path}/index.html.r58' '${front.path}/index.html'\n`
  script += `trap - ERR\necho R58_SWITCH_OK\n`
  const result = ssh(script)
  save(instance + '-switch', { backup, result })
  console.log(JSON.stringify({ instance, backup, result }))
} else {
  const after = probe()
  if (!same(after.files, pack.backend) || !same(after.migration, before.migration)) throw Error('Backend/migration mismatch')
  ssh(`set -e\n${target.health}\n`)
  const publicResults = []
  for (let i = 0; i < fronts.length; i++) {
    const front = fronts[i], build = pack.builds[front.name + '-candidate']
    if (after.fronts[i].index_sha256 !== build.index_sha256 || !same(after.fronts[i].assets, build.assets)) throw Error('Disk entry mismatch')
    const url = `https://${target.domain}${front.name === 'preview' ? '/preview-8080' : ''}/view_cashier_v3/`
    const html = run('curl', ['--fail', '--silent', '--show-error', '--max-time', '45', url])
    const refs = [...html.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(m => m[1])
    if (refs.length !== 2) throw Error('Unexpected public entry')
    const assets = {}
    for (const ref of refs) assets['assets/' + path.basename(ref)] = sha(run('curl', ['--fail', '--silent', '--show-error', '--max-time', '60', new URL(ref, url).href], { encoding: null }))
    if (!same(assets, build.assets)) throw Error('Public assets mismatch')
    publicResults.push({ url, assets, htmlSha256: sha(html) })
  }
  const smoke = php('scripts/r58-release-smoke.php')
  if (smoke.result !== 'PASS') throw Error('Read-only smoke failed')
  const verified = { instance, commit: pack.commit, backup, after, public: publicResults, smoke }
  save(instance + '-verified', verified)
  console.log(JSON.stringify(verified, null, 2))
}
