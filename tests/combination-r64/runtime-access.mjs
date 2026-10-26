import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

// Isolated fixture reproduces root success / worker failure without changing
// permissions on the running application. No database or payment is accessed.
const docker = (args, input) => execFileSync('docker', args, { input, encoding: 'utf8' });
const root = docker(['exec', '-i', 'mohe-app', 'php'], `<?php
$p='/tmp/r64-runtime-'.bin2hex(random_bytes(6));
mkdir($p,0755);mkdir($p.'/vendor',0755);mkdir($p.'/config',0755);mkdir($p.'/classes',0700);
file_put_contents($p.'/classes/Probe.php','<?php namespace R64Fixture; final class Probe {}');
file_put_contents($p.'/vendor/autoload.php','<?php $loader=require "/var/www/html/vendor/autoload.php";$loader->addPsr4("R64Fixture".chr(92),dirname(__DIR__)."/classes");');
file_put_contents($p.'/config/unified_query.php','<?php return ["registrars"=>["R64Fixture".chr(92)."Probe"]];');
echo $p;
`).trim();
assert.match(root, /^\/tmp\/r64-runtime-[a-f0-9]{12}$/);
const script = readFileSync('scripts/unified-query-runtime-readability.php');
const probe = () => docker(['exec', '-i', '-u', 'www-data', 'mohe-app', 'php', '--',
    '--root=' + root, '--runtime-user=www-data'], script);
try {
    let denied;
    try { probe(); } catch (e) { denied = e; }
    assert.equal(denied?.status, 1, denied?.stdout + String(denied?.stderr));
    assert.equal(JSON.parse(denied.stdout).classes['R64Fixture\\Probe'], false);
    console.log('PASS worker rejects inaccessible class directory');
    const hash = () => docker(['exec', 'mohe-app', 'sha256sum', root + '/classes/Probe.php']);
    const before = hash();
    docker(['exec', 'mohe-app', 'chmod', '755', root + '/classes']);
    assert.equal(JSON.parse(probe()).ok, true);
    assert.equal(hash(), before);
    console.log('PASS directory access repair loads class without changing code');
    let rootDenied;
    try {
        docker(['exec', '-i', 'mohe-app', 'php', '--', '--root=' + root, '--runtime-user=root'], script);
    } catch (e) { rootDenied = e; }
    assert.equal(rootDenied?.status, 2);
    console.log('PASS root probe cannot masquerade as worker verification');
} finally {
    docker(['exec', 'mohe-app', 'rm', '-rf', '--', root]);
}
