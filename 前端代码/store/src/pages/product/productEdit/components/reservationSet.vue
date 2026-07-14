<template>
	<div>
	   <FormItem label="预约模式：">
	     <RadioGroup v-model="baseInfo.reservation_type">
	       <Radio :label="1">到店服务+上门服务</Radio>
	       <Radio :label="2">到店服务</Radio>
	       <Radio :label="3">上门服务</Radio>
	     </RadioGroup>
	   </FormItem>
	   <FormItem label="预约时机：">
	     <RadioGroup v-model="baseInfo.reservation_timing_type">
	       <Radio :label="1">购买时预约+先买后约</Radio>
	       <Radio :label="2">购买时预约</Radio>
	       <Radio :label="3">先买后约</Radio>
	     </RadioGroup>
		 <div class="fs-12 text--w111-999">
			购买时预约：用户需选择预约时间后再提交预约订单；先买后约：支持用户先购买预约服务，再选择合适的预约时间。
		 </div>
	   </FormItem>
	   <FormItem label="可售日期：">
		   <RadioGroup v-model="baseInfo.sale_time_type">
		     <Radio :label="1">每天</Radio>
		     <Radio :label="2">每周</Radio>
		     <Radio :label="3">自定义时间</Radio>
		   </RadioGroup>
		   <div class="acea-row row-middle" v-if="baseInfo.sale_time_type == 2">
			   <div v-for="(item,index) in weekList" @click="weekTap(item)" class="week w-60 h-32 fs-14 rd-5px acea-row row-center-wrapper mr-10 mt10 cup" :class="item.selected?'on':''">{{item.name}}</div>
		   </div>
		   <div v-else-if="baseInfo.sale_time_type == 3" class="mt10">
			   <DatePicker
			     type="daterange"
			   	 placeholder="选择日期" 
			   	 class="w-250" 
			   	 v-model="baseInfo.sale_time_data"
			   	 format="yyyy-MM-dd"
			   	 @on-change="onchangeData"
			   	></DatePicker>
		   </div>
		   <div class="fs-12 text--w111-999">
		     设置预约服务的可预约日期。
		   </div>
	   </FormItem>
	   <FormItem label="显示日期：">
		   <RadioGroup v-model="baseInfo.show_reservation_days_type">
		     <Radio :label="1">全部展示</Radio>
		     <Radio :label="2">自定义展示时间</Radio>
		   </RadioGroup>
		   <div class="mt10" v-if="baseInfo.show_reservation_days_type == 2">
			   对用户展示
		       <InputNumber 
			   :min="0" 
			   :max="99999999"
    		   :precision="0" 
			   v-model="baseInfo.show_reservation_days" 
			   />
			  天内的可预约日期
		   </div>
		   <div class="fs-12 text--w111-999">
		     用户端可以看到的可预约日期。示例：设置1天，则用户最多可以选择的预约日期为第二天。
		   </div>
	   </FormItem>
	   <FormItem label="提前预约：">
		   <RadioGroup v-model="baseInfo.is_advance" vertical>
		     <Radio :label="0">无需提前</Radio>
		     <Radio :label="1">
				提前
				<InputNumber
				:min="0" 
				:max="99999999"
				:precision="0" 
				v-model="baseInfo.advance_time" 
				/>
				小时预约
			 </Radio>
		   </RadioGroup>
		   <div class="fs-12 text--w111-999 mt10">
		     用户只能预约间隔时间后的时段。示例：当前10:00,设置2h，则用户只可预约12:00往后的时段
		   </div>
	   </FormItem>
	   <FormItem label="取消预约：">
		 <RadioGroup v-model="baseInfo.is_cancel_reservation" vertical>
		   <Radio :label="0">不允许取消</Radio>
		   <Radio :label="1">
			   服务开始
			   <InputNumber
			   :min="0" 
			   :max="99999999"
			   :precision="0" 
			   v-model="baseInfo.cancel_reservation_time" 
			   />
			   小时之前，允许取消并自动退款
		   </Radio>
		 </RadioGroup>
		 <div class="fs-12 text--w111-999 mt10">
		   设置用户最晚可以取消预约的时间。示例：设置2h，用户预约12:00-14:00，则当天10:00之前允许用户取消预约
		 </div>
	   </FormItem>
	</div>
</template>

<script>
	export default{
		name: 'reservationSet',
		props: {
		  baseInfo: {
		    type: Object,
		    default: () => ({}),
		  },
		},
		data(){
			return {
				weekList:[
					{id:1,name:'周一',selected:true},
					{id:2,name:'周二',selected:true},
					{id:3,name:'周三',selected:true},
					{id:4,name:'周四',selected:true},
					{id:5,name:'周五',selected:true},
					{id:6,name:'周六',selected:false},
					{id:0,name:'周天',selected:false}
				]
			}
		},
		methods: {
			weekTap(item){
				item.selected = !item.selected;
				this.$emit('weekData',this.weekList);
			},
			onchangeData(e){
				this.baseInfo.sale_time_start = e
			}
		}
	}
</script>

<style scoped>
	.week{
		background-color: #fff;
		border: 1px solid #dcdee2;
		color: #333;
		&.on{
			background-color: #2d8cf0;
			border-color: #2d8cf0;
			color: #fff;
		}
	}
</style>