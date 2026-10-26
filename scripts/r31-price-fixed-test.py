"""Run accepted R31 business tests against immutable candidate PHP, without production writes."""
import json,subprocess
from pathlib import Path
R=Path('tests/cashier-v3/evidence/R31-sale-price-range/release');tree=json.loads((R/'trees.json').read_text())['candidateTree'];target='/tmp/r31-fixed-'+tree[:12];backend=target+'/后端代码'
def docker(*a,**kw):return subprocess.check_output(['docker','exec',*a],**kw)
docker('mohe-app','mkdir','-p',target)
archive=subprocess.check_output(['git','archive',tree,'后端代码/app','后端代码/config','后端代码/mohe','后端代码/route','tests/cashier-v3/php'],stderr=subprocess.PIPE)
docker('-i','mohe-app','tar','-xf','-','-C',target,input=archive)
for name in ['vendor','.env']:docker('mohe-app','ln','-s','/var/www/html/'+name,backend+'/'+name)
docker('mohe-app','mkdir','-p',backend+'/runtime')
for name in ['app','config','mohe','route']:docker('mohe-app','chmod','-R','a-w',backend+'/'+name)
# Only rewrite the test launch path. Candidate business source remains read-only.
test=subprocess.check_output(['git','show',tree+':tests/cashier-v3/php/r31-sale-price-range-local.php']).replace(b'/var/www/html/',(backend+'/').encode())
docker('-i','mohe-app','sh','-c','cat > '+target+'/r31-local-test.php',input=test)
out=docker('mohe-app','php',target+'/r31-local-test.php',stderr=subprocess.STDOUT)
(R/'fixed-local-test.log').write_bytes(out)
assert b'R31 SALE PRICE RANGE PASS' in out
print('FIXED_CANDIDATE_BUSINESS_PASS',tree)
