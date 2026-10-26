import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';

// Materialize immutable release candidates, never edit the shared development tree or
// import a server file into canonical source. Every transformation is R51-only.
const [base, output, snapshotDir] = process.argv.slice(2);
if (!base || !output || fs.existsSync(output)) throw new Error('Use a new output directory and an explicit Git base');
const sha = data => crypto.createHash('sha256').update(data).digest('hex');
const git = args => execFileSync('git', args, {encoding:'utf8',maxBuffer:16*1024*1024});
const files = ['Two','Six'].map(n => `后端代码/app/services/report/StoreUnifiedReportPhase${n}Services.php`);
const accepted = JSON.parse(fs.readFileSync('tests/report-phase-six/evidence/R51-garden-market-people-2026-10-14.json'));
const replace = (s, before, after) => {
  if (!before || s.split(before).length !== 2) throw new Error(`Non-unique/missing release anchor: ${before.slice(0,100)}`);
  return s.replace(before, after);
};
// These services use a four-space method closing brace. Refuse ambiguous extraction.
function method(s, name) {
  const re = new RegExp(`^    (?:public|private) function ${name}\\(`,'m');
  const match = re.exec(s); if (!match) throw new Error(`Missing method ${name}`);
  const end = s.indexOf('\n    }\n',match.index); if (end < 0) throw new Error(`Missing method end ${name}`);
  return s.slice(match.index,end+7);
}
function editMethod(s,name,edit) {const old=method(s,name);return replace(s,old,edit(old));}
fs.mkdirSync(output,{recursive:true});
const manifest={base:git(['rev-parse',base]).trim(),files:[]};
for (const file of files) {
  const current=fs.readFileSync(file,'utf8');
  if (sha(current)!==accepted.files_by_kind.backend_source.find(f=>f.path===file).sha256) throw new Error(`Acceptance changed: ${file}`);
  const original=snapshotDir ? fs.readFileSync(path.join(snapshotDir,path.basename(file)),'utf8') : git(['show',`${base}:${file}`]);
  let next=original;
  if (file.includes('PhaseTwo')) {
    if (!next.includes('market-imported-debt-effective-v6')) throw new Error('Prerequisite imported-debt metric missing');
    const begin=current.indexOf('    public function query(');
    const end=current.indexOf('    private function marketPerformance(',begin);
    const oldBegin=next.indexOf('    public function query(');
    const oldEnd=next.indexOf('    private function marketPerformance(',oldBegin);
    next=replace(next,next.slice(oldBegin,oldEnd),current.slice(begin,end));
    // Only market methods are relabelled; refund personnel descriptions retain their wording.
    for (const name of ['marketPerformance','marketDetail','marketCountableServiceVisits','marketEffectiveMemberKeys','result','marketPerformanceColumnExplanations','marketDetailColumnExplanations']) {
      next=editMethod(next,name,s=>s.replaceAll('有效人员','成交人数'));
    }
    for (const line of current.split('\n').filter(l=>l.trim().startsWith('*') || l.trim().startsWith('/**'))) {
      if (line.includes('成交人数')) {
        const old=line.replaceAll('成交人数','有效人员');
        if(next.includes(old)) next=replace(next,old,line);
      }
    }
    const comment='        // 明细与导出复用同一列定义；成交人数仍按会员日达标，不表示单据张数。\n';
    next=editMethod(next,'marketDetail',s=>replace(s,'        $columns = $this->columns(',comment+'        $columns = $this->columns('));
    next=replace(next,'    private function marketEffectiveMemberDayKey(', '    public function marketEffectiveMemberDayKey(');
  } else {
    next=editMethod(next,'query',s=>{
      s=replace(s,'$this->garden($stores, $range)','$this->garden($stores, $range, $input)');
      const version="        // Only the garden headcount changes; other phase-six metrics retain their accepted versions.\n        if ($report === 'phase_six_garden_item_analysis') {\n            $result['metric_version'] = 'report-phase-six-garden-market-member-day-v4';\n        }\n";
      if(s.includes('        return $this->withManualAnnotations(')) {
        s=replace(s,'        return $this->withManualAnnotations(', '        $result = $this->withManualAnnotations(');
        s=s.slice(0,-6)+version+'        return $result;\n    }\n';
      } else s=replace(s,'\n        return $result;\n','\n'+version+'        return $result;\n');
      return s;
    });
    const oldGarden=method(next,'garden');
    let garden=method(current,'garden');
    // A release must not silently migrate an instance's category reader or widen its rows.
    const oldFilter=oldGarden.split('\n').find(l=>l.includes('$facts = array_values(array_filter('));
    const newFilter=garden.split('\n').find(l=>l.includes('$facts = array_values(array_filter('));
    garden=replace(garden,newFilter,oldFilter.replace('$this->cashFacts($stores, $range)','$this->cashFacts($stores, $range, true)'));
    next=replace(next,oldGarden,'    /** 花园明细保留原分摊金额；成交资格复用市场表，每个达标会员日只落在一条稳定明细上。 */\n'+garden);
    next=editMethod(next,'cashFacts',s=>{
      s=replace(s,'private function cashFacts(array $stores,array $range):array','private function cashFacts(array $stores,array $range,bool $withPaymentSource = false):array');
      const cash=method(current,'cashFacts');
      const insert=cash.slice(cash.indexOf('        if ($withPaymentSource)'),cash.indexOf('        $normal ='));
      return '    /** 可选读取真实收款来源供花园会员日匹配；其他报表的金额与原销售快照不变。 */\n'+replace(s,'        $normal =',insert+'        $normal =');
    });
  }
  const target=path.join(output,path.basename(file));
  fs.writeFileSync(target,next,{mode:0o444});
  execFileSync('php',['-l',target],{stdio:'pipe'});
  manifest.files.push({source:file,before:sha(original),after:sha(next),blob:git(['hash-object','-w',target]).trim()});
}
fs.writeFileSync(path.join(output,'manifest.json'),JSON.stringify(manifest,null,2)+'\n',{mode:0o444});
console.log(JSON.stringify(manifest,null,2));
