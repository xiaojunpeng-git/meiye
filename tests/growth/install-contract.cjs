/* 安装验证通过独立临时MySQL库执行，输出仅为契约结果。 */
const {execFileSync}=require('node:child_process');
const result=execFileSync('docker',['exec','mohe-growth-local-app','php','/workspace/tests/growth/install-contract.php'],{encoding:'utf8'});
const parsed=JSON.parse(result);if(parsed.passed.length<10)throw Error('安装验证覆盖不完整');console.log(result.trim());
