// Reuse the established immutable Git export pipeline; outputs never become editable source.
const fs=require('fs'),path=require('path'),Module=require('module');
const original=path.resolve('scripts/r32-report-scroll-build.cjs');let source=fs.readFileSync(original,'utf8');
for(const[a,b]of[["const base = '/private/tmp/mohe-r32-report-scroll-release';","const base = '/private/tmp/platform-member-release-20261028';"],["const release = path.join(root, 'tests/cashier-v3/evidence/R32-report-scroll/release');","const release = path.join(root, 'tests/platform-member-login/release');"]]){if(source.split(a).length!==2)throw Error('Build pipeline drift');source=source.replace(a,b);}
const child=new Module(original,module);child.filename=original;child.paths=Module._nodeModulePaths(path.dirname(original));child._compile(source,original);
