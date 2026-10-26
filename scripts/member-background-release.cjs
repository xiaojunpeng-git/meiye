const fs = require('fs');
const path = require('path');
const https = require('https');
const cp = require('child_process');
const crypto = require('crypto');
const { Parser } = require(path.resolve('前端代码/admin/node_modules/htmlparser2'));

// The accepted member-only release never writes SQL, customer configuration,
// admin, cashier or mini-program files. Every instance has its own guard/backup.
const [mode, instance] = process.argv.slice(2);
const commit = '25c115271e945bdb8f2a46a8bcbb85f8b7137f6f';
const base = '/private/tmp/m008-background-release-20261021';
const previousManifest = '/private/tmp/m008-round6-release-20261020/payload/manifest.json';
const branch = 'release/member-background-r8-20261021';
const sites = {
  rh: { host: 'rh-server', domain: 'rh.cc3798.com', db: 'ruihao', port: 3306 },
  '008': { host: 'mohe-server', domain: '008.cc3798.com', db: 'mohe_008', port: 3307 }
};
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const run = (command, args, options = {}) => cp.execFileSync(command, args, { encoding: 'utf8', maxBuffer: 128e6, ...options });
const git = args => run('git', args);
const load = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const save = (file, data) => fs.writeFileSync(path.join(base, file), JSON.stringify(data, null, 2) + '\n');
const assert = (ok, message) => { if (!ok) throw Error(message); };
const quote = text => "'" + text.replace(/'/g, "'\\''") + "'";
fs.mkdirSync(base, { recursive: true });
// Evidence-only archive commits may follow the business commit. Any source
// drift still blocks install/rollback instead of silently rebuilding a new UI.
git(['merge-base', '--is-ancestor', commit, 'HEAD']);
git(['diff', '--exit-code', commit + '..HEAD', '--', '前端代码/uniapp', '前端代码/shared']);
git(['diff', '--exit-code', commit, '--', '前端代码/uniapp', '前端代码/shared']);

function inspect(key, files) {
  const site = sites[key];
  assert(site, 'Explicit instance required');
  const encoded = Buffer.from(JSON.stringify({ ...site, key, files, root: '/www/wwwroot/' + site.domain })).toString('base64');
  const pattern = Buffer.from('~(?:src|href)=["\x27]?([^"\x27\\s>]+\\.(?:js|css)(?:\\?[^"\x27\\s>]*)?)~').toString('base64');
  // Credentials are read only on their own server and never printed. The
  // transaction fingerprints decoration instead of copying it across clients.
  const php = `<?php
$s=json_decode(base64_decode('${encoded}'),true);$r=$s['root'];$hash=static function($f){return is_file($f)?hash_file('sha256',$f):null;};
$e=parse_ini_file($r.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($e['DATABASE']??$e['database']??[]);
if(($c['database']??'')!==$s['db']||(int)($c['hostport']??0)!==$s['port']||!in_array($c['hostname'],['127.0.0.1','localhost'],true))throw new RuntimeException('Wrong instance');
$p=new PDO('mysql:host='.$c['hostname'].';port='.$s['port'].';dbname='.$s['db'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('START TRANSACTION READ ONLY');
$o=['instance'=>$s['key'],'environment'=>$hash($r.'/.env'),'front'=>[],'memberFiles'=>[],'database'=>['name'=>$s['db'],'port'=>$s['port'],'version'=>$p->query('SELECT VERSION()')->fetchColumn()],'latestUpgrade'=>$p->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC)];
$o['decorationDigest']=hash('sha256',json_encode($p->query('SELECT * FROM eb_diy ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_UNICODE));
$entries=['member'=>$r.'/public/index.html','admin'=>$r.'/public/system.html','cashier'=>$r.'/public/view_cashier_v3/index.html'];if($s['key']==='rh')$entries['cashier_preview']=$r.'-8080/public/view_cashier_v3/index.html';
foreach($entries as $key=>$f){$html=file_get_contents($f);preg_match_all(base64_decode('${pattern}'),$html,$m);$assets=[];foreach(array_unique($m[1]) as $ref){if(preg_match('~^(?:https?:)?//~',$ref))continue;$url=explode('?',$ref)[0];$public=$key==='cashier_preview'?$r.'-8080/public':$r.'/public';$file=strpos($url,'/')===0?$public.$url:dirname($f).'/'.$url;$assets[$ref]=$hash($file);}$o['front'][$key]=['path'=>$f,'index'=>$hash($f),'assets'=>$assets];}
foreach($s['files'] as $f)$o['memberFiles'][$f]=$hash($r.'/'.$f);$p->exec('ROLLBACK');echo json_encode($o,JSON_UNESCAPED_SLASHES),PHP_EOL;`;
  return JSON.parse(run('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', site.host, '/www/server/php/74/bin/php'], { input: php }));
}

