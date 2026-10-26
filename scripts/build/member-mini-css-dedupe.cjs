// Factor repeated WXSS into same-package imports. Expanding every generated
// import must reproduce the original stylesheet byte-for-byte and in order.
const path=require('path');
const fs=require('fs');
const crypto=require('crypto');
const assert=require('assert');
const hash=value=>crypto.createHash('sha256').update(value).digest('hex');
const textAsset=value=>({source:()=>value,size:()=>Buffer.byteLength(value)});
function optimize(assets,{postcss,valueParser}){
  const app=JSON.parse(assets['app.json'].source().toString());
  const config=JSON.parse(assets['project.config.json'].source().toString());
  const roots=(app.subPackages||app.subpackages||[]).map(p=>p.root.replace(/\/$/,''));
  const groupOf=file=>roots.find(root=>file.startsWith(root+'/'))||'main';
  const ignored=file=>(config.packOptions?.ignore||[]).some(rule=>{
    if(rule.type==='folder')return file===rule.value||file.startsWith(rule.value.replace(/\/$/,'')+'/');
    if(rule.type==='file')return file===rule.value;
    return false;
  });
  function sizes(){
    const result={};
    for(const [file,asset] of Object.entries(assets))if(!ignored(file)){
      const group=groupOf(file);result[group]=(result[group]||0)+asset.size();
    }
    return result;
  }
  const before=sizes(),original=new Map(),generated=new Map(),changed=[];
  const groups=Object.keys(before).filter(group=>before[group]>2048*1024);
  for(const group of groups){
    const files=new Map(),usage=new Map();
    for(const [file,asset] of Object.entries(assets)){
      if(!file.endsWith('.wxss')||ignored(file)||groupOf(file)!==group)continue;
      const content=asset.source().toString(),parsed=postcss.parse(content,{from:file});
      const starts=[0];for(let i=0;i<content.length;i++)if(content[i]==='\n')starts.push(i+1);
      const position=pos=>starts[pos.line-1]+pos.column-1;
      let cursor=0;
      const tokens=parsed.nodes.map(node=>{
        const end=position(node.source.end)+1;
        const raw=content.slice(cursor,end);cursor=end;
        let safe=node.type==='rule'||node.type==='atrule';
        if(node.type==='atrule'&&['import','charset'].includes(node.name))safe=false;
        node.walkDecls?.(declaration=>valueParser(declaration.value).walk(value=>{
          if(value.type!=='function'||value.value.toLowerCase()!=='url')return;
          const url=valueParser.stringify(value.nodes).replace(/^['"]|['"]$/g,'');
          if(!/^(?:data:|https?:|\/|#)/i.test(url))safe=false;
        }));
        const key=hash(raw);
        if(safe){
          if(!usage.has(key))usage.set(key,{count:0,files:new Set()});
          usage.get(key).count++;usage.get(key).files.add(file);
        }
        return {raw,key,safe};
      });
      if(cursor<content.length)tokens.push({raw:content.slice(cursor),safe:false});
      files.set(file,{content,tokens});
    }
    for(const [file,{content,tokens}] of files){
      // Group consecutive nodes with the same consumers; this keeps the
      // fragment count bounded without changing rule order or specificity.
      const segments=[];
      for(const token of tokens){
        const record=token.safe&&usage.get(token.key);
        const signature=record&&record.count>1?[...record.files].sort().join('\n'):'';
        const last=segments.at(-1);
        if(last&&last.signature===signature)last.raw+=token.raw;
        else segments.push({signature,raw:token.raw});
      }
      if(!segments.some(segment=>segment.signature))continue;
      original.set(file,content);
      const imports=[];
      segments.forEach((segment,index)=>{
        if(!segment.raw)return;
        const digest=hash(segment.raw).slice(0,20);
        const target=segment.signature
          ? `${group==='main'?'':group+'/'}common/wxss-shared/${digest}.wxss`
          : `${file.slice(0,-5)}.__wxss_part_${index}_${digest}.wxss`;
        assert(!assets[target],`Generated WXSS path already exists: ${target}`);
        if(generated.has(target))assert.strictEqual(generated.get(target),segment.raw);
        generated.set(target,segment.raw);
        let relative=path.posix.relative(path.posix.dirname(file),target);
        if(!relative.startsWith('.'))relative='./'+relative;
        imports.push(`@import ${JSON.stringify(relative)};`);
      });
      assets[file]=textAsset(imports.join('\n'));
      changed.push(file);
    }
  }
  for(const [file,content] of generated)assets[file]=textAsset(content);
  function expand(file,chain=[]){
    assert(!chain.includes(file),'WXSS import cycle');
    const content=assets[file].source().toString();
    if(generated.has(file))return content;
    const imports=[...content.matchAll(/@import\s+("(?:[^"\\]|\\.)*");/g)];
    return imports.map(match=>{
      const target=path.posix.normalize(path.posix.join(path.posix.dirname(file),JSON.parse(match[1])));
      assert(generated.has(target),'Unowned generated WXSS import');
      return expand(target,[...chain,file]);
    }).join('');
  }
  for(const [file,content] of original)assert.strictEqual(expand(file),content,`WXSS changed ${file}`);
  const after=sizes();
  return {before,after,changed:changed.map(file=>({file,originalSha256:hash(original.get(file)),expandedSha256:hash(expand(file))})),generated:[...generated].map(([file,content])=>({file,sha256:hash(content),bytes:Buffer.byteLength(content)}))};
}
class MemberMiniCssDedupePlugin{
  constructor(dependencies){this.dependencies=dependencies;}
  apply(compiler){
    let proof;
    compiler.hooks.emit.tap({name:'MemberMiniCssDedupe',stage:10000},compilation=>{
      proof=optimize(compilation.assets,this.dependencies);
      const oversized=Object.entries(proof.after).filter(([,bytes])=>bytes>2048*1024);
      if(oversized.length)throw new Error('Mini-program package source limit: '+JSON.stringify(oversized));
    });
    compiler.hooks.done.tap('MemberMiniCssDedupeProof',()=>{
      if(proof)fs.writeFileSync(compiler.options.output.path+'-css-dedupe.json',JSON.stringify(proof,null,2));
    });
  }
}
module.exports={MemberMiniCssDedupePlugin,optimize};
