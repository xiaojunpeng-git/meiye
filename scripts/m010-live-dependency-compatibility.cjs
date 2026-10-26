/* Read-only canonical Git fixtures plus isolated synthetic DB: never touch a customer instance. */
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const repo = path.resolve(__dirname, '..');
const candidate = path.join(process.env.HOME, '.codex/tmp/m010-custom-card-candidate');
const fixture = path.join(process.env.HOME, '.codex/tmp/m010-live-dependency-fixtures');
const evidence = path.join(repo, 'tests/cashier-v3/evidence/m010-custom-card-2026-10-22');
const run = (cmd, args, options = {}) => cp.execFileSync(cmd, args, {cwd: repo, stdio: ['pipe', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024, ...options});
const docker = (args, options = {}) => run('docker', args, options);
const network = 'm010-compat-network', mysql = 'm010-compat-mysql', redis = 'm010-compat-redis';
const fixtures = [
    ['92a02ef16c4802a05e170c32d44c45e208de9d8c', 'app/services/cashier/v3/CashierV3CommandGatewayServices.php'],
    ['30582fd009384a1a8aa88d7954d9d2ae06015bd4', 'app/services/cashier/v3/card/CashierV3CardOperationKernel.php'],
    ['ad030461c85e63b471dc7e39b437855f4611e9d0', 'app/services/cashier/v3/cashier/CashierV3EntitlementResourceVersionProvider.php'],
];
fs.mkdirSync(fixture, {recursive: true, mode: 0o700});
const mounts = [];
// The deployed integer guard must be tested with its accepted test, not the older cent-permitting fixture.
const operationTest = path.join(fixture, 'card-operation-kernel-contract.php');
fs.writeFileSync(operationTest, run('git', ['show', '30582fd009384a1a8aa88d7954d9d2ae06015bd4:tests/cashier-v3/php/card-operation-kernel-contract.php']));
mounts.push('-v', `${operationTest}:/workspace/tests/cashier-v3/php/card-operation-kernel-contract.php:ro`);
for (const [commit, relative] of fixtures) {
    const dest = path.join(fixture, path.basename(relative));
    fs.writeFileSync(dest, run('git', ['show', `${commit}:后端代码/${relative}`]));
    mounts.push('-v', `${dest}:/var/www/html/${relative}:ro`, '-v', `${dest}:/workspace/后端代码/${relative}:ro`);
}
if (fs.existsSync(path.join(candidate, '后端代码/.env'))) throw Error('Unexpected candidate environment');
try {
    docker(['network', 'create', '--internal', network]);
    docker(['run', '-d', '--platform', 'linux/amd64', '--name', mysql, '--network', network, '--network-alias', 'mysql', '-e', 'MYSQL_INITDB_SKIP_TZINFO=1', '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '-e', 'MYSQL_DATABASE=m010_custom_card', 'docker.m.daocloud.io/library/mysql:5.7']);
    docker(['run', '-d', '--name', redis, '--network', network, '--network-alias', 'redis', 'docker.m.daocloud.io/library/redis:7-alpine']);
    let ready = false;
    for (let i = 0; i < 120; i++) {
        try { docker(['exec', mysql, 'mysql', '--protocol=TCP', '-h127.0.0.1', '-uroot', '-e', 'SELECT 1', 'm010_custom_card']); ready = true; break; }
        catch { Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 1000); }
    }
    if (!ready) throw Error('Isolated MySQL startup failed');
    const schema = docker(['exec', 'mohe-mysql', 'sh', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --no-data --routines --skip-comments --set-gtid-purged=OFF ruihao']);
    docker(['exec', '-i', mysql, 'mysql', '-uroot', 'm010_custom_card'], {input: schema});
    fs.copyFileSync(path.join(repo, '后端代码/.env'), path.join(candidate, '后端代码/.env'));
    fs.chmodSync(path.join(candidate, '后端代码/.env'), 0o600);
    for (const name of ['custom-card-configuration-contract.php', 'card-purchase-issuance-contract.php', 'c2-checkout-settlement-contract.php', 'card-operation-kernel-contract.php', 'm010-custom-card-template-local.php']) {
        const args = ['run', '--rm', '--entrypoint', 'php', '--network', network,
            '-v', `${candidate}/后端代码:/var/www/html:ro`, '-v', `${candidate}:/workspace:ro`,
            '-v', `${repo}/后端代码/vendor:/var/www/html/vendor:ro`, '-v', `${repo}/后端代码/vendor:/workspace/后端代码/vendor:ro`,
            ...mounts, '--tmpfs', '/var/www/html/runtime', '-e', 'M010_LOCAL_TEST=1', '-e', 'M010_ISOLATED_DB=1',
            name === 'm010-custom-card-template-local.php' ? 'mohe-app:local' : 'docker.m.daocloud.io/library/php:8.2-cli',
            `/workspace/tests/cashier-v3/php/${name}`];
        let output;
        try { output = docker(args); }
        catch (error) {
            fs.writeFileSync(path.join(evidence, 'live-compatible-' + name + '.log'), Buffer.concat([error.stdout || Buffer.alloc(0), error.stderr || Buffer.alloc(0)]));
            throw Error(name + ': ' + (error.stdout || '').toString() + (error.stderr || '').toString());
        }
        fs.writeFileSync(path.join(evidence, 'live-compatible-' + name + '.log'), output);
        console.log(name + ': PASS');
    }
} catch (error) {
    try { fs.writeFileSync(path.join(evidence, 'compatibility-environment-diagnostic.log'), docker(['logs', mysql])); } catch {}
    throw error;
} finally {
    fs.rmSync(path.join(candidate, '后端代码/.env'), {force: true});
    for (const name of [mysql, redis]) { try { docker(['rm', '-f', name]); } catch {} }
    try { docker(['network', 'rm', network]); } catch {}
}
