<script setup>
// Browser fixture only: render the production overlay and picker with deterministic paged responses.
import { ref } from 'vue'
import PersonnelPerformanceOverlay from '../../../前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue'
const records=ref([]),tree=ref([]),page=ref(1),total=ref(0),loading=ref(false),events=ref([])
const nodes=[{id:9100,node_type:'org',name:'测试集团',children:[{id:9200,node_type:'org',name:'测试事业部',children:[{id:9300,store_id:9300,node_type:'store',name:'大悦城测试店',children:[]}]},{id:9400,node_type:'org',name:'无门店直属组织',children:[]}]}]
const all=Array.from({length:24},(_,i)=>({id:1000000100+i,employeeId:100+i,name:i===0?'陈晓霞（小霞）':`测试手艺人${i}`,storeName:'大悦城测试店',selectable:true,personnelSource:'other'}))
async function search(q){events.value.push(q);loading.value=true;records.value=[];await Promise.resolve();tree.value=nodes;page.value=q.page||1;const result=q.organizationOptionsOnly?[]:q.keyword?all.filter(x=>x.name.includes(q.keyword)):q.organizationNode?.id===9400?[{...all[0],name:'直属测试人员',storeName:'组织直属'}]:all;total.value=result.length;records.value=result.slice((page.value-1)*20,page.value*20);loading.value=false}
</script>
<template>
<main style="height:100vh"><PersonnelPerformanceOverlay show-craftsmen :show-salespeople="false" allow-other-craftsmen other-craftsman-organization-enabled :craftsmen-candidates="[{id:1,name:'原有手艺人',storeName:'本店'}]" :other-craftsman-candidates="records" :other-craftsman-organization-tree="tree" :other-craftsman-allowed-store-ids="[9300]" :other-craftsman-page="page" :other-craftsman-total="total" :other-craftsman-loading="loading" @search-personnel="search" />
</main>
</template>
