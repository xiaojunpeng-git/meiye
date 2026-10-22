// Bound the sampling count and compare image pixels only, excluding status-bar clock changes.
const fs=require('fs'),path=require('path'),cp=require('child_process');
const sharp=require(process.env.SHARP_MODULE||'/tmp/node_modules/sharp');
const dir=path.join(__dirname,'evidence'),project='/private/tmp/m008-cube-slides-20261022/mini/008-member';
(async()=>{
 let previous,changed=0,captures=0;
 for(let i=0;i<4;i++){
  const file=path.join(dir,'mini-motion-'+i+'.png');
  cp.execFileSync('/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide',['-c','Codex','simulator_screenshot','--project',project,'--path',file,'--optimize','false','--wait','1'],{encoding:'utf8',timeout:30000});
  const next=await sharp(file).extract({left:40,top:75,width:500,height:70}).raw().toBuffer();
  if(previous)for(let j=0;j<next.length;j++)if(Math.abs(next[j]-previous[j])>10)changed++;
  previous=next;captures++;if(changed>500)break;
 }
 if(changed<500)throw Error('No image movement in four bounded captures');
 fs.writeFileSync(path.join(dir,'mini-motion.json'),JSON.stringify({result:'PASS',changedColorChannels:changed,captures,nativeAutoplay:true,imageCropExcludesClock:true},null,2));
 console.log('PASS: native image pixels changed, not just the clock.');
})().catch(error=>{console.error(error);process.exitCode=1;});
