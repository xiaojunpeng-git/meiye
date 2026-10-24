const fs = require('fs');
const path = require('path');
const os = require('os');
const cp = require('child_process');
const crypto = require('crypto');
const root = process.cwd();
const out = path.join(root, 'tests/target-scheme/evidence/R95-excel-names-2026-10-24');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const git = (args, env = process.env) => cp.execFileSync('git', args, { cwd: root, env });
const business = ['前端代码/admin/src/pages/target/scheme/Edit.vue', '前端代码/admin/src/utils/targetSchemeExcel.js', '后端代码/app/controller/admin/v1/target/StoreTarget.php'];
const files = [...business, 'tests/target-scheme/js/r95-excel-names.mjs', 'tests/target-scheme/js/r95-excel-browser.cjs', 'tests/target-scheme/php/r95-excel-import-integration.php', 'scripts/r95-excel-local-build.cjs', 'scripts/r95-excel-evidence.cjs'];
const head = git(['rev-parse','HEAD']).toString().trim();
if (head !== 'df65e60a4d916f0f021ad00576314061eaeab1bf') throw Error('Baseline HEAD changed; review before fixing candidate');
// 只在独立临时索引生成补丁对象，不改共享暂存区、不创建提交或第二份业务源码。
const indexPath = git(['rev-parse','--git-path','index']).toString().trim();
const beforeIndex = hash(fs.readFileSync(path.resolve(root,indexPath)));
const temp = fs.mkdtempSync(path.join(os.tmpdir(),'r95-excel-index-'));
const env = {...process.env,GIT_INDEX_FILE:path.join(temp,'index')};
try {
    git(['read-tree',head],env);
    const controllerBefore = '66d05e72079ce8a93bfbf8a581c2c7e82a27ecb7';
    git(['update-index','--add','--cacheinfo',`100644,${controllerBefore},${business[2]}`],env);
    const baselineTree = git(['write-tree'],env).toString().trim();
    for (const file of business) {
        const blob = git(['hash-object','-w',file]).toString().trim();
        git(['update-index','--add','--cacheinfo',`100644,${blob},${file}`],env);
    }
    const candidateTree = git(['write-tree'],env).toString().trim();
    const patch = git(['diff','--binary',baselineTree,candidateTree]);
    fs.writeFileSync(path.join(out,'business.patch'),patch);
    const manifest = {
        head, baselineTree, candidateTree, treePurpose:'scoped patch only; not a release baseline',
        businessPatchSha256:hash(patch),
        existingControllerBaseline:{blob:controllerBefore,sha256:'284d6ef3d6ea9b879f38ab016258ad4027ea317ffa55be77bc8cfb7baf921fd4'},
        files:files.map(file=>({file,sha256:hash(fs.readFileSync(path.join(root,file)))})),
        verification:{importChecks:49,databaseChecks:19,existingControls:20,browserChecks:7,productionBuild:JSON.parse(fs.readFileSync(path.join(out,'build.json')))},
        remote:'not applicable; no remote access',commit:false,deployment:false
    };
    if (hash(fs.readFileSync(path.resolve(root,indexPath))) !== beforeIndex) throw Error('Shared index changed during evidence generation; inspect');
    fs.writeFileSync(path.join(out,'fixed-candidate.json'),JSON.stringify(manifest,null,2));
    console.log(JSON.stringify(manifest,null,2));
} finally { fs.rmSync(temp,{recursive:true,force:true}); }
