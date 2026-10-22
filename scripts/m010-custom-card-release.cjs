/* Fixed Git blobs are the only deploy source. No dirty workspace business files are packaged. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const repo = path.resolve(__dirname, '..');
const commit = 'f4005e6682bfe04d2397ffa1bd76c8141fac2c40';
const evidence = path.join(repo, 'tests/cashier-v3/evidence/m010-custom-card-2026-10-22');
const site = process.argv[2], mode = process.argv[3];
if (!['rh', '008'].includes(site) || !['prepare', 'deploy', 'verify'].includes(mode)) throw Error('Explicit target and mode required');
const host = site === 'rh' ? 'rh-server' : 'mohe-server';
const run = (cmd, args, options = {}) => cp.execFileSync(cmd, args, {cwd: repo, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], ...options});
const ssh = command => run('ssh', [host, command]);
const fixed = JSON.parse(fs.readFileSync(path.join(evidence, 'fixed-version.json')));
const before = JSON.parse(fs.readFileSync(path.join(evidence, `${site}-preflight.json`)));
const files = fixed.files.filter(f => f.group === 'backend');
const artifact = path.join(process.env.HOME, '.codex/tmp', `m010-release-${site}-f4005e66`);
const remote = `/www/backup/m010-${site}-f4005e66-20261022`;
const receiptPath = path.join(evidence, `${site}-deployment.json`);
const hash = data => crypto.createHash('sha256').update(data).digest('hex');
const q = text => "'" + text.replaceAll("'", "'\\''") + "'";
const php = '/www/server/php/74/bin/php';
const install = action => ssh(`${php} ${q(remote + '/scripts/m010-custom-card-install.php')} ${action}`);
const service = action => site === 'rh'
    ? ssh(`/etc/init.d/ruihao_swoole ${action}`)
    : ssh(`systemctl ${action} mohe-008-swoole.service`);
if (mode === 'prepare') {
    fs.mkdirSync(artifact, {recursive: true, mode: 0o700});
    const after = {}, baseline = {}, protectedFiles = {};
    for (const file of files) {
        const relative = file.path.replace(/^后端代码\//, '');
        const data = run('git', ['show', `${commit}:${file.path}`], {encoding: 'buffer'});
        if (hash(data) !== file.sha256) throw Error('Accepted fingerprint mismatch');
        const dest = path.join(artifact, 'payload', relative);
        fs.mkdirSync(path.dirname(dest), {recursive: true}); fs.writeFileSync(dest, data);
        after[relative] = file.sha256; baseline[relative] = before.files[relative];
    }
    for (const [relative, sha] of Object.entries(before.files)) {
        if (!(relative in after)) protectedFiles[`${before.root}/${relative}`] = sha;
    }
    for (const entry of Object.values(before.entries)) {
        protectedFiles[entry.path] = entry.sha256;
        for (const [relative, sha] of Object.entries(entry.assets)) protectedFiles[path.dirname(path.dirname(entry.path)) + relative.replace('/view_cashier_v3', '/view_cashier_v3')] = sha;
    }
    // Protect customer configuration without copying its bytes into this release.
    protectedFiles[before.root + '/.env'] = ssh(`sha256sum ${q(before.root + '/.env')}`).trim().split(/\s/)[0];
    const manifest = {commit, site, root: before.root, database: site === 'rh' ? 'ruihao' : 'mohe_008', before: baseline, after, protected: protectedFiles};
    fs.writeFileSync(path.join(artifact, 'manifest.json'), JSON.stringify(manifest, null, 2));
    fs.mkdirSync(path.join(artifact, 'scripts'), {recursive: true});
    fs.copyFileSync(path.join(__dirname, 'm010-custom-card-install.php'), path.join(artifact, 'scripts/m010-custom-card-install.php'));
    const archive = artifact + '.tgz';
    run('tar', ['-czf', archive, '-C', artifact, 'manifest.json', 'payload', 'scripts']);
    ssh(`test ! -e ${q(remote)} && mkdir -m 700 -p ${q(remote)}`);
    run('scp', [archive, host + ':' + remote + '/payload.tgz']);
    ssh(`tar -xzf ${q(remote + '/payload.tgz')} -C ${q(remote)}`);
    const prepared = install('prepare');
    const probe = install('probe');
    fs.writeFileSync(receiptPath, JSON.stringify({commit, site, state: 'prepared', remote, prepared, beforeProbe: probe, at: new Date().toISOString()}, null, 2));
    console.log(prepared, probe);
} else {
    const receipt = JSON.parse(fs.readFileSync(receiptPath));
    if (receipt.commit !== commit || receipt.remote !== remote) throw Error('Receipt mismatch');
    if (mode === 'deploy') {
        if (receipt.state !== 'prepared') throw Error('Release is not prepared');
        let stopped = false;
        try {
            service('stop'); stopped = true;
            receipt.install = install('install');
            service('start'); stopped = false;
            receipt.verify = install('verify');
            receipt.afterProbe = install('probe');
            receipt.state = 'deployed-verified';
        } catch (error) {
            if (!stopped) { service('stop'); stopped = true; }
            receipt.rollback = install('rollback'); service('start'); stopped = false;
            receipt.state = 'rolled-back'; receipt.error = String(error);
            fs.writeFileSync(receiptPath, JSON.stringify(receipt, null, 2));
            throw error;
        } finally { if (stopped) service('start'); }
    } else { receipt.verify = install('verify'); receipt.afterProbe = install('probe'); }
    receipt.serviceStatus = site === 'rh' ? service('status') : ssh('systemctl is-active mohe-008-swoole.service');
    receipt.verifiedAt = new Date().toISOString();
    fs.writeFileSync(receiptPath, JSON.stringify(receipt, null, 2));
    console.log(JSON.stringify(receipt, null, 2));
}
