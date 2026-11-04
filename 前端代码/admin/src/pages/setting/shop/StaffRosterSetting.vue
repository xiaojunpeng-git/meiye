<template>
  <section class="roster-setting" aria-label="启用排班">
    <div class="roster-setting__row"><label>启用排班
      <i-switch v-model="value.enabled" :true-value="1" :false-value="0" size="large" :disabled="!loaded || saving || !!pending">
        <span slot="open">开启</span><span slot="close">关闭</span>
      </i-switch></label>
      <Button type="primary" :loading="saving" :disabled="!loaded || saving" @click="save">{{pending ? '重试本次保存' : '保存'}}</Button>
    </div>
    <p>关闭时沿用原预约规则；开启后，预约日历只显示当天上班人员，会员端、门店端和商家端统一按上班及休息时间预约，未排班或全天休息不可预约。</p>
    <p v-if="error" class="roster-setting__error" role="status">{{error}}</p>
  </section>
</template>
<script>
import request from '@/plugins/request';
// 单独保存一个参数，保留版本、幂等令牌和服务端回读，不提交其他系统设置。
export default {
  name:'StaffRosterSetting',
  data:()=>({value:{enabled:0,version:0},loaded:false,saving:false,error:'',pending:null}),
  created(){this.read();},
  methods:{
    normalize(data){if(!data||![0,1].includes(data.enabled)||!Number.isSafeInteger(data.version)||data.version<=0)throw new Error('排班参数返回无效');return data;},
    read(){this.loaded=false;return request({url:'setting/staff-roster',method:'get'}).then(res=>{this.value=this.normalize(res.data);this.loaded=true;}).catch(e=>{this.error=e.msg||e.message||'排班参数读取失败，请刷新后重试。';});},
    save(){
      if(!this.loaded||this.saving)return;
      if(!this.pending)this.pending={enabled:this.value.enabled,version:this.value.version,request_token:crypto.randomUUID()};
      this.saving=true;this.error='';
      return request({url:'setting/staff-roster',method:'put',data:{...this.pending}}).then(res=>{
        this.value=this.normalize(res.data);this.pending=null;this.$Message.success('启用排班已保存');
      }).catch(e=>{
        this.error=e.msg||e.message||'保存结果尚未确认，请重试本次保存。';
        // 业务拒绝可以刷新重试；网络结果不明时保留同一请求，禁止重复生成变更。
        if(e.status!==undefined){this.pending=null;this.read();}
      }).finally(()=>{this.saving=false;});
    }
  }
};
</script>
<style scoped>.roster-setting{padding:18px 0;border-bottom:1px solid #e8eaec;margin-bottom:18px}.roster-setting__row{display:flex;align-items:center;gap:16px}.roster-setting label{display:flex;align-items:center;gap:14px}.roster-setting p{font-size:12px;color:#888;margin:10px 0 0}.roster-setting .roster-setting__error{color:#c33;line-height:1.7}</style>
