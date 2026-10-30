"""Run canonical integration fixtures on the named disposable database only."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[1]
base=(root/'tests/customer-008/php/historical-reservation-store-isolated.php').read_text()
extra=(root/'tests/r15-card-occupancy/backend.php').read_text().replace('<?php','',1)
r=subprocess.run(['docker','exec','-i','-e','M008_RIGHTS_TEST_BACKEND=/var/www/html','-e','M008_RIGHTS_TEST_HOST=m008-rights-isolate','-e','M008_RIGHTS_TEST_PORT=3306','mohe-app','php'],input=(base+'\n'+extra).encode(),capture_output=True,timeout=90)
(root/'tests/r15-card-occupancy/evidence/backend.txt').write_bytes(r.stdout+r.stderr)
print(r.stdout.decode()[-2500:]);assert r.returncode==0,r.stderr.decode()
