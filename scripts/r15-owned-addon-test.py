"""Run canonical PHP against the existing disposable rights database, never production."""
import pathlib,subprocess,hashlib,json,time
root=pathlib.Path(__file__).resolve().parents[1]
# Docker Desktop bind mounts may briefly expose the prior/truncated write.
# Execute only after all tested PHP bytes match the canonical fixed input.
manifest=json.loads((root/'tests/r15-owned-addon/evidence/baseline.json').read_text())
php_files=[p for p in manifest['files'] if p.startswith('后端代码/')]
expected={p:hashlib.sha256((root/p).read_bytes()).hexdigest() for p in php_files}
for attempt in range(8):
    remote={p:'/var/www/html/'+p.split('/',1)[1] for p in php_files}
    code='$f=json_decode('+json.dumps(json.dumps(remote))+',true);$o=[];foreach($f as $k=>$v)$o[$k]=hash_file("sha256",$v);echo json_encode($o);'
    actual=json.loads(subprocess.check_output(['docker','exec','mohe-app','php','-r',code]))
    if actual==expected:break
    time.sleep(.2)
else:raise RuntimeError('Local bind mount differs from tested source')
base=(root/'tests/customer-008/php/historical-reservation-store-isolated.php').read_text()
extra=(root/'tests/r15-owned-addon/mixed-booking.php').read_text().replace('<?php','',1)
helpers=(root/'tests/cashier-v3/php/card-rule-entitlement-authority-integration.php').read_text().split('function cardRuleCanonicalize',1)[1].split('function deduction(',1)[0]
extra += '\nfunction cardRuleCanonicalize' + helpers
extra += (root/'tests/r15-owned-addon/card-types.php').read_text().replace('<?php','',1)
p=subprocess.run(['docker','exec','-i','-e','M008_RIGHTS_TEST_BACKEND=/var/www/html','-e','M008_RIGHTS_TEST_HOST=m008-rights-isolate','-e','M008_RIGHTS_TEST_PORT=3306','mohe-app','php'],input=(base+'\n'+extra).encode(),capture_output=True,timeout=90)
(root/'tests/r15-owned-addon/evidence/mixed-backend.log').write_bytes(p.stdout+p.stderr)
print(p.stdout.decode()[-1500:]);assert p.returncode==0,p.stderr.decode()
