// Validate the fixed release export, never the shared dirty worktree. Only test
// file reads are redirected; the component under test is the accepted production code.
const fs=require('fs'),path=require('path'),Module=require('module');
const root=path.resolve(__dirname,'..'),admin='/private/tmp/r15-unified-admin-release/candidate-readonly',member='/private/tmp/r15-unified-release-008-mp-weixin-retry2/readonly';
const read=fs.readFileSync.bind(fs);fs.readFileSync=(file,...args)=>read(typeof file==='string'?file.replace(admin+'/前端代码/uniapp',member+'/前端代码/uniapp'):file,...args);
const original=path.join(root,'tests/r15-menu-preview/browser.cjs');let code=read(original,'utf8').replace("path.resolve(__dirname,'../..')",JSON.stringify(admin)).replace("path.join(__dirname,'evidence')",JSON.stringify(path.join(root,'tests/r15-release')));
const m=new Module(original,module);m.filename=original;m.paths=Module._nodeModulePaths(path.dirname(original));m._compile(code,original);
