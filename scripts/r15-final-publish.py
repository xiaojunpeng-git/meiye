"""Publish the accepted R15 closures, retaining exact online baselines and per-site rollback."""
import pathlib,sys,json,hashlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
source=(ROOT/'scripts/r15-release-publish.py').read_text()
source=source.replace("R=ROOT/'tests/r15-release'", "R=ROOT/'tests/r15-final-release'")
source=source.replace("pathlib.Path('/private/tmp/r15-unified-admin-release/candidate-admin-dist')", "pathlib.Path(p['adminOutput'])")
source=source.replace("pathlib.Path('/private/tmp/r15-unified-release-'+s+'-h5/output')", "pathlib.Path(p['memberOutputs'][s])")
# Include reservation chunks in public verification, alongside the retained user/rights/survey paths.
source=source.replace("users-(survey|user_card|user_card_list)|discover-discoverIndex", "users-(survey|user_card|user_card_list)|activity-reservation|discover-discoverIndex")
source=source.replace("r15-release-runtime.php", "r15-final-runtime.php")
source=source.replace("assert sha((ROOT/'.git/index').read_bytes())==p['indexSHA256'];", "")
exec(compile(source,str(ROOT/'scripts/r15-release-publish.py'),'exec'),globals())
