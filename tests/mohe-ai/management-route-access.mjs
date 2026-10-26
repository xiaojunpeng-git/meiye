import assert from 'node:assert/strict';
import { managementRouteAccess } from '../../前端代码/shared/mohe-ai/management-route-access.mjs';

assert.deepEqual(
  await managementRouteAccess(async () => ({ revision: 1 })),
  { allowed: true, serverVerified: true }
);
assert.deepEqual(
  await managementRouteAccess(async () => { const error = new Error('denied'); error.reason = 'AI_PERMISSION_DENIED'; throw error; }),
  { allowed: false, serverVerified: false }
);
assert.deepEqual(
  await managementRouteAccess(async () => { const error = new Error('not ready'); error.reason = 'AI_MANAGEMENT_NOT_READY'; throw error; }),
  { allowed: true, serverVerified: false }
);
assert.deepEqual(
  await managementRouteAccess(async () => { throw new Error('network'); }),
  { allowed: true, serverVerified: false }
);
console.log('Management route access: PASS');
