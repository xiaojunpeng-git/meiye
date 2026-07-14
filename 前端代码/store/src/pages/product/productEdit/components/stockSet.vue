<template>
  <Modal
    v-model="stockModals"
    scrollable
    title="预约数量设置"
	width="526"
    :closable="false"
  >
    <Table :columns="timeColumns" :data="timeData" class="timeTable" border no-data-text="暂无数据"
           highlight-row no-filtered-data-text="暂无筛选结果" max-height="380" width='100%'>
    	<template slot-scope="{ row, index }" slot="time">
    		<div class="ml-34">{{row.start}}-{{row.end}}</div>
    	</template>
    	<template slot-scope="{ row, index }" slot="stock">
    		<InputNumber
    		  v-model="timeData[index].stock"
    		  :min="0"
    		  :max="99999999"
    		  :precision="0"
    		  v-width="129"
			  class="ml-45"
    		></InputNumber>
    	</template>
		<template slot-scope="{ row, index }" slot="service_price">
			<InputNumber
			  v-model="timeData[index].service_price"
			  :min="0"
			  :max="99999999"
			  v-width="129"
			></InputNumber>
		</template>
    </Table>
    <div slot="footer">
      <Button @click="cancel">取消</Button>
	  <Button type="primary" @click="submit">确认</Button>
    </div>
  </Modal>
</template>

<script>
export default {
  name: "changePrice",
  props: {
    timeData: {
      type: Array,
      default: () => ([]),
    }
  },
  data() {
    return {
	  stockModals: false,
	  tooltipVisible:false,//控制气泡的显示和隐藏（库存）
	  tooltipServiceVisible:false,//控制气泡的显示和隐藏（服务费）
	  timeInputNumberValue:0, //批量设置单规格库存值（库存）
	  timeServiceInputNumberValue:0, //批量设置单规格库存值（服务费）
	  timeColumns:[
	  		{
	  			title: "时段",
	  			slot: "time",
	  			align: "left",
	  			width: 186
	  		},
	  		{
	  			title: "预约数量",
	  			slot: "stock",
	  			align: "left",
	  			minWidth: 180,
	  			renderHeader: (h, row) => {
	  				return h('div', [
	  				  h('Poptip',{
	  					props:{
	  						transfer:true,
	  						placement: 'top',
	  						trigger:'click',
	  						value: this.tooltipVisible,
	  					},
	  					on: {
	  						'on-popper-hide': () => {
	  							this.tooltipVisible = false; // 气泡隐藏时设置 visible 为 false
	  						}
	  					},
	  					scopedSlots:{
	  						default: () => h('span', {
	  							on: {
	  							  click: ($event) => {
	  								$event.stopPropagation();
	  							    this.tooltipVisible = true; // 点击单元格时显示气泡
									this.tooltipServiceVisible = false;
									this.timeInputNumberValue = 0;
	  							  },
	  							}
	  						},[
	  							h('span', '预约数量'),
	  							h('span', {
	  								class: ['iconfont iconbianji11'],
	  								style: {
	  								  marginLeft: '6px',
	  								  color:'#AAAAAA',
	  								  fontSize:'12px'
	  								}
	  							})
	  						]),
	  					  content:()=>h('div',[
	  						h('div',{
	  							class:['fs-12 text-wlll-515A6E mb-12']
	  						},'批量修改'),
	  						h('InputNumber',{
	  							props:{
	  							  min:0,
	  							  max:99999999,
	  							  precision:0,
	  							  value: this.timeInputNumberValue
	  							},
	  							class:['w-85'],
	  							 on: {
	  								 'on-change': (value) => {
	  								   this.timeInputNumberValue = value;
	  								 }
	  							 }
	  						}),
	  						h('Button',{
	  							props:{
	  							  size:'small',
	  							},
	  							class:['ml-8'],
	  							on: {
	  							  click: () => {
	  								this.tooltipVisible = false;
	  							  }
	  							}
	  						},'取消'),
	  						h('Button',{
	  							props:{
	  							  type:'primary',
	  							  size:'small'
	  							},
	  							class:['ml-8'],
	  							on: {
	  							  click: () => {
	  								this.tooltipVisible = false;
	  							    this.handleButtonClick();
	  							  }
	  							}
	  						},'确定'),
	  					  ])
	  					},
	  				  })
	  				]);
	  			}
	  		},
			// {
			// 	title: "服务费",
			// 	slot: "service_price",
			// 	align: "center",
			// 	minWidth: 180,
			// 	renderHeader: (h, row) => {
			// 		return h('div', [
			// 		  h('Poptip',{
			// 			props:{
			// 				transfer:true,
			// 				placement: 'top',
			// 				trigger:'click',
			// 				value: this.tooltipServiceVisible,
			// 			},
			// 			on: {
			// 				'on-popper-hide': () => {
			// 					this.tooltipServiceVisible = false; // 气泡隐藏时设置 visible 为 false
			// 				}
			// 			},
			// 			scopedSlots:{
			// 				default: () => h('span', {
			// 					on: {
			// 					  click: ($event) => {
			// 						$event.stopPropagation();
			// 					    this.tooltipServiceVisible = true; // 点击单元格时显示气泡
			// 						this.tooltipVisible = false;
			// 						this.timeServiceInputNumberValue = 0;
			// 					  },
			// 					}
			// 				},[
			// 					h('span', '服务费'),
			// 					h('span', {
			// 						class: ['iconfont iconbianji11'],
			// 						style: {
			// 						  marginLeft: '6px',
			// 						  color:'#AAAAAA',
			// 						  fontSize:'12px'
			// 						}
			// 					})
			// 				]),
			// 			  content:()=>h('div',[
			// 				h('div',{
			// 					class:['fs-12 text-wlll-515A6E mb-12']
			// 				},'批量修改'),
			// 				h('InputNumber',{
			// 					props:{
			// 					  min:0,
			// 					  max:99999999,
			// 					  value: this.timeServiceInputNumberValue
			// 					},
			// 					class:['w-85'],
			// 					 on: {
			// 						 'on-change': (value) => {
			// 						   this.timeServiceInputNumberValue = value;
			// 						 }
			// 					 }
			// 				}),
			// 				h('Button',{
			// 					props:{
			// 					  size:'small',
			// 					},
			// 					class:['ml-8'],
			// 					on: {
			// 					  click: () => {
			// 						this.tooltipServiceVisible = false;
			// 					  }
			// 					}
			// 				},'取消'),
			// 				h('Button',{
			// 					props:{
			// 					  type:'primary',
			// 					  size:'small'
			// 					},
			// 					class:['ml-8'],
			// 					on: {
			// 					  click: () => {
			// 						this.tooltipServiceVisible = false;
			// 					    this.handleServiceButtonClick();
			// 					  }
			// 					}
			// 				},'确定'),
			// 			  ])
			// 			},
			// 		  })
			// 		]);
			// 	}
			// },
	  ],
    };
  },
  computed:{},
  mounted(){},
  methods: {
	// 单规格批量设置库存;
	handleButtonClick(){
		this.timeData.forEach(item=>{
			item.stock = this.timeInputNumberValue;
		})
	},
	handleServiceButtonClick(){
		this.timeData.forEach(item=>{
			item.service_price = this.timeServiceInputNumberValue;
		})
	},
    cancel() {
      this.stockModals = false;
    },
    submit() {
	  this.stockModals = false;
	  this.$emit('modalStockSet',this.timeData);
	},
  },
};
</script>

<style scoped lang="stylus">
	/deep/.ivu-table-header thead tr th{
		padding-left: 50px;
	}
</style>