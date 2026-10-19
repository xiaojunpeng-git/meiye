// A presentation-only patch must not alter saved configuration or requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { root, deps, component, source } = require('./stacked-harness.cjs');
const compiler = require(path.join(deps, 'vue-template-compiler'));
const paths = ['前端代码/admin/src/components/mobilePage/home_staff_list.vue','前端代码/uniapp/pages/index/components/staffList.vue'];
for (const file of paths) {
  const current = compiler.parseComponent(fs.readFileSync(path.join(root,file),'utf8'));
  const prior = compiler.parseComponent(execFileSync('git',['show','3faea328d6077e9eca7d52805464c826fc3051a7:'+file],{cwd:root,encoding:'utf8'}));
  assert.equal(current.script.content,prior.script.content,'Business methods/defaults changed');
  assert.equal(current.template.content,prior.template.content,'Existing fields/navigation changed');
  assert(current.styles[0].content.includes('.staff-module__list--0 .staff-module__card { flex: 0 0 '));
  // Remove only this documented rail block: all prior style rules must survive.
  const stripped=current.styles[0].content.replace(/\s*\/\* (?:Only the horizontal rail|Stack only horizontal cards)[\s\S]*?\*\/\s*\.staff-module__list--0 \.staff-module__card[^\n]*\n\s*\.staff-module__list--0 \.staff-module__info[^\n]*\n\s*\.staff-module__list--0 \.staff-module__button[^\n]*/, '');
  assert.equal(stripped.replace(/\s/g,''),prior.styles[0].content.replace(/\s/g,''),'Unrelated layout style changed');
}
for(const key of ['preview','member']) component(key);
const fixture=source();
assert.equal(fixture.people.length,6);
assert.deepEqual(fixture.config.checkboxInfo.type,[0,1,2,3]);
console.log('PASS: actual Vue2 templates/styles compile; scripts, requests, selection, defaults and non-horizontal style rules exactly preserved.');
