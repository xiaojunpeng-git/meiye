<template>
  <section class="activity-flow" :class="{'activity-flow--fixed':layout.fixed}">
    <div class="activity-flow-query">
      <div class="activity-flow-toolbar">
        <OrganizationStoreScopePicker v-model="draft.store_ids" :load-scope="loadScope" />
        <DatePicker v-model="draft.dates" type="daterange" format="yyyy-MM-dd" placeholder="付款日期范围" style="width:220px" />
        <Button @click="more=!more">更多筛选</Button><Button type="primary" :loading="loading" @click="search">查询</Button>
        <span class="activity-flow-spacer" />
        <Button @click="sourcesOpen=true">列名取值来源</Button><Button :disabled="loading || !applied" @click="refresh">刷新</Button>
        <Button :loading="exporting" :disabled="loading || !applied" @click="exportRows">导出</Button>
      </div>
      <ReportFieldFilters v-show="more" v-model="draft.filters" :fields="metadata" />
      <p v-if="error" class="activity-flow-error" role="status">{{ error }}</p>
    </div>
    <div ref="results" class="activity-flow-results">
      <Table :columns="columns" :data="displayRows" :loading="loading" :max-height="layout.fixed ? tableHeight : undefined" :row-class-name="rowClass" no-data-text="暂无活动流水">
        <template slot="amount" slot-scope="{row}"><span class="flow-money">{{ money(row.paid_amount) }}</span></template>
        <template slot="action" slot-scope="{row}">
          <template v-if="!row.is_summary">
            <Button v-if="row.refund_action" size="small" :disabled="!!refundBusy" @click="openRefund(row)">{{ row.refund_action==='query'?'查询退款结果':(row.refund_action==='retry'?'重试退款':'退款') }}</Button>
            <span v-else class="flow-block-reason" :title="row.refund_block_reason">{{ row.refund_block_reason || '—' }}</span>
          </template>
        </template>
      </Table>
    </div>
    <footer class="activity-flow-pagination">
      <span>共 {{ total }} 条 · 每页 20 条 · 第 {{ page }} / {{ totalPages }} 页</span>
      <Button size="small" :disabled="loading || page<=1" @click="load(page-1)">上一页</Button>
      <Button size="small" :disabled="loading || page>=totalPages" @click="load(page+1)">下一页</Button>
    </footer>
    <Modal v-model="sourcesOpen" title="列名取值来源" footer-hide width="760">
      <p v-for="column in metadata" :key="column.key" class="flow-source"><strong>{{ column.title }}：</strong>{{ column.source }}</p>
      <p class="flow-source"><strong>合计金额：</strong>按已查询的日期、门店及全部字段筛选，对完整命中记录的原付款金额合计，含已退款订单；不是净收款。分页不影响合计。</p>
    </Modal>
    <Modal v-model="refundOpen" title="原订单退款" :mask-closable="false" :closable="!refundBusy" footer-hide>
      <p>会员：{{ refundRow.member_name }}　原单实付：{{ money(refundRow.paid_amount) }} 元</p>
      <p>订单：{{ refundRow.business_order_no }}</p>
      <p class="flow-source">按原渠道全额退款。提交时重新校验原订单、使用情况及权限；已成团订单需到拼团列表办理整团退款。</p>
      <div ref="refundReasonArea" data-required-key="activity-refund-reason">
        <Input v-model="refundReason" type="textarea" :maxlength="300" placeholder="退款原因（必填）" aria-label="退款原因" />
      </div>
      <p v-if="refundError" class="activity-flow-error" role="status">{{ refundError }}</p>
      <Button type="error" :loading="!!refundBusy" :disabled="!!refundBusy" style="margin-top:12px" @click="submitRefund">确认{{ refundRow.refund_action==='retry'?'重试退款':'退款' }}</Button>
    </Modal>
  </section>
</template>
<script>
import OrganizationStoreScopePicker from '@/components/organization/OrganizationStoreScopePicker.vue';
import ReportFieldFilters from '@/components/query/ReportFieldFilters.vue';
import { combinationActivityFlowApi, combinationActivityFlowRefundApi } from '@/api/marketing';
import formatBusinessMoney from '@/utils/formatBusinessMoney.js';
import exportBusinessReport from '@/utils/exportBusinessReport.js';
import { guideRequired, clearRequiredGuidance } from '@/utils/requiredGuidance.js';

