"""Run the real DB suite with immutable candidate PHP class blobs, never replacing shared source."""
import base64,hashlib,io,json,pathlib,re,subprocess,tarfile
E=pathlib.Path(__file__).parent/'evidence';plan=json.loads((E/'fixed.json').read_text());mapping={};archive=io.BytesIO();prefix='/tmp/platform-member-fixed-'+plan['sourceTree'][:12]
with tarfile.open(fileobj=archive,mode='w') as tar:
 for f,h in plan['files'].items():
  if not f.endswith('.php'):continue
  data=subprocess.check_output(['git','show',plan['sourceTree']+':'+f]);text=data.decode();ns=re.search(r'namespace\s+([^;]+);',text);cl=re.search(r'^(?:abstract )?class\s+(\w+)',text,re.M)
  if not ns or not cl:continue
  dest=prefix+'/'+cl[1]+'.php';mapping[ns[1]+'\\'+cl[1]]=dest
  item=tarfile.TarInfo(dest.lstrip('/'));item.mode=0o444;item.size=len(data);tar.addfile(item,io.BytesIO(data))
subprocess.run(['docker','exec','-i','mohe-app','tar','xf','-','-C','/'],input=archive.getvalue(),check=True)
src=pathlib.Path(__file__).with_name('local-database.php').read_text();needle="(new think\\App('/var/www/html/'))->initialize();"
loader="$fixedMap=json_decode(base64_decode('"+base64.b64encode(json.dumps(mapping).encode()).decode()+"'),true);\nspl_autoload_register(function($class)use($fixedMap){if(isset($fixedMap[$class]))require $fixedMap[$class];},true,true);\n"
src=src.replace(needle,loader+needle,1)+ '\nforeach($fixedMap as $class=>$path){if((new ReflectionClass($class))->getFileName()!==$path)throw new RuntimeException("Candidate class source mismatch");} echo "FIXED_SOURCE_PROVENANCE_PASS\\n";\n'
r=subprocess.run(['docker','exec','-i','mohe-app','php'],input=src.encode(),stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=180)
(E/'fixed-database.txt').write_bytes(r.stdout)
assert r.returncode==0 and b'PLATFORM_MEMBER_LOGIN_DATABASE_PASS 40 checks' in r.stdout and b'FIXED_SOURCE_PROVENANCE_PASS' in r.stdout,r.stdout[-2400:]
print('PASS immutable candidate: 40 database/JWT/export checks; '+str(len(mapping))+' PHP class sources verified')
