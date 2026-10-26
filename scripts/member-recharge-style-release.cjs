const fs = require('fs');
const path = require('path');
const Module = require('module');

// Member static files only. Retain full live-file guards, separate customer
// backups, protected entry fingerprints and RH -> verified push -> 008 order.
const original = path.resolve('scripts/member-background-release.cjs');
let source = fs.readFileSync(original, 'utf8');
for (const [from, to] of [
  ['25c115271e945bdb8f2a46a8bcbb85f8b7137f6f', '740c0c76732ab3a4e1108f45e80a1b8f7c0b2ee8'],
  ['/private/tmp/m008-background-release-20261021', '/private/tmp/m008-recharge-style-release-20261021'],
  ["const previousManifest = '/private/tmp/m008-round6-release-20261020/payload/manifest.json';", "const previousManifest = '/private/tmp/m008-layout-release-20261021/payload/manifest.json';"],
  ['release/member-background-r8-20261021', 'release/member-recharge-style-r9-20261021'],
  ["const previous = load(previousManifest);", "const previous = load(previousManifest); previous.files = previous.allFiles;"],
  ['M008-R8-BG-001', 'M008-R9-RECHARGE-STYLE-001'],
  ["acceptedPatch: '929a185f'", "acceptedPatch: '24ab9f58'"],
  ['20261021-member-background-25c11527', '20261021-member-recharge-style-740c0c76'],
  ['.m008-bg-next', '.m008-recharge-style-next'],
  ['?bg=25c11527', '?recharge=740c0c76'],
  ['const publicResult = await publicCheck(instance, after, manifest.allFiles);', `const publicResult = await publicCheck(instance, after, manifest.allFiles);
    // The recharge page is lazy-loaded, so entry checks alone cannot prove its
    // new script and original illustration are actually served by this site.
    publicResult.affectedAssets = {};
    for (const file of Object.keys(manifest.allFiles).filter(file => /pages-users-user_payment-index\\..*\\.js$/.test(file) || file === 'public/pages/users/static/chongzhi.png')) {
      const response = await get(site.domain, file.slice(6) + '?recharge=740c0c76');
      assert(response.status === 200 && sha(response.body) === manifest.allFiles[file], 'Recharge public asset differs: ' + file);
      publicResult.affectedAssets[file] = manifest.allFiles[file];
    }
    assert(Object.keys(publicResult.affectedAssets).length === 2, 'Recharge asset evidence incomplete');`],
  ["} else throw Error('Use pack, deploy, verify or rollback');", `} else if (mode === 'inspect') {
    const snapshot = inspect(instance, Object.keys(previous.files));
    for (const [file, hash] of Object.entries(previous.files)) assert(snapshot.memberFiles[file] === hash, 'Current member baseline differs: ' + file);
    save(instance + '-before.json', snapshot);
    console.log(JSON.stringify({instance, baseline: previous.commit, files: Object.keys(snapshot.memberFiles).length, latestUpgrade: snapshot.latestUpgrade, database: snapshot.database}));
  } else throw Error('Use inspect, pack, deploy, verify or rollback');`]
]) {
  if (!source.includes(from)) throw Error('Release adapter hook changed: ' + from);
  source = source.split(from).join(to);
}
const runtime = new Module(original, module);
runtime.filename = original;
runtime.paths = module.paths;
runtime._compile(source, original);
