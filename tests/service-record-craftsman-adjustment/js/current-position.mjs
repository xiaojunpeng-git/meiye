import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {automaticCraftsmanAmounts} from '../../../前端代码/cashier-v3/src/services/personnelDefaultAllocation.js';
const source=fs.readFileSync('前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue','utf8');
const state={props:{historyAdjustment:true},craftsmen:{value:[]},historyTotalCents:{value:7700},validationMessage:{value:''},automaticCraftsmanAmounts};
vm.createContext(state);
for(const [from,to] of [['function historyPerformanceRows()','function syncHistoryAmountFromRatio'],['function syncHistoryAmountFromRatio','function normalizeProjectCount']])vm.runInContext(source.slice(source.indexOf(from),source.indexOf(to)),state);
const row=(id,group)=>({id,selected:true,performance:100,allocationGroupKey:group,allocationAmountCents:3850,allocationAmountYuan:'38.5'});
state.craftsmen.value=[row(1,'normal'),row(2,'independent:8')];state.distributeHistoryAmountsByRatio();assert.deepEqual(state.craftsmen.value.map(x=>x.allocationAmountCents),[7700,7700]);
state.craftsmen.value=[row(1,'independent:8'),row(2,'independent:8')];state.craftsmen.value.forEach(x=>x.performance=50);state.distributeHistoryAmountsByRatio();assert.deepEqual(state.craftsmen.value.map(x=>x.allocationAmountCents),[3800,3900]);
const first=state.craftsmen.value[0];first.allocationAmountYuan='20';state.syncHistoryRatioFromAmount(first);state.distributeHistoryAmountsByRatio();assert.equal(first.allocationAmountCents,2000);assert.equal(first.allocationAmountManual,true);assert.equal(first.performance,50);
first.performance=100;state.syncHistoryAmountFromRatio(first);assert.equal(first.allocationAmountManual,false);assert.equal(first.allocationAmountCents,7700);
assert.match(source,/if \(props.historyAdjustment\) distributeHistoryAmountsByRatio\(\)/);
console.log('PASS current independent groups 77/77; same group 38/39; manual amount; ratio returns to automatic; support selection recalculates');
// Both entry modes must apply the same current fee/count eligibility helpers.
vm.runInContext(source.slice(source.indexOf('function configuredCraftsmanType'),source.indexOf('function defaultLaborFeeCents')),state);
for (const history of [false,true]) {
  state.props.historyAdjustment=history;
  assert.equal(state.laborFeeLocked({craftsmanPerformanceType:'commission'}),true);
  assert.equal(state.laborFeeLocked({craftsmanPerformanceType:'commission_labor'}),false);
  assert.equal(state.servicePositionExcluded({serviceCountsExcluded:true}),true);
  assert.equal(state.laborFeeLocked({serviceCountsExcluded:true}),true);
}
console.log('PASS checkout and adjustment share current hand-fee type and service-count exclusion rules');
