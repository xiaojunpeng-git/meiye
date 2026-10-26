import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

// Local Docker mirror only. Compare the new shared calculation with the exact
// accepted pre-round reader in memory; never connect to customer servers.
const directory = 'tests/report-group-dashboard/evidence/r93-2026-10-19';
const baseline = JSON.parse(readFileSync(`${directory}/baseline.json`, 'utf8')).head;
const source = execFileSync('git', ['show', `${baseline}:后端代码/app/services/query/metric/RegisteredMetricReadServices.php`], { encoding: 'utf8' })
  .replace('final class RegisteredMetricReadServices', 'final class SaleTypesBaselineMetricReader');
const test = readFileSync('tests/report-group-dashboard/php/category-sale-types.php', 'utf8')
  .replace('// BASELINE_INJECTION', `eval(substr(base64_decode('${Buffer.from(source).toString('base64')}'), 5));`);
const result = JSON.parse(execFileSync('docker', ['exec', '-i', 'mohe-cashier-app', 'php'], { input: test, encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }));
mkdirSync(directory, { recursive: true });
writeFileSync(`${directory}/business-test.json`, JSON.stringify({ baseline, ...result }, null, 2) + '\n');
console.log(JSON.stringify(result));
