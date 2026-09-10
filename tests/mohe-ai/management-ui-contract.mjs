import fs from 'node:fs';

const sources = [
  fs.readFileSync(new URL('../../前端代码/admin/src/pages/setting/moheAi/index.vue', import.meta.url), 'utf8'),
  fs.readFileSync(new URL('../../前端代码/admin/src/pages/setting/moheAi/skillDetail.vue', import.meta.url), 'utf8')
];
for (const source of sources) for (const stale of ['scene.goal', 'scene.examples', 'setExamples(']) {
  if (source.includes(stale)) throw new Error(`stale management scene field: ${stale}`);
}
if (!sources[0].includes('业务语义来自版本化 Skill 原文') || !sources[1].includes('业务语义、边界和引导原则只来自')) throw new Error('management page must explain the source-owned Skill boundary');
console.log('Management UI scene contract: PASS');
