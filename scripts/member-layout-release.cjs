const fs = require('fs');
const path = require('path');
const Module = require('module');

// The static installer retains per-instance backups, full live-baseline guards,
// protected frontend fingerprints and the required RH -> push -> 008 order.
const original = path.resolve('scripts/member-background-release.cjs');
let source = fs.readFileSync(original, 'utf8');
const replacements = [
  ['25c115271e945bdb8f2a46a8bcbb85f8b7137f6f', 'd728656bcda854555f905dd95d896b902f601fdf'],
  ['/private/tmp/m008-background-release-20261021', '/private/tmp/m008-layout-release-20261021'],
  ["const previousManifest = '/private/tmp/m008-round6-release-20261020/payload/manifest.json';", "const previousManifest = '/private/tmp/m008-background-release-20261021/payload/manifest.json';"],
  ['release/member-background-r8-20261021', 'release/member-layout-r8-20261021'],
  ["const previous = load(previousManifest);", "const previous = load(previousManifest); previous.files = previous.allFiles;"],
  ['M008-R8-BG-001', 'M008-R8-LAYOUT-001'],
  ["acceptedPatch: '929a185f'", "acceptedPatch: 'cea4387a'"],
  ['20261021-member-background-25c11527', '20261021-member-layout-d728656b'],
  ['.m008-bg-next', '.m008-layout-next'],
  ['?bg=25c11527', '?layout=d728656b'],
  ["} else throw Error('Use pack, deploy, verify or rollback');", `} else if (mode === 'inspect') {
    const snapshot = inspect(instance, Object.keys(previous.files));
    for (const [file, hash] of Object.entries(previous.files)) assert(snapshot.memberFiles[file] === hash, 'Current member baseline differs: ' + file);
    save(instance + '-before.json', snapshot);
    console.log(JSON.stringify({instance, baseline: previous.commit, files: Object.keys(snapshot.memberFiles).length, latestUpgrade: snapshot.latestUpgrade, database: snapshot.database}));
  } else throw Error('Use inspect, pack, deploy, verify or rollback');`]
];
for (const [from, to] of replacements) {
  if (!source.includes(from)) throw Error('Release adapter hook changed: ' + from);
  source = source.split(from).join(to);
}
const runtime = new Module(original, module);
runtime.filename = original;
runtime.paths = module.paths;
runtime._compile(source, original);
