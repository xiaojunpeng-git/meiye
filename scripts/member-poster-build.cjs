/** Reuse the established fixed-tree compiler; this task only changes its manifest/output locations. */
const fs=require('fs'),path=require('path'),Module=require('module');
const source=path.resolve(__dirname,'release/r44/build-member.cjs');
const code=fs.readFileSync(source,'utf8')
 .replace("'scripts/build/member-mini-css-dedupe.cjs'];", "'scripts/build/member-mini-css-dedupe.cjs', 'scripts/build/member-mini-promotion-assets.cjs'];")
 .replace("tree + ':前端代码/uniapp/config/app.mp.template.js'", "tree + ':前端代码/uniapp/config/' + (platform === 'h5' ? 'app.h5.template.js' : 'app.mp.template.js')")
 .split('../任务管理/进行中/第44轮-拼团大屏与推广/发布核对').join('../_local_evidence/2026-11-05-round15-member-poster')
 .split('/private/tmp/r44-member-release-').join('/private/tmp/round15-poster-');
const runner=new Module(source,module);runner.filename=source;runner.paths=Module._nodeModulePaths(path.dirname(source));runner._compile(code,source);
