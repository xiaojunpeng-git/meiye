"""Exercise the actual fixed mini-program with local, non-persistent API fixtures.
No production create/confirm/writeoff command can be called by this harness."""
import subprocess,json,pathlib
CLI='/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide';PROJECT='/private/tmp/m015-project-booking-final-008-mp-weixin-retry2/output';E=pathlib.Path(__file__).parent/'evidence'
def call(tool,**kw):
 args=[CLI,'-c','Codex',tool,'--project',PROJECT]
 for k,v in kw.items():args+=['--'+k.replace('_','-'),str(v)]
 r=subprocess.run(args,capture_output=True,timeout=40);x=json.loads(r.stdout);assert x['ok']and x['result']['success'],x
 return x['result'].get('result',{}).get('result',x['result'])
bootstrap="""function(){var p=getCurrentPages().slice(-1)[0];p.$vm.$store.state.app.token='M015-LOCAL-ONLY';p.$vm.$store.state.app.userInfo={uid:1,phone:'13800000000'};wx.requestSubscribeMessage=function(o){o.fail&&o.fail({errMsg:'Local test: notification declined'});};wx.setStorageSync('BASIC_CONFIG',{mobile_reservation_switch:1});wx.setStorageSync('viewColor','--view-theme:#7b2941;');wx.request=function(o){if(o.method&&o.method.toUpperCase()!=='GET')throw Error('Local test forbids API writes');var data={mobile_reservation_switch:1};var image='https://myqiniu.cc3798.com/attach/2026/10/cdb38202610242231172909.jpg';if(o.url.indexOf('v2/card/holder/')>=0){var gift=o.url.indexOf('/999')>=0;data={id:gift?999:6538,oid:6540,product_id:2768,product_type:gift?5:6,card_name:gift?'赠送护理项目':'八倍水动力（单次）',store_name:'海伦堡',write_times:1,write_surplus_times:1,status:1,write_valid:1,image:image,card_cover_image:'',verify_code:'LOCAL'}}else if(o.url.indexOf('card/order/benefits/')>=0)data=[{id:16651,product_id:2768,product_type:6,write_times:1,write_surplus_times:1,waiting_service:0,cart_info:{productInfo:{store_name:'八倍水动力（单次）',attrInfo:{image:image}}}}];else if(o.url.indexOf('reservation/orderInfo/')>=0)data={id:6540,cart_info_id:16651,cart_num:1,project_service_duration:60,product_id:2768,product_name:'八倍水动力（单次）',store_id:108,store_name:'海伦堡',unique:'test',custom_form:[],system_from_type:1};setTimeout(function(){o.success&&o.success({statusCode:200,data:{status:200,data:data}});o.complete&&o.complete({});},0);return {abort:function(){}}};return new Promise(function(resolve){wx.redirectTo({url:'/pages/users/user_card/index?id=6538',success:function(){resolve({localFixtures:true,productionRequests:false})}})})}"""
geometry="""function(){var p=getCurrentPages().slice(-1)[0],v=p.$vm;return new Promise(function(resolve){wx.createSelectorQuery().in(p).select('.project-reserve-button').boundingClientRect().select('.project-cover__image').boundingClientRect().exec(function(r){resolve({route:p.route,standalone:v.isStandaloneProject,canReserve:v.canReserveStandalone,cover:v.projectCoverImage,detailId:v.standaloneProject&&v.standaloneProject.id,button:r[0],image:r[1],width:wx.getSystemInfoSync().windowWidth,localFixtures:true,productionWrites:0})})})}"""
if __name__=='__main__':
 import sys
 if sys.argv[1]=='seed':print(call('automation_evaluate',fn_source=bootstrap))
 elif sys.argv[1]=='verify':
  a=call('automation_evaluate',fn_source=geometry);assert a['canReserve']and a['detailId']==16651 and a['image']['height']>0
  assert a['button']['width']>=a['width']*.38 and a['button']['right']<=a['width'],'Native footer width regressed'
  (E/'mini-runtime-final.json').write_text(json.dumps(a,ensure_ascii=False,indent=2)+'\n');print('NATIVE_PROJECT_COVER_AND_BUTTON_PASS',a)
 elif sys.argv[1]=='book':
  call('automation_element_action',action='tap',selector='.project-reserve-button');print('NATIVE_BOOKING_NAVIGATION_TAPPED')
 elif sys.argv[1]=='route':
  a=call('automation_evaluate',fn_source="function(){var p=getCurrentPages().slice(-1)[0],v=p.$vm;return {route:p.route,orderId:v.orderId,detailId:v.cartInfoId,duration:v.mainServiceDuration,options:p.options,localFixtures:true,productionWrites:0}}")
  assert a['route']=='pages/activity/reservation/index'and str(a['orderId'])=='6540'and str(a['detailId'])=='16651';(E/'mini-booking-route.json').write_text(json.dumps(a,ensure_ascii=False,indent=2)+'\n');print('NATIVE_ORIGINAL_BOOKING_ROUTE_PASS',a)
 else:raise ValueError('seed|verify|book|route')
