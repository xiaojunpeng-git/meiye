// Property coverage for the real shared scene decoder, without Node-only helpers in app code.
const fs=require('fs'),vm=require('vm'),crypto=require('crypto'),path=require('path');
const root=path.resolve(__dirname,'../../..'),context={};vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(root,'前端代码/uniapp/utils/memberSurveyScene.js'),'utf8').replace('export function','function'),context);
let checked=0;for(const token of ['0'.repeat(48),'f'.repeat(48),'a'.repeat(48),...Array.from({length:200},()=>crypto.randomBytes(24).toString('hex'))]){
 const scene=Buffer.from(token,'hex').toString('base64url');if(scene.length!==32||context.surveyEntryToken({scene})!==token||context.surveyEntryToken({entry:token})!==token)throw Error('Scene lost token identity');checked++;
}
for(const scene of ['short','x'.repeat(33),'!'.repeat(32),'%E0%A4']){let rejected=false;try{context.surveyEntryToken({scene});}catch(e){rejected=true;}if(!rejected)throw Error('Malformed scene accepted');checked++;}
const evidence=path.join(root,'tests/customer-010/evidence/round12-survey-miniprogram/scene.json');fs.writeFileSync(evidence,JSON.stringify({passed:true,checks:checked,token_identity_preserved:true},null,2));console.log('SCENE PASS',checked);
