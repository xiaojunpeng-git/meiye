/* 去掉本轮入口增量后，核对旧路由/工作台/岗位目录仍与开工时逐字一致。 */
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto'), assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const baseline = JSON.parse(fs.readFileSync(path.resolve(root, '../任务管理/进行中/美业大学成长管理/证据/开工基线.json')));
const transforms = {
  '前端代码/admin/src/router/routes.js': s => s.replace("import growth from './modules/growth';\n", '').replace('  growth,\n', ''),
  '前端代码/mobile-vue3/src/merchant/pages/workbench/index.uvue': s => s.split('\n').filter(line => !line.includes("merchantActionIsAllowed('mobile.merchant.growth')")).join('\n'),
  '后端代码/app/services/mobile/merchant/MobileMerchantCapabilityCatalog.php': s => s.replace('    /** 成长入口独立授权；不从历史工作台授权自动继承。 */\n    public const RULE_GROWTH = 401030;\n', '').split('\n').filter(line => !line.includes("['id' => self::RULE_GROWTH")).join('\n')
};
const passed = [];
for (const [file, transform] of Object.entries(transforms)) {
  const original = baseline.shared_files.find(r => r.path === file);
  const sha = crypto.createHash('sha256').update(transform(fs.readFileSync(path.join(root, file), 'utf8'))).digest('hex');
  assert.equal(sha, original.sha256, file + ' 存在成长增量以外的新变更，须重新复核'); passed.push(file + '原有内容保留');
}
console.log(JSON.stringify({ passed }, null, 2));
