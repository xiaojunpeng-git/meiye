<template>
	<Drawer title="预约单" v-model="modals">
		<div class="acea-row">
			<div class="left bg-w111-F5F5F5 px-25">
				<div class="acea-row row-middle pointer h-52 border-b-1-EAEAEA fs-14 text-wlll-606266 pl-26">
					<div class="w-100">预约人</div>
					<div class="w-134">时段</div>
					<div class="w-78">模式</div>
					<div class="w-70">状态</div>
				</div>
				<div v-for="(item, index) in list" :key="index" @click="reservationTap(item.id)"
					:class="id==item.id?'on':''"
					class="acea-row row-middle pointer h-62 border-b-1-EAEAEA fs-14 text-wlll-303133 pl-26">
					<div class="w-100">{{item.reservation_name}}</div>
					<div class="w-134">{{item.reservation_start}}-{{item.reservation_end}}</div>
					<div class="w-78">{{item.reservation_type == 3?'上门':'到店'}}</div>
					<div class="w-70" v-if="item.status == -1">已取消</div>
					<div class="w-70" v-else-if="item.status == 0">待服务</div>
					<div class="w-70" v-else-if="item.status == 3">待确认</div>
					<div class="w-70" v-else-if="item.status == 1">进行中</div>
					<div class="w-70" v-else-if="item.status == 2">已完成</div>
				</div>
			</div>
			<div class="flex-1">
				<div class="conter ml-25 mr-25">
					<div class="acea-row row-middle mt-28 fs-13" v-if="info.cart_info">
						<div class="text-wlll-606266 w-84 text-right">预约服务：</div>
						<div
							class="flex-1 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">
							{{info.cart_info.productInfo.store_name}}</div>
					</div>
					<div class="acea-row row-middle mt-28 fs-13" v-if="info.cart_info">
						<div class="text-wlll-606266 w-84 text-right">服务规格：</div>
						<div
							class="flex-1 h-36 lh-36 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 line1">
							{{info.cart_info.productInfo.attrInfo.suk}}</div>
					</div>
					<div class="acea-row row-middle mt-28 fs-13">
						<div class="text-wlll-606266 w-84 text-right">联系人：</div>
						<Input v-model="info.reservation_name" :disabled='disabled' placeholder="请输入联系人" class="flex-1 ml-6"></Input>
					</div>
					<div class="acea-row row-middle mt-28 fs-13">
						<div class="text-wlll-606266 w-84 text-right">联系电话：</div>
						<Input v-model="info.reservation_phone" :disabled='disabled' type="number" placeholder="请输入联系电话" class="flex-1 ml-6"></Input>
					</div>
					<div class="acea-row row-middle mt-28 fs-13">
						<div class="text-wlll-606266 w-84 text-right">预约日期：</div>
						<DatePicker :transfer='true' v-model="info.reservation_time" :disabled='disabled' type="date" placeholder="选择预约日期" class="flex-1 ml-6"/>
					</div>
					<div class="acea-row row-middle mt-28 fs-13">
						<div class="text-wlll-606266 w-84 text-right">预约时间：</div>
						<Select :transfer='true' v-model="info.reservation_time_id" :disabled='disabled' placeholder="请选择预约时间" class="flex-1 ml-6" clearable>
							<Option :value ="item.id" v-for="(item,index) in reservationTime">{{item.show_time}}</Option>
						</Select>
					</div>
					<div class="acea-row row-middle mt-28 fs-13" v-if="info.reservation_type == 3">
						<div class="text-wlll-606266 w-84 text-right">上门地址：</div>
						<Cascader v-model="info.reservation_address_city_id" :disabled='disabled' :data="addresData" :load-data="loadData" @on-change="addchack" class="flex-1 ml-6"></Cascader>
					</div>
					<div class="acea-row row-middle mt-28 fs-13" v-if="info.reservation_type == 3">
						<div class="text-wlll-606266 w-84 text-right">详细地址：</div>
						<Input v-model="reservationAddress" :disabled='disabled' placeholder="请输入详细地址" class="flex-1 ml-6"></Input>
					</div>
					<div class="acea-row row-middle mt-28 fs-13">
						<div class="text-wlll-606266 w-84 text-right">服务人员：</div>
						<Select :transfer='true' v-model="service_staff_id" placeholder="请选择服务人员"
							class="flex-1 h-36 ml-6" clearable :disabled='info.status == 1'>
							<Option :value="item.value" v-for="(item,index) in staffList">{{item.label}}</Option>
						</Select>
					</div>
					<div class="acea-row row-middle mt-28 fs-13" v-if="info.service_room || info.table_name">
						<div class="text-wlll-606266 w-84 text-right">服务房间：</div>
						<Input :value="info.service_room || info.table_name" disabled class="flex-1 ml-6"></Input>
					</div>
					<div class="acea-row mt-28 fs-14" v-if="isShow && info.reservation_info.length">
						<div class="text-wlll-606266 w-84 text-right pt-5">{{info.custom_form_title}}信息：</div>
						<div class="acea-row flex-1 ml-6 border-1-DDDDDD bg-w111-F9F9F9 rd-4 px-6 text-wlll-303133 pb-24">
							<div v-for="(item,index) in info.reservation_info" :key="index">
								<div class="mr-48 mt-24" v-if="item.name === 'dateranges'">
									{{ item.titleConfig.value }}：{{ item.value[0]+'/'+item.value[1] }}
								</div>
								<div class="acea-row mt-24" v-else-if="item.name === 'uploadPicture'">
									<div>图片：</div>
									<div class="acea-row flex-1" v-viewer>
										<div class="w-58 h-58 mr-8 mb5" v-for="(img, i) in item.value" :key="i">
											<img class="w-full h-full rd-4" :src="img"/>
										</div>
									</div>
								</div>
								<div class="mr-48 mt-24" v-else>
									{{ item.titleConfig.value }}：{{ item.value || "-" }}
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="footer acea-row row-center-wrapper">
					<div v-if="disabled">
						<div class="acea-row row-center-wrapper" v-if="info.status == 0">
							<div @click="cancelTap" class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 pointer">取消预约</div>
							<div @click="disabled = false" class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 pointer ml-20">修改预约</div>
							<div @click="serviceStart" class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF pointer ml-20">开始服务</div>
						</div>
						<div class="acea-row row-center-wrapper" v-if="info.status == 3">
							<div @click="cancelTap" class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 pointer">取消预约</div>
							<div @click="disabled = false" class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 pointer ml-20">修改预约</div>
							<div @click="confirmTap" class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-FF7700 text-wlll-FFFFFF pointer ml-20">确认预约</div>
						</div>
						<div v-if="info.status == 1" @click="writeTap" class="acea-row row-center-wrapper w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF pointer ml-20">立即消耗</div>
					</div>
					<div v-else class="acea-row row-center-wrapper">
						<div class="w-176 h-46 rd-30px bg-w111-F5F5F5 acea-row row-center-wrapper text-wlll-606266 fs-16 pointer" @click="disabled = true">取消</div>
						<div class="w-176 h-46 rd-30px bg-w111-1890FF acea-row row-center-wrapper text-wlll-FFFFFF fs-16 pointer ml-20" @click="editTap">确定</div>
					</div>
				</div>
			</div>
		</div>
	</Drawer>
