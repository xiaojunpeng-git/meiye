/** 本轮复用固定 Git 树只读会员构建器；仅替换清单/证据路径，保留客户隔离和输入全量指纹校验。 */
const fs = require('fs');
const path = require('path');
const Module = require('module');
const source = path.resolve(__dirname, '../r44/build-member.cjs');
const code = fs.readFileSync(source, 'utf8')
  .replace("'scripts/build/member-mini-css-dedupe.cjs'];", "'scripts/build/member-mini-css-dedupe.cjs', 'scripts/build/member-mini-promotion-assets.cjs'];")
  // H5沿用当前热更新入口的同域配置；小程序仍显式写入各客户的接口域名。
  .replace("tree + ':前端代码/uniapp/config/app.mp.template.js'", "tree + ':前端代码/uniapp/config/' + (platform === 'h5' ? 'app.h5.template.js' : 'app.mp.template.js')")
  .split('../任务管理/进行中/第44轮-拼团大屏与推广/发布核对')
  .join('../任务管理/验收证据/R43-会员门店导航与电话-2026-11-04/release')
  .split('/private/tmp/r44-member-release-').join('/private/tmp/r43-member-release-');
const runner = new Module(source, module);
runner.filename = source;
runner.paths = Module._nodeModulePaths(path.dirname(source));
runner._compile(code, source);
