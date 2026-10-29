// Local-only tests use a disposable restricted DB login: SELECT on the source,
// full rights only on r12_survey_* fixtures. A code/variable error cannot drop ruihao.
const path=require('path'),cp=require('child_process'),fs=require('fs'),crypto=require('crypto'),os=require('os');
const root=path.resolve(__dirname,'../..'),backend=path.join(root,'后端代码');
const canonical=path.dirname(fs.realpathSync(path.join(backend,'vendor')));
// 修复回归另存证据，不覆盖已验收候选的历史测试记录；输出只允许在本项目证据目录。
const evidence=process.env.R12_EVIDENCE_DIR?path.resolve(root,process.env.R12_EVIDENCE_DIR):path.join(root,'tests/customer-010/evidence/round12-member-survey');
if(!evidence.startsWith(path.join(root,'tests/customer-010/evidence')+path.sep))throw Error('Evidence output outside project');
fs.mkdirSync(evidence,{recursive:true});
const result=path.join(evidence,'business.json');
const nonce=crypto.randomBytes(6).toString('hex'),username='r12_test_'+nonce,password=crypto.randomBytes(24).toString('hex');
const reference='r12_reference_'+nonce;
const envPath=path.join(canonical,'runtime','r12-survey-'+nonce+'.env');
const source=fs.readFileSync(path.join(canonical,'.env'),'utf8');
let database='',replaced=false;
const isolated=source.replace(/(^\[database\][^\r\n]*\r?\n)([\s\S]*?)(?=^\[|(?![\s\S]))/gmi,(_,header,body)=>{
 const match=body.match(/^\s*database\s*=\s*["']?([A-Za-z0-9_]+)/mi);if(!match)throw Error('Source database missing');database=match[1];
 let users=0,passwords=0;
 const rewritten=body.replace(/^(\s*database\s*=).*$/gmi,(_,prefix)=>prefix+' '+reference).replace(/^(\s*username\s*=).*$/gmi,(_,prefix)=>{users++;return prefix+' '+username}).replace(/^(\s*password\s*=).*$/gmi,(_,prefix)=>{passwords++;return prefix+' '+password});
 if(users!==1||passwords!==1)throw Error('Credential replacement did not match exactly');replaced=true;return header+rewritten;
});
if(!replaced||!/^ruihao$/.test(database))throw Error('Unrecognized local fixture source');
const sql=statement=>cp.execFileSync('docker',['exec','-i','mohe-mysql','sh','-c','MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --batch --skip-column-names'],{input:statement,encoding:'utf8',maxBuffer:1e6});
if(sql('SELECT @@hostname;').trim()!=='de34b8634512')throw Error('Local MySQL identity changed');
const schemaSource=process.env.R12_SCHEMA_SOURCE||'ruihao';
if(!/^(ruihao|r12_survey_[a-f0-9]{10})$/.test(schemaSource))throw Error('Unknown local schema source');
if(sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='"+schemaSource+"';").trim()!=='1')throw Error('Explicit schema source unavailable');
let status=1;
try{
 sql('CREATE DATABASE `'+reference+'` CHARACTER SET utf8mb4;');
 const tables=sql("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='"+schemaSource+"' AND TABLE_TYPE='BASE TABLE';").trim().split('\n');
 if(!tables.length||tables.some(t=>!/^eb_[a-zA-Z0-9_]+$/.test(t)))throw Error('Schema table list invalid');
 sql(tables.map(t=>'CREATE TABLE `'+reference+'`.`'+t+'` LIKE `'+schemaSource+'`.`'+t+'`;').join('\n'));
 sql("CREATE USER '"+username+"'@'%' IDENTIFIED BY '"+password+"'; GRANT SELECT, SHOW VIEW ON `"+reference+"`.* TO '"+username+"'@'%'; GRANT ALL PRIVILEGES ON `r12\\_survey\\_%`.* TO '"+username+"'@'%';");
 fs.writeFileSync(envPath,isolated,{mode:0o600});
 if(fs.existsSync(result))fs.unlinkSync(result);
 const envProbe=cp.execFileSync('docker',['run','--rm','--user','0:0','--entrypoint','php','-v',envPath+':/run/local-fixture.env:ro','mohe-app:local','-r','echo json_encode([is_file("/run/local-fixture.env"),is_readable("/run/local-fixture.env"),is_array(@parse_ini_file("/run/local-fixture.env",true))]);'],{encoding:'utf8'});if(envProbe.trim()!=='[true,true,true]')throw Error('Restricted env mount invalid: '+envProbe);
 const args=['run','--rm','--user','0:0','--network','mohe-net','--entrypoint','php','-e','BACKEND_ROOT=/var/www/html','-e','LOCAL_TEST_ENV=/run/local-fixture.env','-e','R12_RESULT=/evidence/business.json','-v',backend+':/var/www/html:ro','-v',canonical+':'+canonical+':ro','-v',envPath+':/run/local-fixture.env:ro','-v',path.join(root,'scripts')+':/scripts:ro','-v',path.join(root,'tests')+':/tests:ro','-v',evidence+':/evidence','-w','/var/www/html','mohe-app:local','/tests/member-survey/php/local-business.php'];
 const run=cp.spawnSync('docker',args,{encoding:'utf8',maxBuffer:10e6});process.stdout.write(run.stdout||'');process.stderr.write(run.stderr||'');
 if(run.status===0&&fs.existsSync(result)&&JSON.parse(fs.readFileSync(result)).passed===true)status=0;
}finally{
 sql("DROP USER IF EXISTS '"+username+"'@'%';");if(!/^r12_reference_[a-f0-9]{12}$/.test(reference))throw Error('Reference cleanup refused');sql('DROP DATABASE IF EXISTS `'+reference+'`;');if(fs.existsSync(envPath))fs.unlinkSync(envPath);
}
process.exit(status);
