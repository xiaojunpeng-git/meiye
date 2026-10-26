// Test the real installer in a disposable offline container. /www is tmpfs,
// never a mounted customer site; source is readonly and no database is reachable.
const fs = require('fs'), cp = require('child_process'), path = require('path');
const root = path.resolve(__dirname, '../../..');
const fixture = `<?php
$root='/www/wwwroot/rh.cc3798.com';$directory='/www/backups/rh/r54-service-period-12345678';
mkdir($root.'/public/view_cashier_v3/assets',0755,true);mkdir($directory.'/payload',0700,true);
$old='old entry';$next='new entry';$asset='new asset';$key='formal/public/view_cashier_v3/index.html';$assetKey='formal/public/view_cashier_v3/assets/index-test.js';
$manifest=['site'=>'rh','commit'=>str_repeat('12345678',5),'previewAuthorized'=>true,'targets'=>[$assetKey=>['before'=>null,'after'=>hash('sha256',$asset)],$key=>['before'=>hash('sha256',$old),'after'=>hash('sha256',$next)]]];
foreach([$key=>$next,$assetKey=>$asset]as $k=>$body){mkdir(dirname($directory.'/payload/'.$k),0755,true);file_put_contents($directory.'/payload/'.$k,$body);}file_put_contents($root.'/public/view_cashier_v3/index.html',$old);
file_put_contents($directory.'/payload/manifest.json',json_encode($manifest));$digest=hash_file('sha256',$directory.'/payload/manifest.json');
$checks=0;function verify($ok){global $checks;if(!$ok)throw new RuntimeException('installer assertion '.($checks+1));$checks++;}
function invoke($mode,$hash=null){global $directory,$digest;$cmd=escapeshellarg(PHP_BINARY).' /fixed/install.php '.escapeshellarg($directory).' '.escapeshellarg($mode).' '.escapeshellarg($hash??$digest).' 2>&1';exec($cmd,$lines,$code);return [$code,implode("\\n",$lines)];}
verify(invoke('backup')[0]===0);verify(file_exists($directory.'/prepared.json'));
verify(invoke('backup')[0]!==0);verify(invoke('install',str_repeat('0',64))[0]!==0);
file_put_contents($root.'/public/view_cashier_v3/index.html','concurrent');verify(invoke('install')[0]!==0);
verify(file_get_contents($root.'/public/view_cashier_v3/index.html')==='concurrent');
file_put_contents($root.'/public/view_cashier_v3/index.html',$old);verify(invoke('install')[0]===0);
verify(file_get_contents($root.'/public/view_cashier_v3/index.html')===$next);verify(file_get_contents($root.'/public/view_cashier_v3/assets/index-test.js')===$asset);
file_put_contents($root.'/public/view_cashier_v3/index.html','newer deployment');verify(invoke('rollback')[0]!==0);
verify(file_get_contents($root.'/public/view_cashier_v3/index.html')==='newer deployment');
file_put_contents($root.'/public/view_cashier_v3/index.html',$next);verify(invoke('rollback')[0]===0);
verify(file_get_contents($root.'/public/view_cashier_v3/index.html')===$old);verify(!file_exists($root.'/public/view_cashier_v3/assets/index-test.js'));
echo json_encode(['result'=>'PASS','checks'=>$checks,'offlineDisposableContainer'=>true,'businessWrites'=>0]);
`;
const output = cp.execFileSync('docker', ['run', '--rm', '-i', '--network', 'none', '--read-only', '--tmpfs', '/www:rw,noexec,nosuid,size=16m', '--tmpfs', '/tmp:rw,noexec,nosuid,size=16m', '--entrypoint', 'php', '-v', path.join(root, 'scripts/r54-service-period-install.php') + ':/fixed/install.php:ro', 'mohe-app:local'], { input: fixture, encoding: 'utf8' });
const result = JSON.parse(output); if (result.result !== 'PASS' || result.checks !== 14) throw Error('Installer regression incomplete');
fs.writeFileSync(path.join(root, 'tests/cashier-v3/evidence/R54-release/installer-tests.json'), JSON.stringify(result, null, 2) + '\n'); console.log(output);
