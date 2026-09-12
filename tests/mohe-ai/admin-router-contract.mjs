import fs from 'node:fs';

const source = fs.readFileSync(
  new URL('../../前端代码/admin/src/router/index.js', import.meta.url),
  'utf8'
);

for (const required of [
  "import moheAiRequest from '@/api/moheAi';",
  "import { managementRouteAccess } from '../../../shared/mohe-ai/management-route-access.mjs';",
  'const decision = await managementRouteAccess(moheAiRequest);',
  "if (!decision.allowed) return next({ name: '403' });",
  "if (decision.serverVerified) store.commit('admin/menu/setMoheAiVerifiedUser', userInfo);",
  "await store.dispatch('admin/menus/getMenusNavList');",
  'A menu refresh is a presentation update.'
]) {
  if (!source.includes(required)) throw new Error(`missing management navigation guard: ${required}`);
}

console.log('Admin router management navigation contract: PASS');
