// Local preparation only: retain canonical Git ancestry, isolate accepted paths,
// and inspect explicitly authorized instances without modifying their files/data.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), zlib = require('zlib');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const dir = 'tests/cashier-v3/evidence/R55-release'; fs.mkdirSync(dir, { recursive: true });
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const git = (args, input, env = process.env) => cp.execFileSync('git', args, { input, env, maxBuffer: 128 * 1024 * 1024 });
const save = (name, data) => fs.writeFileSync(path.join(dir, name), JSON.stringify(data, null, 2) + '\n', { flag: 'wx' });
const fixed = JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R55-member-filters/fixed-files.json'));
for (const [file, digest] of Object.entries(fixed.files)) if (sha(fs.readFileSync(file)) !== digest) throw Error('Accepted source changed: ' + file);
if (process.argv[2] === 'probe') {
  for (const site of ['rh', '008', '010']) {
    const source = fs.readFileSync('scripts/r28-permissions-inspect.php', 'utf8').replace('<?php', '<?php ob_start();') + '\necho gzencode(ob_get_clean());';
    const result = JSON.parse(zlib.gunzipSync(cp.execFileSync('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', site === 'rh' ? 'rh-server' : 'mohe-server', '/www/server/php/74/bin/php -- ' + site], { input: source, maxBuffer: 32 * 1024 * 1024 })).toString());
    if (result.site !== site) throw Error('Instance mismatch');
    save(site + '-before.json', result);
    console.log(site, result.frontends.filter(f => f.kind === 'cashier' && f.root === result.root).map(f => Object.keys(f.assets)));
  }
} else if (process.argv[2] === 'prepare') {
  const baselineCommit = git(['rev-parse', 'a0ff04b6']).toString().trim();
  const baselineTree = git(['rev-parse', baselineCommit + '^{tree}']).toString().trim();
  const captured = JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R55-member-filters/baseline.json'));
  // All existing business files must exactly match the captured pre-development
  // baseline. Otherwise an isolated patch needs review, not a silent merge.
  for (const [file, row] of Object.entries(captured.files).filter(([f]) => f.startsWith('前端代码/'))) {
    if (git(['rev-parse', baselineCommit + ':' + file]).toString().trim() !== row.blob) throw Error('Shared baseline differs: ' + file);
  }
  const index = '/private/tmp/mohe-r55-release-' + process.pid + '.index';
  const sharedIndex = git(['rev-parse', '--git-path', 'index']).toString().trim(), indexDigest = sha(fs.readFileSync(sharedIndex));
  const env = { ...process.env, GIT_INDEX_FILE: index };
  try {
    git(['read-tree', baselineCommit], undefined, env);
    for (const file of Object.keys(fixed.files)) {
      const blob = git(['hash-object', '-w', '--stdin'], fs.readFileSync(file)).toString().trim();
      git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
    }
    const candidateTree = git(['write-tree'], undefined, env).toString().trim();
    git(['diff', '--check', baselineTree, candidateTree]);
    const paths = git(['-c', 'core.quotepath=false', 'diff', '--name-only', baselineTree, candidateTree]).toString().trim().split('\n');
    if (paths.length !== 10 || paths.some(f => !Object.hasOwn(fixed.files, f))) throw Error('Scoped file boundary mismatch');
    const patch = git(['diff', baselineTree, candidateTree]); fs.writeFileSync(path.join(dir, 'r55-only.patch'), patch, { flag: 'wx' });
    if (sha(fs.readFileSync(sharedIndex)) !== indexDigest) throw Error('Shared index changed');
    save('trees.json', { canonicalGit: root, head: git(['rev-parse', 'HEAD']).toString().trim(), baselineCommit, baselineTree, candidateTree, paths,
      acceptedFiles: fixed.files, sharedIndexBefore: indexDigest, patchSHA256: sha(patch), sharedIndexUntouched: true,
      authorization: { sites: ['rh', '008', '010'], rhFormalOnly: true, retiredPreviewMustRemain410: true, databaseMigrations: false, platformStaticUnchanged: true },
      excluded: ['all unrelated dirty workspace changes', 'member multi-person selection', 'other unaccepted cashier changes'], supplementalAcceptanceRequired: true });
    console.log('R55_CANDIDATE', candidateTree);
  } finally { if (fs.existsSync(index)) fs.unlinkSync(index); }
} else throw Error('probe|prepare required');
