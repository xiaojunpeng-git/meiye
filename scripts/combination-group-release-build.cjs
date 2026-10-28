// Reuse the canonical immutable-tree production builder; no editable source copies and no remote side effects.
const fs=require('fs'),path=require('path'),Module=require('module');
let source=fs.readFileSync(path.join(__dirname,'r32-report-scroll-build.cjs'),'utf8');
source=source.replace("const base = '/private/tmp/mohe-r32-report-scroll-release';", "const base = '/private/tmp/combination-group-release-20261027';")
    .replace("const release = path.join(root, 'tests/cashier-v3/evidence/R32-report-scroll/release');", "const release = path.join(root, 'tests/combination-group-list/release');");
const child=new Module(__filename,module);child.filename=__filename;child.paths=module.paths;child._compile(source,__filename);
