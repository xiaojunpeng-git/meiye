// Freeze only this follow-up's files after local checks and reconstructing the deployed platform baseline.
const fs = require('fs'), path = require('path'), crypto = require('crypto'), cp = require('child_process');
const root = path.resolve(__dirname, '../..'), evidence = path.join(root, 'tests/customer-010/evidence/round12-survey-miniprogram');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const read = name => JSON.parse(fs.readFileSync(path.join(evidence, name)));
const groups = {
  frontend: ['前端代码/admin/src/pages/user/survey/index.vue', '前端代码/uniapp/App.vue', '前端代码/uniapp/api/memberSurvey.js', '前端代码/uniapp/libs/login.js', '前端代码/uniapp/pages/users/survey/index.vue', '前端代码/uniapp/utils/memberPhoneGate.js', '前端代码/uniapp/utils/memberSurveyScene.js'],
  backend: ['后端代码/app/controller/api/v1/user/MemberSurvey.php', '后端代码/app/services/user/MemberSurveyServices.php', '后端代码/app/services/user/MemberSurveyMiniProgramCodeServices.php', '后端代码/app/services/user/UserBelongStoreServices.php', '后端代码/config/member_survey.php'],
  preserved_baseline: ['前端代码/admin/src/pages/setting/staff/add.vue', '前端代码/admin/src/pages/setting/staff/index.vue', '前端代码/uniapp/pages/discover/discoverIndex/index.vue', '前端代码/uniapp/pages/users/user_card/index.vue', '前端代码/uniapp/pages/users/user_card_list/index.vue'],
  tests: ['tests/member-survey/js/browser.cjs', 'tests/member-survey/js/h5-runtime.cjs', 'tests/member-survey/js/scene.cjs', 'tests/member-survey/js/phone-gate.cjs', 'tests/member-survey/js/mini-login.cjs', 'tests/member-survey/js/platform-baseline.cjs', 'tests/member-survey/php/local-business.php', 'tests/member-entitlement-display/model.cjs'],
  scripts: ['scripts/member-survey/build-admin-local.cjs', 'scripts/member-survey/build-local.cjs', 'scripts/member-survey/test-local.cjs', 'scripts/member-survey/prepare-mini-experience.cjs', 'scripts/member-survey/fix-mini-candidate.cjs'],
  migrations: [],
};
const removed = ['tests/member-survey/js/decode-qr.m'];
const files = Object.entries(groups).flatMap(([group, paths]) => paths.map(p => ({ group, path: p, sha256: hash(fs.readFileSync(path.join(root, p))) })));
for (const p of removed) if (fs.existsSync(path.join(root, p))) throw Error('Obsolete H5 QR decoder still exists');
const checks = {};
for (const name of ['business', 'browser', 'scene', 'phone-gate', 'mini-login', 'platform-baseline', 'member-baseline', 'h5-runtime', 'wechat-code-api', 'native-validation']) {
  const d = read(name + '.json'); if (!d.passed) throw Error('Failed evidence: ' + name);
  checks[name] = { passed: true, count: Array.isArray(d.checks) ? d.checks.length : d.checks || 1, evidence_sha256: hash(fs.readFileSync(path.join(evidence, name + '.json'))) };
}
const builds = {};
for (const name of ['admin-build', 'member-build-h5-010', 'member-build-mp-weixin-010']) {
  const d = read(name + '.json'); if (!d.passed) throw Error('Build failed');
  for (const [p, h] of Object.entries(d.source_inputs)) if (hash(fs.readFileSync(path.join(d.source, p))) !== h) throw Error('Build source changed: ' + p);
  const assets = {};
  function walk(dir) { for (const entry of fs.readdirSync(dir, { withFileTypes: true })) { const p = path.join(dir, entry.name); if (entry.isDirectory()) walk(p); else if (entry.isFile() && !p.endsWith('/project.private.config.json')) assets[path.relative(d.output, p)] = hash(fs.readFileSync(p)); } }
  walk(d.output); builds[name] = { ...d, files: Object.keys(assets).length, assets_sha256: hash(JSON.stringify(assets)) };
  fs.writeFileSync(path.join(evidence, name + '-assets.json'), JSON.stringify(assets, null, 2));
}
const live = read('010-domain-followup-baseline.json'), baseline = read('baseline-admin/admin-build.json');
const sourceBaseline = read('platform-source-baseline.json');
if (!sourceBaseline.passed) throw Error('Accepted platform source baseline not preserved');
// 现网由只读Git导出目录构建，模块命名受编译上下文影响；不把不同目录的重建哈希冒充相同。
// 先用原验收资源证明现网，再核对全部源码输入；在同一上下文中只允许问卷分块和入口映射变化。
const acceptedBuild = JSON.parse(fs.readFileSync(sourceBaseline.accepted_R22_proof));
let liveMatches = 0, matches = 0; const changed = [];
for (const [p, h] of Object.entries(acceptedBuild.assets)) {
  if (!(p === 'system.html' || (p.startsWith('view_admin/') && /\.(js|css)$/.test(p)))) continue;
  if (live.frontend_assets['public/' + p] !== h) throw Error('Accepted platform/live mismatch: ' + p);
  liveMatches++;
}
function compare(dir) { for (const f of fs.readdirSync(dir, { withFileTypes: true })) { const p = path.join(dir, f.name); if (f.isDirectory()) compare(p); else if (/\.(js|css)$/.test(f.name)) {
  const rel = path.relative(baseline.output, p), next = path.join(builds['admin-build'].output, rel);
  if (fs.existsSync(next) && hash(fs.readFileSync(p)) === hash(fs.readFileSync(next))) matches++; else changed.push(rel);
} } }
compare(path.join(baseline.output, 'view_admin'));
if (changed.length !== 3 || !changed.every(p => /\/app\.[^/]+\.js$/.test(p) || /\/chunk-02dc75a8\./.test(p))) throw Error('Unexpected non-survey build changes');
const base = cp.execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root }).toString().trim();
const fingerprint = hash(JSON.stringify({ base, files, removed, builds, checks }));
const result = { task: '010-R12-SURVEY-MINI', base_head: base, fingerprint, files, removed, checks, builds,
  preserved_baseline: { source_commit: '82894c184b9d9120d43e8e91a97f49d6f474953c', member_source_commit: read('member-baseline.json').source_commit, accepted_R22_live_asset_matches: liveMatches, rebuilt_unchanged_asset_matches: matches, changed_projection_assets: changed, backend_matches_head: read('member-baseline.json').head_file_matches, admin_source_inputs_matched: sourceBaseline.matched_admin_inputs, different_build_context_fingerprints_disclosed: true },
  scope: ['任务专属小程序码', '提交前手机号授权', '未归属会员主动选店，已有归属不改', '首次归属与答卷同事务', '奖励继续使用010现有服务'],
  delivery_target: { member_appid: 'wxce4f61fc9c4eaaa6', trial_version: '3.4.1', api_origin: 'https://010.cc3798.com', instance: '010', production_007_connection_changes: false },
  runtime_configuration: { instance: '010', section: 'MEMBER_SURVEY', MINI_ENV_VERSION: 'trial', credentials_changes: false },
  native_validation: read('native-validation.json'),
  limitations: ['010合法域名及原生联网已验证；新代码部署后的真实提交联调尚未执行', '未提交、未部署、未上传；需固定版本产品经理补验', '未创建或发布线上测试任务，010已有草稿保留', '此前本地ruihao开发库事件仍未恢复，本轮测试只用隔离合成库'],
  cleanup: ['删除已失效的普通H5二维码解码测试', '删除管理页普通二维码生成分支及依赖导入', '删除已无调用的member_h5_base_url配置，其他模块仍使用的qrcodejs2依赖保留'],
  comments: groups.frontend.concat(groups.backend),
};
fs.writeFileSync(path.join(evidence, 'fixed-candidate.json'), JSON.stringify(result, null, 2));
console.log(JSON.stringify({ fingerprint, source_files: files.length, removed, accepted_live_assets: liveMatches, unchanged_projection_assets: matches, native_status: result.native_validation.status }));
