<template>
  <section class="report-field-filters" aria-label="列表字段筛选">
    <div v-for="(filter,index) in value" :key="index" class="report-field-filter">
      <Select :value="filter.fieldKey" placeholder="选择列表字段" aria-label="筛选字段" @on-change="changeField(index,$event)">
        <Option v-for="field in fields" :key="field.key" :value="field.key">{{ field.title }}</Option>
      </Select>
      <Select :value="filter.operator" aria-label="筛选方式" @on-change="update(index,'operator',$event)">
        <Option v-for="op in operators(filter)" :key="op" :value="op">{{ labels[op] }}</Option>
      </Select>
      <template v-if="!['is_null','is_not_null'].includes(filter.operator)">
        <DatePicker v-if="field(filter).type==='datetime'" type="datetime" format="yyyy-MM-dd HH:mm:ss" :value="filter.value" placeholder="付款时间" @on-change="update(index,'value',$event)" />
        <Input v-else :value="filter.value" :placeholder="field(filter).type==='amount'?'金额（元）':'筛选值'" @on-change="update(index,'value',$event.target.value)" />
        <template v-if="filter.operator==='between'">
          <span>至</span>
          <DatePicker v-if="field(filter).type==='datetime'" type="datetime" format="yyyy-MM-dd HH:mm:ss" :value="filter.valueTo" placeholder="结束时间" @on-change="update(index,'valueTo',$event)" />
          <Input v-else :value="filter.valueTo" placeholder="最大金额（元）" @on-change="update(index,'valueTo',$event.target.value)" />
        </template>
      </template>
      <Button size="small" @click="remove(index)">移除</Button>
    </div>
    <Button size="small" :disabled="value.length>=20 || !fields.length" @click="add">添加条件</Button>
    <Button size="small" :disabled="!value.length" @click="$emit('input',[])">清空筛选</Button>
    <span class="filter-hint">同时满足全部条件，点击查询生效</span>
  </section>
</template>
<script>
/** Vue2 报表字段筛选编辑器：只编辑草稿，字段、类型和可用运算符由后端元数据提供。 */
export default {
  name: 'ReportFieldFilters',
  props: { value: { type: Array, default: () => [] }, fields: { type: Array, default: () => [] } },
  data: () => ({ labels: { equal:'等于',not_equal:'不等于',contains:'包含',greater_or_equal:'大于等于',less_or_equal:'小于等于',between:'介于',is_null:'为空',is_not_null:'不为空' } }),
  methods: {
    field(filter) { return this.fields.find(field => field.key===filter.fieldKey) || {}; },
    operators(filter) { return this.field(filter).operators || []; },
    /** 发出新数组，避免展开/输入就修改已提交的查询快照。 */
    update(index,key,value) { const next=this.value.map(item => ({...item})); next[index][key]=value; this.$emit('input',next); },
    changeField(index,key) { const next=this.value.map(item=>({...item})); const field=this.fields.find(item=>item.key===key); next[index]={fieldKey:key,operator:(field && field.operators[0])||'equal',value:'',valueTo:''}; this.$emit('input',next); },
    add() { if (!this.fields.length || this.value.length>=20) return; const f=this.fields[0]; this.$emit('input',this.value.concat({fieldKey:f.key,operator:f.operators[0],value:'',valueTo:''})); },
    remove(index) { this.$emit('input',this.value.filter((_,i)=>i!==index)); }
  }
};
</script>
<style scoped>
.report-field-filters{padding:12px 0;border-top:1px solid #eee}.report-field-filter{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px}.report-field-filter>.ivu-select{width:155px}.report-field-filter>.ivu-input-wrapper{width:210px}.report-field-filters>button{margin-right:8px}.filter-hint{color:#808695;font-size:12px}
</style>
