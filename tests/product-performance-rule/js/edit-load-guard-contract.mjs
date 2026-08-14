import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const sourcePath = path.join(root, '前端代码/admin/src/pages/product/productAdd/index.vue');
const source = fs.readFileSync(sourcePath, 'utf8');

const checks = [
  [
    'only reservation projects request the performance rule after product detail loads',
    /this\.infoData\(data\);\s*if \(Number\(data\.product_type\) === 6\) \{\s*this\.loadPerformanceRule\(data\.id \|\| this\.\$route\.params\.id\);\s*\}/s.test(source),
  ],
  [
    'performance-rule loader rejects non-reservation product types before the request',
    /loadPerformanceRule\(id\) \{\s*if \(!id \|\| Number\(this\.formData\.product_type\) !== 6\) return;/s.test(source),
  ],
];

let failed = 0;
for (const [name, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`);
  if (!passed) failed += 1;
}

process.exit(failed === 0 ? 0 : 1);