function walk(root) {
  const out = {};
  const visit = directory => {
    for (const e of fs.readdirSync(directory, { withFileTypes: true })) {
      const file = path.join(directory, e.name);
      if (e.isDirectory()) visit(file);
      else out['public/' + path.relative(root, file)] = sha(fs.readFileSync(file));
    }
  };
  visit(root);
  return out;
}

function get(domain, pathname) {
  return new Promise((resolve, reject) => {
    const request = https.get('https://' + domain + pathname, { headers: { 'Accept-Encoding': 'identity', 'Cache-Control': 'no-cache' } }, response => {
      const chunks = [];
      response.on('data', b => chunks.push(b));
      response.on('end', () => resolve({ status: response.statusCode, body: Buffer.concat(chunks) }));
    });
    request.on('error', reject);
    request.setTimeout(30000, () => request.destroy(Error('HTTP timeout')));
  });
}

async function publicCheck(key, before, candidate) {
  const site = sites[key];
  const out = { entries: {}, api: {} };
  for (const [name, entry] of Object.entries(before.front)) {
    const url = { member: '/pages/index/index', admin: '/system.html', cashier: '/view_cashier_v3/', cashier_preview: '/preview-8080/view_cashier_v3/' }[name];
    const response = await get(site.domain, url + '?bg=25c11527');
    assert(response.status === 200, 'Entry failed: ' + name);
    const html = response.body.toString();
    const assets = {};
    const references = [];
    const parser = new Parser({ onopentag(name, attributes) {
      if (name === 'script' && attributes.src) references.push(attributes.src);
      if (name === 'link' && attributes.rel === 'stylesheet' && attributes.href) references.push(attributes.href);
    } });
    parser.end(html);
    for (const ref of new Set(references)) {
      if (/^(?:https?:)?\/\//.test(ref)) continue;
      const pathname = new URL(ref, 'https://' + site.domain + url).pathname;
      const normalized = pathname.replace(/^\/preview-8080/, '');
      const expected = name === 'member' && candidate ? candidate['public' + normalized] : entry.assets[ref] || entry.assets[normalized];
      assert(expected, 'Unknown entry asset: ' + pathname);
      const result = await get(site.domain, pathname + '?bg=25c11527');
      assert(result.status === 200 && sha(result.body) === expected, 'Public asset mismatch: ' + pathname);
      assets[pathname] = expected;
    }
    assert(Object.keys(assets).length >= 2, 'No entry dependency graph: ' + name);
    if (name !== 'cashier_preview') assert(sha(response.body) === (name === 'member' && candidate ? candidate['public/index.html'] : entry.index), 'Public entry mismatch: ' + name);
    out.entries[name] = { sha256: sha(response.body), assets };
  }
  for (const pathname of ['/api/index', '/api/diy/get_diy/0', '/api/menu/user']) {
    const response = await get(site.domain, pathname);
    const body = JSON.parse(response.body.toString());
    assert(Number(body.status) === 200, 'Read API failed: ' + pathname);
    out.api[pathname] = { status: body.status, dataSha256: sha(JSON.stringify(body.data)) };
  }
  return out;
}

async function main() {
  const previous = load(previousManifest);
  if (mode === 'pack') {
    const build = load(path.join(base, 'member-candidate-input.json'));
    const complete = load(path.join(base, 'member-candidate-complete.json'));
    assert(build.commit === commit && complete.result === 'PASS', 'Immutable build failed');
    const allFiles = walk(path.join(base, 'member-candidate'));
    const changed = Object.keys(allFiles).filter(f => previous.files[f] !== allFiles[f]);
    const manifest = { task: 'M008-R8-BG-001', commit, acceptedPatch: '929a185f', tree: build.tree, sourceInput: build, previous: previous.commit, allFiles, files: {}, before: {} };
    const payload = path.join(base, 'payload');
    assert(!fs.existsSync(payload), 'Payload already exists');
    fs.mkdirSync(payload);
    for (const f of changed) {
      assert(!/view_admin|view_cashier|system\.html/.test(f) && f.startsWith('public/'), 'Protected output');
      const destination = path.join(payload, f);
      fs.mkdirSync(path.dirname(destination), { recursive: true });
      fs.copyFileSync(path.join(base, 'member-candidate', f.slice(7)), destination);
      manifest.files[f] = allFiles[f];
    }
    // HTML is switched last even when its bytes stay equal; this avoids changing
    // the entry before its new hashed chunks have been installed.
    if (!manifest.files['public/index.html']) {
      fs.copyFileSync(path.join(base, 'member-candidate/index.html'), path.join(payload, 'public/index.html'));
      manifest.files['public/index.html'] = allFiles['public/index.html'];
    }
    for (const key of Object.keys(sites)) {
      const original = load(path.join(base, key + '-before.json'));
      const files = [...new Set([...Object.keys(original.memberFiles), ...Object.keys(allFiles)])];
      const fresh = inspect(key, files);
      assert(JSON.stringify(fresh.front) === JSON.stringify(original.front) && fresh.environment === original.environment && fresh.decorationDigest === original.decorationDigest, 'Live baseline drifted');
      for (const [f, hash] of Object.entries(original.memberFiles)) assert(fresh.memberFiles[f] === hash && hash === previous.files[f], 'Unrecognized live member file: ' + f);
      manifest.before[key] = fresh;
      save(key + '-guard.json', fresh);
      save(key + '-public-before.json', await publicCheck(key, fresh, null));
    }
    fs.writeFileSync(path.join(payload, 'manifest.json'), JSON.stringify(manifest, null, 2));
    // Reuse the previously exercised static installer as a generated artifact;
    // only its immutable commit, private directory and staging suffix change.
    let installer = fs.readFileSync('scripts/member-round5-release-install.php', 'utf8');
    for (const [from, to] of [['20261020-member-round5-46c4b377', '20261021-member-background-25c11527'], ['46c4b37715880d0b6f8f22f59e5d1cf39d3ad54c', commit], ['.m008-r5-next', '.m008-bg-next']]) {
      assert(installer.includes(from), 'Installer hook changed');
      installer = installer.split(from).join(to);
    }
    fs.writeFileSync(path.join(base, 'install.php'), installer);
    run('tar', ['-czf', path.join(base, 'payload.tar.gz'), '-C', base, 'payload']);
    save('payload.json', { commit, fileCount: Object.keys(manifest.files).length, fullFileCount: Object.keys(allFiles).length, changed, archiveSha256: sha(fs.readFileSync(path.join(base, 'payload.tar.gz'))), installerSha256: sha(installer) });
    console.log(JSON.stringify(load(path.join(base, 'payload.json')), null, 2));
  } else if (mode === 'deploy' || mode === 'rollback') {
    const site = sites[instance];
    assert(site, 'Explicit rh/008 required');
    if (mode === 'deploy' && instance === '008') {
      assert(load(path.join(base, 'rh-verified.json')).result === 'PASS', 'RH must pass first');
      const pushed = load(path.join(base, 'pushed.json'));
      assert(git(['ls-remote', 'origin', 'refs/heads/' + branch]).split(/\s/)[0] === pushed.commit, 'Verified push must precede 008');
    }
    const directory = '/www/backups/' + site.domain + '/20261021-member-background-25c11527';
    const command = action => '/www/server/php/74/bin/php ' + quote(directory + '/install.php') + ' ' + quote('/www/wwwroot/' + site.domain) + ' ' + quote(directory) + ' ' + action;
    if (mode === 'rollback') {
      console.log(run('ssh', ['-q', site.host, command('rollback')]));
      return;
    }
    const before = load(path.join(base, instance + '-guard.json'));
    const now = inspect(instance, Object.keys(before.memberFiles));
    assert(JSON.stringify(now) === JSON.stringify(before), 'Live state changed since guard');
    const payload = load(path.join(base, 'payload.json'));
    assert(sha(fs.readFileSync(path.join(base, 'payload.tar.gz'))) === payload.archiveSha256 && sha(fs.readFileSync(path.join(base, 'install.php'))) === payload.installerSha256, 'Payload changed');
    run('ssh', ['-q', site.host, 'bash', '-s'], { input: 'set -euo pipefail\ntest ! -e ' + quote(directory) + '\nmkdir -m 700 -p ' + quote(directory) + '\n' });
    run('scp', ['-q', path.join(base, 'payload.tar.gz'), path.join(base, 'install.php'), site.host + ':' + directory + '/']);
    const output = run('ssh', ['-q', site.host, 'bash', '-s'], { input: `set -euo pipefail\ntest "$(sha256sum ${quote(directory + '/payload.tar.gz')} | cut -d' ' -f1)" = '${payload.archiveSha256}'\ntest "$(sha256sum ${quote(directory + '/install.php')} | cut -d' ' -f1)" = '${payload.installerSha256}'\ntar -xzf ${quote(directory + '/payload.tar.gz')} -C ${quote(directory)}\n${command('prepare')}\nif ! ${command('install')}; then ${command('rollback')}; exit 1; fi\n` });
    save(instance + '-installed.json', { commit, backup: directory, output, installedAt: new Date().toISOString() });
    console.log(output);
  } else if (mode === 'verify') {
    const site = sites[instance];
    assert(site, 'Explicit instance required');
    const manifest = load(path.join(base, 'payload/manifest.json'));
    const before = manifest.before[instance];
    const after = inspect(instance, Object.keys(before.memberFiles));
    for (const [f, hash] of Object.entries(manifest.allFiles)) assert(after.memberFiles[f] === hash, 'Deployed member mismatch: ' + f);
    assert(after.environment === before.environment && after.decorationDigest === before.decorationDigest && JSON.stringify(after.latestUpgrade) === JSON.stringify(before.latestUpgrade), 'Protected configuration/upgrade changed');
    for (const key of Object.keys(before.front)) if (key !== 'member') assert(JSON.stringify(after.front[key]) === JSON.stringify(before.front[key]), 'Protected frontend drift: ' + key);
    const publicResult = await publicCheck(instance, after, manifest.allFiles);
    const publicBefore = load(path.join(base, instance + '-public-before.json'));
    for (const endpoint of ['/api/diy/get_diy/0', '/api/menu/user']) {
      assert(publicResult.api[endpoint].dataSha256 === publicBefore.api[endpoint].dataSha256, 'Decoration/menu projection changed: ' + endpoint);
    }
    save(instance + '-after.json', after);
    save(instance + '-public-after.json', publicResult);
    save(instance + '-verified.json', { instance, result: 'PASS', commit, filesVerified: Object.keys(manifest.allFiles).length, installedFiles: Object.keys(manifest.files).length, databaseWrites: 0, environmentUnchanged: true, decorationUnchanged: true, adminUnchanged: true, cashierAndPreviewUnchanged: true, publicEntries: publicResult.entries, checkedAt: new Date().toISOString() });
    console.log(JSON.stringify(load(path.join(base, instance + '-verified.json')), null, 2));
  } else throw Error('Use pack, deploy, verify or rollback');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
