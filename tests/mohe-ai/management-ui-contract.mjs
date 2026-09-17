import fs from 'node:fs';

const sources = [
  fs.readFileSync(new URL('../../前端代码/admin/src/pages/setting/moheAi/index.vue', import.meta.url), 'utf8'),
  fs.readFileSync(new URL('../../前端代码/admin/src/pages/setting/moheAi/skillDetail.vue', import.meta.url), 'utf8')
];
for (const source of sources) for (const stale of ['scene.goal', 'scene.examples', 'setExamples(']) {
  if (source.includes(stale)) throw new Error(`stale management scene field: ${stale}`);
}
if (!sources[0].includes('业务语义来自版本化 Skill 原文') || !sources[1].includes('业务语义、边界和引导原则只来自')) throw new Error('management page must explain the source-owned Skill boundary');
for (const required of ['完整答案等待', 'answerTiming()', 'completionRate()', 'answerTimingText()', 'latency_cohorts.async.browser_observed', 'modelStages()', 'technicalReasons()', 'diagnosticPredicates()', '模型阶段耗时（近 24 小时聚合）', '技术失败原因（近 24 小时聚合）', '模型协议诊断（近 24 小时聚合）', 'formatMs(value)']) {
  if (!sources[0].includes(required)) throw new Error(`management page must expose honest answer-latency evidence: ${required}`);
}
for (const forbidden of ['提速达标', '已达到目标']) {
  if (sources[0].includes(forbidden)) throw new Error(`management page must not claim unverified latency target: ${forbidden}`);
}
console.log('Management UI scene contract: PASS');
