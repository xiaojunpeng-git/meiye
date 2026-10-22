// Release only the accepted cube frontend trees. Each instance is guarded and
// backed up independently; no SQL/configuration or cashier deployment is allowed.
const fs = require('fs'), path = require('path'), cp = require('child_process'), vm = require('vm');
const base = '/private/tmp/m008-cube-release-20261022';
const accepted = '1e1296eb0e77b16dda1150deab74ce9a416f524c';
const branch = 'release/member-cube-r2-20261022';
const read = p => JSON.parse(fs.readFileSync(p, 'utf8'));
const [action, instance] = process.argv.slice(2);
// Reuse the exercised read-only inspection and public HTTP helpers, not the old
// deployment main function. Bind its source and guard scope to this release.
let helpers = fs.readFileSync('scripts/member-background-release.cjs', 'utf8');
helpers = helpers.slice(0, helpers.indexOf('async function main()'))
  .replace("const commit = '25c115271e945bdb8f2a46a8bcbb85f8b7137f6f';", `const commit = '${accepted}';`)
  .replace("const base = '/private/tmp/m008-background-release-20261021';", `const base = '${base}';`)
  .replace("name === 'member' && candidate", "['member','admin'].includes(name) && candidate")
  .replace("name === 'member' && candidate", "['member','admin'].includes(name) && candidate")
  .replace("candidate['public/index.html']", "candidate[name === 'member' ? 'public/index.html' : 'public/system.html']");
const api = vm.runInThisContext('(function(require){' + helpers + '\nreturn {inspect,walk,get,publicCheck,sha,run,git,save,assert,quote,sites};})')(require);
const {inspect,walk,publicCheck,sha,run,git,save,assert,quote,sites} = api;
const roots = {
  admin: '/private/tmp/m008-cube-slides-20261022-verified/admin/admin-candidate',
  member: '/private/tmp/m008-cube-slides-20261022-v2/member/member-candidate'
};
const beforeMember = read('/private/tmp/m008-emerg-r2-release-20261022/payload/manifest.json');
const beforeAdmin = read('/private/tmp/mohe-r22-release-20261021-c474daea/payload/manifest.json');
const guards = ['app/controller/admin/v1/diy/Diy.php', 'app/services/diy/DiyServices.php'];
const sourceTrees = {admin: '7fe9c51077d9aa61e76582539d62ee4a233f3dc3', member: 'd50cb7d416372682b330c2b2e1d8d9cc5e6b9327', mini: '2c0d48158796f52cea68555a623c20d7a0867af2'};

function sourceGuard() {
  for (const [target, tree] of Object.entries(sourceTrees)) {
    for (const directory of ['前端代码/shared', target === 'admin' ? '前端代码/admin' : '前端代码/uniapp']) {
      assert(git(['rev-parse', tree + ':' + directory]).trim() === git(['rev-parse', accepted + ':' + directory]).trim(), 'Accepted source tree changed: ' + target);
    }
  }
  git(['diff', '--exit-code', 'c474daea', accepted + '^', '--', '前端代码/admin', '前端代码/shared']);
  git(['diff', '--exit-code', beforeMember.commit, accepted + '^', '--', '前端代码/uniapp']);
}

function protectedGuard(before, after) {
  assert(after.environment === before.environment && after.decorationDigest === before.decorationDigest, 'Configuration drift');
  assert(JSON.stringify(after.latestUpgrade) === JSON.stringify(before.latestUpgrade), 'Migration baseline drift');
  for (const key of ['cashier', 'cashier_preview']) if (before.front[key]) assert(JSON.stringify(after.front[key]) === JSON.stringify(before.front[key]), 'Cashier drift: ' + key);
  for (const file of guards) assert(after.memberFiles[file] === before.memberFiles[file], 'Backend drift: ' + file);
}

