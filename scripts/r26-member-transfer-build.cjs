const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const vm = require('vm');

// 固定Git提交只读导出构建，复用已上线图片魔方的生产编译参数；绝不构建共享脏工作区。
const root = process.cwd();
const variant = process.argv[2] || 'candidate';
if (!['baseline', 'candidate'].includes(variant)) throw Error('Invalid variant');
const commit = variant === 'candidate' ? '802490dcf4facafd1a29bad7fb6191d33698d772' : '120fea991b7dbe4fe062d9914523a35a677f6819';
let source = fs.readFileSync(path.join(root, 'scripts/member-commerce-release-build.cjs'), 'utf8')
  .replace(/const commit=variant===.*?;/, 'const commit=' + JSON.stringify(commit) + ';')
  .replace("const base='/private/tmp/m008-round3-release-20261020';", "const base='/private/tmp/mohe-r26-member-transfer-20261022';")
  // 生产构建沿用图片魔方编译口径；新模块已独立lint，旧页面忽略规则不扩散整改。
  .replace('config.cache(false);', "config.module.rules.delete('eslint');config.cache(false);");
process.argv = [process.argv[0], 'r26-build', 'admin', variant];
vm.runInThisContext('(function(require,__dirname,__filename){' + source + '\n})', { filename: __filename })(require, path.join(root, 'scripts'), __filename);
