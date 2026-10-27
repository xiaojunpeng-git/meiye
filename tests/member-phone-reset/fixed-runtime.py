"""Test immutable Git candidate PHP classes, without replacing the shared source mount."""
import io
import base64
import json
import pathlib
import re
import subprocess
import tarfile

evidence = pathlib.Path(__file__).parent / 'evidence'
plan = json.loads((evidence / 'fixed.json').read_text())
runtime = '/tmp/member-phone-reset-fixed-' + plan['sourceTree'][:12]
mapping = {}
archive = io.BytesIO()
with tarfile.open(fileobj=archive, mode='w') as tar:
    for source in plan['files']:
        if not source.endswith('.php'):
            continue
        data = subprocess.check_output(['git', 'show', plan['sourceTree'] + ':' + source])
        text = data.decode()
        namespace = re.search(r'namespace\s+([^;]+);', text).group(1)
        name = re.search(r'^(?:abstract )?class\s+(\w+)', text, re.M).group(1)
        destination = runtime + '/' + name + '.php'
        mapping[namespace + '\\' + name] = destination
        info = tarfile.TarInfo(destination.lstrip('/'))
        info.mode = 0o444
        info.size = len(data)
        tar.addfile(info, io.BytesIO(data))
subprocess.run(['docker', 'exec', '-i', 'mohe-app', 'tar', 'xf', '-', '-C', '/'], input=archive.getvalue(), check=True, timeout=30)
source = pathlib.Path(__file__).with_name('local-database.php').read_text()
needle = "(new think\\App('/var/www/html/'))->initialize();"
loader = "$fixedMap = json_decode(base64_decode('" + base64.b64encode(json.dumps(mapping).encode()).decode() + "'), true);\n"
loader += "spl_autoload_register(function ($class) use ($fixedMap) { if (isset($fixedMap[$class])) require $fixedMap[$class]; }, true, true);\n"
source = source.replace(needle, loader + needle, 1)
# Prove all changed PHP classes were loaded from the immutable candidate.
source += '\nforeach ($fixedMap as $class => $path) { if ((new ReflectionClass($class))->getFileName() !== $path) throw new RuntimeException("Wrong fixed class " . $class); }\necho "FIXED_SOURCE_PROVENANCE_PASS\\n";\n'
result = subprocess.run(['docker', 'exec', '-i', 'mohe-app', 'php'], input=source.encode(), stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=60)
(evidence / 'fixed-database.txt').write_bytes(result.stdout)
assert result.returncode == 0 and b'MEMBER_PHONE_RESET_DATABASE_PASS 33 checks' in result.stdout and b'FIXED_SOURCE_PROVENANCE_PASS' in result.stdout, result.stdout[-3500:]
print('PASS exact immutable candidate: 33 real DB/JWT/Redis checks and all 8 PHP class provenance checks')
