// Execute the real page methods; occupied rights must stay searchable but inert.
const assert=require('assert/strict'),load=require('../r15-reservation-remove/harness.cjs');
const h=load(),vm=h.create({cartInfoId:1,$util:{Tips:()=>{throw Error('No generic popup expected');}},loadStaffList:()=>Promise.resolve()});
const row=(id,disabled=false)=>({id,product_type:6,write_surplus_times:disabled?0:1,is_writeoff:0,card_presentation:{disabled,disabled_reason:disabled?'已预约占用':'',remaining_text:'剩余1次'},cart_info:{productInfo:{store_name:'同名护理',addon_service_duration:30}}});
vm.addonPickerOptions=vm.parsePurchasedAddonOptions([{order:{id:2,type:12,product_type:6},cart_info:[row(1),row(2,true),row(3)]}]);
assert.equal(vm.addonPickerOptions.length,2,'main excluded, occupied retained');
const [blocked,open]=vm.addonPickerOptions;assert.equal(blocked.disabledReason,'已预约占用');
vm.addonPickerSelected=[];vm.toggleAddonItem(blocked);assert.equal(vm.addonPickerSelected.length,0);
vm.toggleAddonItem(open);assert.deepEqual(vm.addonPickerSelected,[open._key]);
vm.addonPickerSearch='同名护理';assert.equal(vm.filteredAddonPickerOptions.length,2);
vm.addonItems=[blocked];vm.addonPickerSelected=[blocked._key,open._key];vm.confirmAddonPicker();assert.equal(vm.addonItems.length,1);assert.equal(vm.addonItems[0]._key,open._key);
const restored=vm.parsePurchasedAddonOptions([{order:{id:2,type:12,product_type:6},cart_info:[row(2)]}])[0];vm.addonPickerSelected=[];vm.toggleAddonItem(restored);assert.deepEqual(vm.addonPickerSelected,[restored._key]);
console.log('PASS occupied visible/searchable, main excluded, disabled taps and stale draft blocked, cancellation restores selection');
