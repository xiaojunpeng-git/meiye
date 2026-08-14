import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const config = fs.readFileSync(path.join(root, '前端代码/admin/vue.config.js'), 'utf8');
const startScript = fs.readFileSync(path.join(root, 'scripts/start-local.sh'), 'utf8');

const checks = [
  [
    'admin dev server publishes the browser-visible HMR address for webpack-dev-server 2.x',
    config.includes("public: process.env.VUE_APP_ADMIN_DEV_PUBLIC || undefined")
      && config.includes("sockHost: process.env.VUE_APP_ADMIN_DEV_SOCKET_HOST || undefined")
      && config.includes("sockPort: Number(process.env.VUE_APP_ADMIN_DEV_SOCKET_PORT) || undefined"),
  ],
  [
    'local platform preview advertises the host-mapped 18081 HMR endpoint',
    startScript.includes("VUE_APP_ADMIN_DEV_PUBLIC='127.0.0.1:18081'")
      && startScript.includes("VUE_APP_ADMIN_DEV_SOCKET_HOST='127.0.0.1'")
      && startScript.includes("VUE_APP_ADMIN_DEV_SOCKET_PORT='18081'"),
  ],
];

let failed = 0;
for (const [name, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`);
  if (!passed) failed += 1;
}

process.exit(failed === 0 ? 0 : 1);