async function main() {
  sourceGuard();
  if (action === 'pack') {
    // Admin's copied legacy public directory is not a business build output;
    // preserve those deployed static assets instead of replacing the site root.
    const adminFiles = Object.fromEntries(Object.entries(walk(roots.admin)).filter(([f]) => f === 'public/system.html' || f.startsWith('public/view_admin/')));
    const allFiles = {...adminFiles, ...walk(roots.member)};
    const old = {...beforeAdmin.frontendAssets, ...beforeMember.allFiles};
    const files = [...new Set([...Object.keys(old), ...Object.keys(allFiles), ...guards])];
    const manifest = {task: 'M008-EMERG-R2-CUBE', commit: accepted, acceptedPatch: '80fc2467', sourceTrees, allFiles, files: {}, before: {}};
    const payload = path.join(base, 'payload');
    assert(!fs.existsSync(path.join(payload, 'manifest.json')), 'Payload already frozen');
    if (fs.existsSync(payload)) fs.rmSync(payload, {recursive: true});
    fs.mkdirSync(payload);
    for (const key of ['rh', '008']) {
      const original = read(path.join(base, key + '-before.json'));
      const snap = inspect(key, files);
      protectedGuard(original, snap);
      assert(JSON.stringify(snap.front) === JSON.stringify(original.front), 'Live entry advanced');
      for (const [file, hash] of Object.entries(old)) assert(snap.memberFiles[file] === hash, 'Published baseline differs: ' + file);
      manifest.before[key] = snap;
      save(key + '-public-before.json', await publicCheck(key, snap, null));
    }
    for (const [file, hash] of Object.entries(allFiles)) {
      assert(file.startsWith('public/') && !/view_cashier|cube-(test|proof)/.test(file), 'Unexpected release asset');
      if (hash === old[file] && !['public/index.html', 'public/system.html'].includes(file)) continue;
      const sourceRoot = file === 'public/system.html' || file.startsWith('public/view_admin/') ? roots.admin : roots.member;
      const target = path.join(payload, file);
      fs.mkdirSync(path.dirname(target), {recursive: true});
      fs.copyFileSync(path.join(sourceRoot, file.slice(7)), target);
      assert(sha(fs.readFileSync(target)) === hash, 'Payload copy differs');
      manifest.files[file] = hash;
    }
    fs.writeFileSync(path.join(payload, 'manifest.json'), JSON.stringify(manifest, null, 2));
    // Generate the two-entry variant of the exercised static installer. All
    // assets land before either entry; rollback restores both entries last.
    let installer = fs.readFileSync('scripts/member-round5-release-install.php', 'utf8');
    for (const [from, to] of [
      ['20261020-member-round5-46c4b377', '20261022-member-cube-1e1296eb'],
      ['46c4b37715880d0b6f8f22f59e5d1cf39d3ad54c', accepted],
      ['.m008-r5-next', '.m008-cube-next'],
      ['(?:system\\.html|view_admin|view_cashier_v3)', '(?:view_cashier_v3)'],
      ["if ($file === 'public/index.html') continue;", "if (in_array($file, ['public/index.html', 'public/system.html'], true)) continue;"],
      ["$copy($directory . '/payload/public/index.html', $root . '/public/index.html');", "$copy($directory . '/payload/public/system.html', $root . '/public/system.html');\n        $copy($directory . '/payload/public/index.html', $root . '/public/index.html');"],
      ["$a === 'public/index.html'", "in_array($a, ['public/index.html', 'public/system.html'], true)"],
      ["$b === 'public/index.html'", "in_array($b, ['public/index.html', 'public/system.html'], true)"],
      ["if ($key === 'member') continue;", "if (in_array($key, ['member', 'admin'], true)) continue;"],
      ["'adminUnchanged' => true", "'adminUpdated' => true"]
    ]) {
      assert(installer.includes(from), 'Installer hook drift: ' + from);
      installer = installer.split(from).join(to);
    }
    fs.writeFileSync(path.join(base, 'install.php'), installer);
    run('tar', ['-czf', path.join(base, 'payload.tar.gz'), '-C', base, 'payload']);
    const receipt = {commit: accepted, files: Object.keys(manifest.files).length, allFiles: Object.keys(allFiles).length, archiveSha256: sha(fs.readFileSync(path.join(base, 'payload.tar.gz'))), installerSha256: sha(installer)};
    save('payload.json', receipt);
    console.log(JSON.stringify(receipt));
    return;
  }
  const site = sites[instance];
  assert(site, 'Explicit instance required');
  const manifest = read(path.join(base, 'payload/manifest.json'));
  const before = manifest.before[instance];
  const directory = '/www/backups/' + site.domain + '/20261022-member-cube-1e1296eb';
  if (action === 'deploy') {
    if (instance === '008') {
      assert(read(path.join(base, 'rh-verified.json')).result === 'PASS', 'RH verification required');
      assert(git(['ls-remote', 'origin', 'refs/heads/' + branch]).split(/\s/)[0] === read(path.join(base, 'pushed.json')).commit, 'Precise push required');
    }
    const fresh = inspect(instance, Object.keys(before.memberFiles));
    assert(JSON.stringify(fresh) === JSON.stringify(before), 'Instance baseline advanced; stop before write');
    const receipt = read(path.join(base, 'payload.json'));
    assert(sha(fs.readFileSync(path.join(base, 'payload.tar.gz'))) === receipt.archiveSha256 && sha(fs.readFileSync(path.join(base, 'install.php'))) === receipt.installerSha256, 'Frozen payload changed');
    run('ssh', ['-q', site.host, 'bash', '-s'], {input: 'set -euo pipefail\ntest ! -e ' + quote(directory) + '\nmkdir -m 700 -p ' + quote(directory) + '\n'});
    run('scp', ['-q', path.join(base, 'payload.tar.gz'), path.join(base, 'install.php'), site.host + ':' + directory + '/']);
    const command = mode => '/www/server/php/74/bin/php ' + quote(directory + '/install.php') + ' ' + quote('/www/wwwroot/' + site.domain) + ' ' + quote(directory) + ' ' + mode;
    const output = run('ssh', ['-q', site.host, 'bash', '-s'], {input: `set -euo pipefail\ntest "$(sha256sum ${quote(directory + '/payload.tar.gz')} | cut -d' ' -f1)" = '${receipt.archiveSha256}'\ntest "$(sha256sum ${quote(directory + '/install.php')} | cut -d' ' -f1)" = '${receipt.installerSha256}'\ntar -xzf ${quote(directory + '/payload.tar.gz')} -C ${quote(directory)}\n${command('prepare')}\nif ! ${command('install')}; then ${command('rollback')}; exit 1; fi\n`});
    save(instance + '-installed.json', {commit: accepted, backup: directory, output, time: new Date().toISOString()});
    console.log(output);
  } else if (action === 'verify') {
    const after = inspect(instance, Object.keys(before.memberFiles));
    protectedGuard(before, after);
    for (const [file, hash] of Object.entries(manifest.allFiles)) assert(after.memberFiles[file] === hash, 'Installed artifact differs: ' + file);
    const publicResult = await publicCheck(instance, after, manifest.allFiles);
    const previous = read(path.join(base, instance + '-public-before.json'));
    for (const endpoint of ['/api/diy/get_diy/0', '/api/menu/user']) assert(previous.api[endpoint].dataSha256 === publicResult.api[endpoint].dataSha256, 'Decoration/menu response changed');
    save(instance + '-after.json', after);
    save(instance + '-public-after.json', publicResult);
    const result = {result: 'PASS', commit: accepted, instance, backup: directory, files: Object.keys(manifest.allFiles).length, cashierUnchanged: true, backendUnchanged: true, configurationUnchanged: true, databaseWrites: 0, time: new Date().toISOString()};
    save(instance + '-verified.json', result);
    console.log(JSON.stringify(result));
  } else throw Error('Unknown action');
}
main().catch(error => { console.error(error.stack); process.exitCode = 1; });
