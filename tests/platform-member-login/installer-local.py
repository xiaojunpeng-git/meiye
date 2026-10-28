"""Run owned installer fixtures without mounting production or exposing local DB credentials."""
import base64,json,pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
raw=subprocess.check_output(['/opt/homebrew/bin/php','-r',"$e=parse_ini_file('后端代码/.env',true,INI_SCANNER_RAW);echo json_encode(array_change_key_case($e['DATABASE']??$e['database']));"],cwd=root)
c=json.loads(raw);assert c['hostname']=='mysql'and c['database']=='ruihao';c['hostport']=int(c.get('hostport',3306))
folders=['2026-10-28-会员改号撤销登录','2026-10-28-平台门店会员管理'];m={f:base64.b64encode((root/'后端代码/database/upgrades'/f/'02-正式升级.sql').read_bytes()).decode()for f in folders}
source=pathlib.Path(__file__).with_suffix('.php').read_text().replace('{{CONFIG}}',base64.b64encode(json.dumps(c).encode()).decode()).replace('{{MIGRATIONS}}',base64.b64encode(json.dumps(m).encode()).decode()).replace('{{INSTALLER}}',base64.b64encode((root/'scripts/platform-member-install.php').read_bytes()).decode())
r=subprocess.run(['docker','exec','-i','mohe-app','php'],input=source.encode(),capture_output=True);log=r.stdout+r.stderr;(root/'tests/platform-member-login/release/unified/installer-local.txt').write_bytes(log)
assert r.returncode==0 and b'INSTALLER_LOCAL_PASS'in log,log[-1600:];print(log.decode().strip())
