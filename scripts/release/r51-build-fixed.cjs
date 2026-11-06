// Build immutable exports of a canonical Git tree; never edit the exported source.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const evidence = path.resolve(root, '../任务管理/验收证据/R51-库存发布-2026-11-06');
const [tree, phase] = process.argv.slice(2);
if (!/^[a-f0-9]{40}$/.test(tree) || !/^[a-z0-9-]+$/.test(phase)) throw Error('Explicit fixed tree and phase required');
const git = args => cp.execFileSync('git', args, {cwd: root, maxBuffer: 128 * 1024 * 1024});
const hash = v => crypto.createHash('sha256').update(v).digest('hex');
const base = '/private/tmp/mohe-r51-fixed-' + tree.slice(0, 12);
const input = path.join(base, 'readonly-source');
const packages = ['前端代码/cashier-v3','前端代码/inventory-vue3','前端代码/shared','tests/cashier-v3','tests/inventory'];
if (!fs.existsSync(input)) {
  fs.mkdirSync(input, {recursive:true});
  cp.execFileSync('tar', ['-xf','-','-C',input], {input:git(['archive',tree,...packages])});
  for (const project of ['cashier-v3','inventory-vue3']) fs.symlinkSync(path.join(root,'前端代码',project,'node_modules'),path.join(input,'前端代码',project,'node_modules'));
  const freeze = dir => { for (const e of fs.readdirSync(dir,{withFileTypes:true})) { if(e.isSymbolicLink()) continue; const f=path.join(dir,e.name); if(e.isDirectory()) freeze(f); else fs.chmodSync(f,0o444); } fs.chmodSync(dir,0o555); };
  freeze(input);
}
const files=git(['ls-tree','-rz','--name-only',tree,'--',...packages]).toString().split('\0').filter(Boolean);
const check=()=>{for(const f of files) if(!fs.readFileSync(path.join(input,f)).equals(git(['show',tree+':'+f]))) throw Error('Export changed '+f);};
check();
const builds={};
for(const project of ['inventory-vue3','cashier-v3']) {
  const cwd=path.join(input,'前端代码',project), output=path.join(base,project+'-dist');
  // Vite writes a temporary config sibling; tracked source remains read-only.
  fs.chmodSync(cwd,0o755);
  try {cp.execFileSync(process.execPath,[path.join(root,'前端代码',project,'node_modules/vite/bin/vite.js'),'build','--outDir',output],{cwd,stdio:'inherit',env:{...process.env,NODE_ENV:'production'}});} finally {fs.chmodSync(cwd,0o555);}
  const assets={}; const scan=dir=>{for(const e of fs.readdirSync(dir,{withFileTypes:true})){const f=path.join(dir,e.name);if(e.isDirectory())scan(f);else assets[path.relative(output,f)]=hash(fs.readFileSync(f));}};scan(output);
  builds[project]={output,assets};
}
check();
fs.writeFileSync(path.join(evidence,phase+'-build.json'),JSON.stringify({tree,input,builds,result:'PASS'},null,2));
console.log('FIXED_BUILD_PASS',tree);