/** 活动流水只展示后端投影；查询草稿与已提交条件分开，分页/刷新/导出不读未查询输入。 */
export default {
  name:'CombinationActivityFlow', components:{OrganizationStoreScopePicker,ReportFieldFilters},
  props:{activityId:{type:[String,Number],required:true}},
  data:()=>({draft:{store_ids:[],dates:[],filters:[]},applied:null,metadata:[],rows:[],summary:null,total:0,page:1,
    more:false,loading:false,exporting:false,error:'',layout:{fixed:false},tableHeight:360,requestId:0,sourcesOpen:false,
    refundOpen:false,refundRow:{},refundReason:'',refundError:'',refundBusy:0,optionsPromise:null}),
  computed:{
    totalPages(){return Math.max(1,Math.ceil(this.total/20));},
    columns(){return this.metadata.map(c=>({...c,slot:c.key==='paid_amount'?'amount':undefined,tooltip:c.type==='text'}))
      .concat([{title:'操作',slot:'action',width:210,fixed:this.layout.fixed?'right':''}]);},
    /** 合计行不是订单，不参与计数或退款；即使空结果也明确显示零合计。 */
    displayRows(){if(!this.summary)return this.rows;const row={is_summary:true};this.metadata.forEach(c=>{row[c.key]='—';});row.member_name='合计金额';row.paid_amount=this.summary.paid_amount;return [row,...this.rows];}
  },
  created(){this.init();},
  mounted(){this.resizeObserver=new ResizeObserver(()=>this.fitTable());this.resizeObserver.observe(this.$el);this.resizeObserver.observe(this.$el.querySelector('.activity-flow-query'));this.fitTable();},
  beforeDestroy(){this.requestId++;clearRequiredGuidance();if(this.resizeObserver)this.resizeObserver.disconnect();},
  methods:{
    money:formatBusinessMoney,
    /** 元数据和双栏范围选择器共用一次后端请求，失败后允许明确重试。 */
    options(){if(!this.optionsPromise)this.optionsPromise=combinationActivityFlowApi(this.activityId,{view:'options'}).then(({data})=>{this.metadata=data.columns;this.layout=data.table_layout;return data;}).catch(e=>{this.optionsPromise=null;throw e;});return this.optionsPromise;},
    loadScope(){return this.options().then(data=>data.scope);},
    async init(){try{await this.options();this.search();}catch(e){this.error=e.msg||e.message||'流水配置加载失败';}},
    formatDate(value){if(!value)return '';if(typeof value==='string')return value.slice(0,10);return [value.getFullYear(),String(value.getMonth()+1).padStart(2,'0'),String(value.getDate()).padStart(2,'0')].join('-');},
    /** 当前页只有查询动作可以替换筛选快照；日期与字段值按后端稳定key传递。 */
    search(){this.applied={store_ids:this.draft.store_ids.slice(),date_from:this.formatDate(this.draft.dates[0]),date_to:this.formatDate(this.draft.dates[1]),filters:JSON.stringify(this.draft.filters.map(f=>({fieldKey:f.fieldKey,operator:f.operator,value:['is_null','is_not_null'].includes(f.operator)?null:f.value,...(f.operator==='between'?{valueTo:f.valueTo}:{})})))};this.load(1);},
    async load(page){if(!this.applied)return;const id=++this.requestId;this.loading=true;this.error='';try{const {data}=await combinationActivityFlowApi(this.activityId,{...this.applied,page});if(id!==this.requestId)return;this.rows=data.list;this.summary=data.summary;this.total=data.count;this.page=data.page;this.layout=data.table_layout;}catch(e){if(id!==this.requestId)return;this.rows=[];this.summary=null;this.total=0;this.page=1;this.error=e.msg||e.message||'活动流水加载失败';}finally{if(id===this.requestId){this.loading=false;this.$nextTick(this.fitTable);}}},
    refresh(){this.load(this.page);},
    async exportRows(){if(!this.applied)return;this.exporting=true;try{const {data}=await combinationActivityFlowApi(this.activityId,{...this.applied,view:'export'});exportBusinessReport(data);}catch(e){this.$Message.error(e.msg||e.message||'活动流水导出失败');}finally{this.exporting=false;}},
    rowClass(row){return row.is_summary?'activity-flow-summary':'';},
    fitTable(){this.$nextTick(()=>{if(this.$refs.results)this.tableHeight=Math.max(180,this.$refs.results.clientHeight);});},
    /** 查询原退款只走现有凭据；新退款/失败重试必须由用户填写原因并确认。 */
    async openRefund(row){if(this.refundBusy)return;if(row.refund_action==='query'){await this.performRefund(row,'查询原退款结果');return;}this.refundRow=row;this.refundReason='';this.refundError='';this.refundOpen=true;},
    /** 首个必填项缺失时复用共享定位引导，不弹通用阻断提示，也不自动填写原因。 */
    async submitRefund(){if(this.refundBusy)return;if(!this.refundReason.trim()){const area=this.$refs.refundReasonArea;guideRequired(area && area.parentNode,'activity-refund-reason','请在这里填写退款原因');return;}await this.performRefund(this.refundRow,this.refundReason.trim());},
    async performRefund(row,reason){this.refundBusy=row.order_id_key;this.refundError='';try{const {data}=await combinationActivityFlowRefundApi(this.activityId,{order_id:row.order_id_key,reason,retry:row.refund_action==='retry'});this.$Message.info(data.message||'退款请求已处理，请核对结果');this.refundOpen=false;await this.load(this.page);}catch(e){const message=e.msg||e.message||'退款处理失败';if(this.refundOpen)this.refundError=message;else this.$Message.error(message);}finally{this.refundBusy=0;}}
  }
};
</script>
<style scoped>
.activity-flow{display:flex;flex-direction:column;background:#fff;min-height:380px}.activity-flow--fixed{height:calc(100vh - 440px);min-height:380px}.activity-flow-query{flex:none}.activity-flow-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:0 0 12px}.activity-flow-spacer{flex:1}.activity-flow-results{flex:1;min-height:0;overflow:hidden}.activity-flow-pagination{flex:none;border-top:1px solid #eee;padding:12px 0;display:flex;align-items:center;justify-content:flex-end;gap:10px}.activity-flow-error{color:#b94138;padding:8px 0}.flow-money{color:#1f5f8b}.flow-block-reason{font-size:12px;color:#808695;white-space:normal}.flow-source{line-height:1.8;margin:8px 0}.activity-flow-results /deep/ .activity-flow-summary td{position:sticky;top:0;z-index:2;background:#edf5fc;font-weight:600}.activity-flow-results /deep/ .ivu-table-cell{word-break:break-all}.activity-flow-results /deep/ .ivu-table-body{overflow:auto!important}.activity-flow-results /deep/ .ivu-table-fixed-body{overflow:hidden!important}
</style>
