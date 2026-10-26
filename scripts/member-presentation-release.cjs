const fs = require('fs'), path = require('path'), Module = require('module');

// Only member H5 static files may be installed. Keep full live-file guards,
// independent backups and RH -> verified push -> 008 ordering from the proven
// installer; no backend, database, configuration or mini-program is released.
const original = path.resolve('scripts/member-background-release.cjs');
let source = fs.readFileSync(original,'utf8');
for (const [from,to] of [
  ['25c115271e945bdb8f2a46a8bcbb85f8b7137f6f','78d6367991976a0082879efd6412f531365815e1'],
  ['/private/tmp/m008-background-release-20261021','/private/tmp/m009-presentation-release-20261021'],
  ["const previousManifest = '/private/tmp/m008-round6-release-20261020/payload/manifest.json';","const previousManifest = '/private/tmp/m008-recharge-style-release-20261021/payload/manifest.json';"],
  ['release/member-background-r8-20261021','release/member-presentation-r9-20261021'],
  ["const previous = load(previousManifest);","const previous = load(previousManifest); previous.files = previous.allFiles;"],
  ['M008-R8-BG-001','M008-R9-PRESENTATION-002'],
  ["acceptedPatch: '929a185f'","acceptedPatch: '976782de'"],
  ['20261021-member-background-25c11527','20261021-member-presentation-78d63679'],
  ['.m008-bg-next','.m009-presentation-next'],
  ['?bg=25c11527','?presentation=78d63679'],
  ['const publicResult = await publicCheck(instance, after, manifest.allFiles);',`const publicResult = await publicCheck(instance, after, manifest.allFiles);
    // Both affected pages are lazy chunks; entry HTTP 200 cannot establish
    // their deployment. Verify each chunk and the reused font over HTTPS.
    publicResult.affectedAssets = {};
    for (const file of Object.keys(manifest.allFiles).filter(file => /(?:pages-users-user_card_list-index|pages-goods-member_checkout-index)\\.[^/]+\\.(?:js|css)$/.test(file) || file === 'public/static/iconfont/iconfont.woff2')) {
      const response = await get(site.domain, file.slice(6) + '?presentation=78d63679');
      assert(response.status === 200 && sha(response.body) === manifest.allFiles[file], 'Affected public asset differs: ' + file);
      publicResult.affectedAssets[file] = manifest.allFiles[file];
    }
    for (const name of ['pages-users-user_card_list-index','pages-goods-member_checkout-index','iconfont.woff2']) assert(Object.keys(publicResult.affectedAssets).some(file => file.includes(name)), 'Missing affected asset: ' + name);`],
  ["} else throw Error('Use pack, deploy, verify or rollback');",`} else if (mode === 'inspect') {
    const snapshot = inspect(instance, Object.keys(previous.files));
    for (const [file,hash] of Object.entries(previous.files)) assert(snapshot.memberFiles[file] === hash, 'Current member baseline differs: ' + file);
    save(instance + '-before.json',snapshot);
    console.log(JSON.stringify({instance,baseline:previous.commit,files:Object.keys(snapshot.memberFiles).length,latestUpgrade:snapshot.latestUpgrade,database:snapshot.database}));
  } else throw Error('Use inspect, pack, deploy, verify or rollback');`]
]) {
  if (!source.includes(from)) throw Error('Release adapter hook changed: '+from);
  source=source.split(from).join(to);
}
const runtime=new Module(original,module);runtime.filename=original;runtime.paths=module.paths;runtime._compile(source,original);
