"""逐站发布已固定包；不构建脏工作区，不改变任何会员归属，异常自动恢复代码入口。"""
import hashlib
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
BASE = Path('/private/tmp/mohe-r26-member-transfer-20261022')
SITES = {'rh': ('rh-server', '/etc/init.d/ruihao_swoole restart', "ss -lnt | grep -q ':20800 '"),
         '008': ('mohe-server', 'systemctl restart mohe-008-swoole.service', 'systemctl is-active --quiet mohe-008-swoole.service')}
site = sys.argv[1]
host, restart, health = SITES[site]
directory = '/www/backups/' + site + '/20261022-r26-bulk-802490dc'

def run(args, **kwargs):
    return subprocess.check_output(args, cwd=ROOT, **kwargs)

def ssh(command, contents=None):
    return run(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', host, command], input=contents)

def probe():
    return json.loads(ssh("/www/server/php/74/bin/php -r 'eval(substr(stream_get_contents(STDIN),5));' -- " + site + ' probe',
                          (ROOT / 'scripts/r26-member-transfer-instance.php').read_bytes()))

manifest = json.loads((BASE / 'payload/manifest.json').read_text())
if probe() != manifest['before'][site]:
    raise RuntimeError('Live baseline changed; do not deploy')
if site == '008' and not (BASE / 'push-verified.json').exists():
    raise RuntimeError('RH verification and push must finish before 008')
ssh("set -eu; test ! -e '" + directory + "'; mkdir -m 700 -p '" + directory + "'")
run(['scp', '-q', str(BASE / 'payload.tar.gz'), str(BASE / 'install.php'),
     str(ROOT / 'scripts/r26-member-transfer-smoke.php'), host + ':' + directory + '/'])
digest = hashlib.sha256((BASE / 'payload.tar.gz').read_bytes()).hexdigest()
install_hash = hashlib.sha256((BASE / 'install.php').read_bytes()).hexdigest()
command = '/www/server/php/74/bin/php ' + directory + '/install.php ' + site
script = f"""set -euo pipefail
test "$(sha256sum '{directory}/payload.tar.gz' | cut -d' ' -f1)" = '{digest}'
test "$(sha256sum '{directory}/install.php' | cut -d' ' -f1)" = '{install_hash}'
tar -xzf '{directory}/payload.tar.gz' -C '{directory}'
{command} prepare '{directory}'
tar -czf '{directory}/before.tar.gz' -C '{directory}' before before.json
gzip -t '{directory}/before.tar.gz'
sha256sum '{directory}/before.tar.gz'
find '{directory}/payload/app' '{directory}/payload/route' -type f -name '*.php' -exec /www/server/php/74/bin/php -l {{}} \;
rollback() {{
  set +e
  {command} rollback '{directory}'
  {restart}
  echo R26_ROLLBACK_ATTEMPTED >&2
}}
trap rollback ERR
{command} install '{directory}'
{restart}
{health}
{command} verify '{directory}'
/www/server/php/74/bin/php '{directory}/r26-member-transfer-smoke.php' {site}
trap - ERR
echo R26_INSTANCE_RELEASE_PASS
"""
result = ssh('bash -s', script.encode())
(BASE / (site + '-deployment.txt')).write_bytes(result)
if b'R26_INSTANCE_RELEASE_PASS' not in result:
    raise RuntimeError('Missing completion marker')
(BASE / (site + '-after.json')).write_text(json.dumps(probe(), ensure_ascii=False, indent=2) + '\n')
print(result.decode())