</template>

<script>
	import {
		getReservationOrder,
		getOrderDetail,
		getReservationStaffList,
		formatReservationStaffOptions,
		postOrderService,
		getReservationTime,
		cityApi,
		postOrderUpdate,
		postOrderConfirm,
		getReservationTableList,
	} from "@/api/reservation";
	export default {
		name: 'reservation',
		data() {
			return {
				modals: false,
				list: [],
				id: 0, //预约单列表id；
				info: {}, //预约单详情；
				staffList: [],
				service_staff_id: 0,
				disabled:true,
				reservationTime:[],
				addresData:[],
				reservationAddress:'', //上门详细地址
				regionAddress:'', //上门地址
				isShow: 0,
				tableList: [],
			}
		},
		mounted() {},
		methods: {
			addchack(e,selectedData){
				this.info.reservation_address_city_id = e;
				this.regionAddress = (selectedData.map(o => o.label)).join("/");
			},
			// 省市区数据
			cityInfo(data){
			    cityApi(data).then(res=>{
			        this.addresData = res.data
			    })
			},
			loadData(item, callback) {
			    item.loading = true;
			    cityApi({pid:item.value}).then(res=>{
			        item.children = res.data;
			        item.loading = false;
			        callback();
			    });
			},
			reservationTimeTap(id){
				getReservationTime(id).then(res=>{
					this.reservationTime = res.data;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			editTap(){
				if(!this.info.reservation_phone){
					return this.$Message.error('请输入联系电话')
				}
				if(!/^1(3|4|5|7|8|9|6)\d{9}$/.test(this.info.reservation_phone)){
					return this.$Message.error('请输入正确的联系电话');
				}
				if(!this.info.reservation_time){
					return this.$Message.error('请选择预约日期')
				}
				if(!this.info.reservation_time_id){
					return this.$Message.error('请选择预约时间')
				}
				if(this.info.reservation_type==3){
					if(!this.info.reservation_address_city_id.length){
						return this.$Message.error('请选择省市区')
					}
					if(!this.reservationAddress){
						return this.$Message.error('请输入上门地址')
					}
				}
				let address = this.regionAddress+'/'+this.reservationAddress;
				let data = {
					reservation_name:this.info.reservation_name,
					reservation_phone:this.info.reservation_phone,
					reservation_time:this.info.reservation_time,
					reservation_time_id:this.info.reservation_time_id,
					reservation_address:address
				}
				postOrderUpdate(this.info.id,data).then(res=>{
					this.$Message.success(res.msg);
					this.disabled = true;
					this.orderDetail(this.id);
				})
			},
			reservationOrder(id) {
				this.cityInfo({ pid: 0 });
				getReservationOrder({
					oid: id,
					status: 0
				}).then(res => {
					this.list = res.data.list;
					this.id = this.list[0].id;
					this.orderDetail(this.id);
					this.allStaffList();
				}).catch(err => {
					this.$Message.error(err.msg);
				})
			},
			writeTap() {
				let delfromData = {
					title: '消耗',
					url: `reservation/order/service/set/${this.info.id}`,
					method: "post",
					ids: {
						status: 2,
						service_staff_id: 0
					}
				};
				this.$modalSure(delfromData)
					.then((res) => {
						this.$Message.success(res.msg);
						this.modals = false;
						this.$emit('submitSuccess')
					})
					.catch((err) => {
						this.$Message.error(err.msg);
					});
			},
			allStaffList() {
				const serviceDate = this.info.reservation_time
					? String(this.info.reservation_time).split(' ')[0]
					: '';
				getReservationStaffList({
					store_id: this.info.store_id,
					service_date: serviceDate,
				}).then(res => {
					this.staffList = formatReservationStaffOptions(res.data.list || []);
				}).catch(err => {
					this.$Message.error(err.msg);
				})
			},
			serviceStart() {
				if (!this.service_staff_id) {
					return this.$Message.error('请选择服务人员');
				}
				let data = {
					status: 1,
					service_staff_id: this.service_staff_id
				}
				postOrderService(this.id, data).then(res => {
					this.$Message.success(res.msg);
					this.modals = false;
					this.$emit('submitSuccess')
				}).catch(err => {
					this.$Message.error(err.msg);
				})
			},
			cancelTap() {
				let delfromData = {
					title: '取消预约',
					url: `reservation/order/cancel/${this.id}`,
					method: "post",
				};
				this.$modalSure(delfromData)
					.then((res) => {
						this.$Message.success(res.msg);
						this.modals = false;
						this.$emit('submitSuccess')
					})
					.catch((err) => {
						this.$Message.error(err.msg);
					});
			},
			async confirmTap() {
				await this.loadTableList();
				let confirmTableId = null;
				this.$Modal.confirm({
					title: '接单确认',
					render: (h) => {
						return h('div', [
							h('div', { style: { marginBottom: '10px', color: '#606266' } }, '请选择服务房间（可不选）'),
							h(
								'Select',
								{
									props: {
										value: confirmTableId,
										clearable: true,
										transfer: true,
										placeholder: '选择房间号',
									},
									style: { width: '100%' },
									on: {
										'on-change': (val) => {
											confirmTableId = val;
										},
									},
								},
								this.tableList.map((item) =>
									h(
										'Option',
										{
											props: {
												value: item.id,
												key: item.id,
											},
										},
										item.remarks || item.table_number || `房间${item.id}`
									)
								)
							),
						]);
					},
					onOk: () => {
						const room = this.tableList.find((item) => Number(item.id) === Number(confirmTableId));
						return postOrderConfirm(this.id, {
							table_id: confirmTableId || 0,
							table_name: room ? room.remarks || String(room.table_number || '') : '',
						})
							.then((res) => {
								this.$Message.success(res.msg);
								this.modals = false;
								this.$emit('submitSuccess');
							})
							.catch((err) => {
								this.$Message.error(err.msg);
								return Promise.reject();
							});
					},
				});
			},
			loadTableList() {
				return getReservationTableList()
					.then((res) => {
						this.tableList = res.data || [];
					})
					.catch(() => {
						this.tableList = [];
					});
			},
			reservationTap(id) {
				this.id = id;
				this.orderDetail(this.id);
				this.loadTableList();
			},
			orderDetail(id) {
				this.reservationTimeTap(id);
				getOrderDetail(id).then(res => {
					this.info = res.data;
					let address = res.data.reservation_address.split(" ");
					this.reservationAddress = address[address.length-1];
					address.pop();
					this.regionAddress = address.join('/');
					let reservationInfo = this.info.reservation_info;
					if (reservationInfo.length) {
					  reservationInfo.forEach((item) => {
						  if (item.value) {
						    return (this.isShow = 1)
						  }
					  })
					}
					this.service_staff_id = res.data.service_staff_id;
				}).catch(err => {
					this.$Message.error(err.msg);
				})
			}
		}
	}
</script>

<style scoped lang="less">
	/deep/.ivu-drawer-body {
		padding: 0;
	}

	/deep/.ivu-drawer {
		width: 1200px !important;
	}

	/deep/.ivu-input{
		border-radius: 4px !important;
		height: 36px !important;
		line-height: 36px !important;
		padding-top: 0;
		padding-bottom: 0;
	}

	/deep/.ivu-select-single .ivu-select-selection {
		height: 36px !important;
		border-radius: 4px !important;
	}

	/deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value {
		height: 36px;
		line-height: 36px;
	}

	/deep/.ivu-select-disabled .ivu-select-selection{
		background-color: #F9F9F9;
		color: #303133;
	}
	/deep/.ivu-input[disabled], /deep/fieldset[disabled] .ivu-input{
		background-color: #F9F9F9;
		color: #303133;
	}

	.footer {
		box-shadow: 0px -1px 11px 0px rgba(0, 0, 0, 0.06);
		height: 90px;
	}

	.conter {
		height: calc(~'100vh - 142px');
		overflow: auto;
	}

	.left {
		height: calc(~'100vh - 51px');
		overflow: auto;
		width: 480px;
	}

	.pointer:has(+ .on) {
		border: 0 !important;
	}

	.on {
		background-color: #fff;
		border-radius: 8px;
		position: relative;
		border: 0 !important;

		&::before {
			position: absolute;
			left: 0;
			width: 5px;
			height: 62px;
			background-color: #1890FF;
			border-radius: 8px 0 0 8px;
			content: ' ';
		}
	}
</style>
