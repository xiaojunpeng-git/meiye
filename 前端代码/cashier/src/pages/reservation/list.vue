<template>
	<div class="reservation">
		<div class="w-full bg-w111-FFFFFF rd-20 px-24 pt-24 acea-row row-between">
			<Form
			  ref="formValidate"
			  :model="formValidate"
			  :label-width="labelWidth"
			  :label-position="labelPosition"
			  @submit.native.prevent
			  inline
			>
			  <FormItem label="客户手机号："  label-for="phone">
				<Input v-model="formValidate.phone" clearable placeholder="请输入手机号" class="w-129 h-40"></Input>
			  </FormItem>
			  <FormItem label="手艺人："  label-for="service_staff_id">
			  	<Select v-model="formValidate.service_staff_id" placeholder="请选择" class="w-129 h-40" clearable @on-change="searchs">
			  		<Option :value ="item.value" v-for="(item,index) in staffList">{{item.label}}</Option>
			  	</Select>
			  </FormItem>
			  <FormItem label="预约日期："  label-for="reservation_time">
				<div class="acea-row row-center-wrapper">
				   <DatePicker v-model="formValidate.reservation_time" type="date" placeholder="选择日期" class="w-144 h-40" @on-change="searchs($event,1)"/>
				</div>
			  </FormItem>
			  <FormItem label="预约状态："  label-for="status">
			  	<Select v-model="formValidate.status" placeholder="请选择" class="w-129 h-40" clearable @on-change="searchs">
			  		<Option :value ="item.id" v-for="(item,index) in reservationStatus">{{item.name}}</Option>
			  	</Select>
			  </FormItem>
              <FormItem :label-width="0">
				<div class="acea-row row-center-wrapper ml-24">
				   <div class="w-67 h-40 rd-6 bg-w111-1890FF fs-14 text-wlll-FFFFFF acea-row row-center-wrapper pointer" @click="searchs">搜索</div>
				   <div class="w-96 h-40 rd-6 add-reservation-btn fs-14 text-wlll-FFFFFF acea-row row-center-wrapper ml-16 pointer" @click="addReservationTap">添加预约</div>
				   <div class="w-67 h-40 rd-6 bg-w111-F5F5F5 fs-14 text-wlll-303133 acea-row row-center-wrapper ml-16 pointer" @click="exports" v-if="active && orderList.length">导出</div>
				</div>
			  </FormItem>
			</Form>
			<div class="acea-row row-around row-middle w-142 h-48 bg-w111-F5F5F5 fs-16 text-wlll-999999 rd-50 mt-f3">
				<div class="nav" :class="active == index?'on':''" v-for="(item,index) in navList" :key="index" @click="navTap(index)">{{item.name}}</div>
			</div>
		</div>
		<Card :bordered="false" dis-hover class="mt-20 rd-20" v-if="active">
			<div class="btnbox"></div>
			<div class="table">
				<Table :columns="columns" :data="orderList" ref="table"
				       :loading="loading" highlight-row
				       no-userFrom-text="暂无数据"
				       no-filtered-userFrom-text="暂无筛选结果">
					  <template slot-scope="{ row, index }" slot="goods_info">
					  	 <div class="acea-row row-middle" v-if="row.cart_info && row.cart_info.productInfo">
							<div class="w-40 h-40 rd-4 mr-6">
								<img class="w-full h-full" :src="row.cart_info.productInfo.image"/>
							</div>
							<div class="acea-row row-middle">
								<div class="mr-6 fs-12 text-wlll-2A7EFB w-36 h-23 rd-4 acea-row row-center-wrapper border-1-2A7EFB">到店</div>
								<div class="w-215 line1">{{ row.cart_info.productInfo.store_name }}</div>
							</div>
						 </div>
						 <div v-else class="text-wlll-909399">—</div>
					  </template>
					  <template slot-scope="{ row, index }" slot="reservation_info">
						  <div class="h-45">
							<div>{{row.reservation_name}}</div>
							<div class="fs-12 text-wlll-909399 mt-6">{{row.reservation_phone}}</div>
						  </div>
					  </template>
					  <template slot-scope="{ row, index }" slot="reservation_time">
					      {{row.reservation_start}}-{{row.reservation_end}}
					  </template>
					  <template slot-scope="{ row, index }" slot="reservation_status">
					      <div v-if="row.status == 0" class="w-58 h-28 rd-4 text-wlll-2A7EFB bg-w111-1890FF-8 acea-row row-center-wrapper">{{row.status_name}}</div>
						  <div v-else-if="row.status == 3" class="w-58 h-28 rd-4 reservation-status-confirm acea-row row-center-wrapper">{{row.status_name}}</div>
						  <div v-else-if="row.status == 1" class="w-58 h-28 rd-4 text-wlll-FF8D30 bg-w111-FF8D30-8 acea-row row-center-wrapper">{{row.status_name}}</div>
						  <div v-else-if="row.status == 2" class="w-58 h-28 rd-4 text-wlll-23C471 bg-w111-23C471-8 acea-row row-center-wrapper">{{row.status_name}}</div>
						  <div v-else-if="row.status == 4" class="w-58 h-28 rd-4 text-wlll-F95E45 bg-w111-F95E45-8 acea-row row-center-wrapper">{{row.status_name}}</div>
						  <div v-else class="w-58 h-28 rd-4 text-wlll-F95E45 bg-w111-F95E45-8 acea-row row-center-wrapper">{{row.status_name}}</div>
					  </template>
					  <template slot-scope="{ row, index }" slot="action">
					      <a @click="detailsTap(row.id)">详情</a>
						  <a class="ml-12" v-if="row.status == -1" @click="del(row,'删除预约单',index)">删除</a>
						  <span v-if="row.status == 3">
							 <a class="ml-12" @click="confirmTap(row)">接单</a>
							 <a class="ml-12" @click="refuseTap(row)">拒绝</a>
							 <a class="ml-12" @click="editTap(row,1)">修改</a>
							 <a class="ml-12" @click="cancelTap(row)">取消预约</a>
						  </span>
						  <span v-if="row.status == 0">
							 <a class="ml-12" @click="editTap(row,1)">修改</a>
							 <a class="ml-12" @click="editTap(row,2)">开始服务</a>
							 <a class="ml-12" @click="cancelTap(row)">取消预约</a>
						  </span>
						  <a class="ml-12" v-if="row.status == 1" @click="writeTap(row)">立即消耗</a>
					  </template>
				</Table>
			</div>
			<div class="acea-row row-right page">
			  <Page
			    :total="total"
			    :current="formValidate.page"
			    show-elevator
			    show-total
			    @on-change="pageChange"
			    :page-size="formValidate.limit"
			  />
			</div>
		</Card>
    <Card :bordered="false" dis-hover class="mt-20 board-card" v-else>
      <reserveBoard ref="reserveBoard" :reservation_time="formValidate.reservation_time" :reservation_phone="formValidate.phone" :service_staff_id="formValidate.service_staff_id" @cancelTap="cancelTap(rowActive)" @serviceTap="serviceTap"></reserveBoard>
    </Card>
		<!-- 预约详情 -->
		<orderDetails ref="details" :staffList='staffList' @submitSuccess='submitSuccess' @cancelTap="cancelTap" @writeTap="writeTap"></orderDetails>
		<!-- 预约编辑 -->
		<edit ref="edit" :rowActive='rowActive' :staffList='staffList' @submitSuccess='submitSuccess' @cancelTap="cancelTap" @writeTap="writeTap"></edit>
    <!-- 预约看板查看更多 -->
    <addReservation ref="addReservation" :staffList="staffList" @submitSuccess="submitSuccess"></addReservation>
	</div>
</template>

<script>
	import orderDetails from "./components/orderDetails.vue";
	import edit from "./components/edit.vue"
	import reserveBoard from "./components/reserveBoard.vue"
  import addReservation from "./components/addReservation.vue";
	import { mapState } from 'vuex';
	import exportExcel from '@/utils/newToExcel.js'
	import { getReservationOrder, getReservationStaffList, formatReservationStaffOptions, getExportOrder, postOrderCancel, postOrderConfirm, postOrderRefuse } from "@/api/reservation";
	export default {
	    name: 'order',
		components: {
		  orderDetails,
		  edit,
      reserveBoard,
      addReservation,
		},
	    data () {
			return{
				grid: {
				    xl: 7,
				    lg: 7,
				    md: 12,
				    sm: 24,
				    xs: 24
				},
				formValidate: {
				  phone: '',
				  service_staff_id: '',
				  reservation_time: '',
				  page: 1,
				  limit: 10,
				  oid: 0
				},
				total: 0,
				loading: false,
				navList:[
					{name:'看板'},
					{name:'列表'}
				],
				active:0,
				staffList:[],
				reservationStatus:[
					{id:3,name:'待确认'},
					{id:0,name:'待服务'},
					{id:1,name:'服务中'},
					{id:2,name:'已完成'},
					{id:4,name:'已退回'},
					{id:-1,name:'已取消'}
				],
				orderList:[],
				columns: [
					{
						title: '商品信息',
						slot: 'goods_info',
						width: 330
					},
					{
						title: '预约单号',
						key: 'order_id',
						minWidth: 200
					},
					{
						title: '联系人信息',
						slot: 'reservation_info',
						minWidth: 140
					},
					{
						title: '预约日期',
						key: 'reservation_time',
						minWidth: 110
					},
					{
						title: '预约时间',
						slot: 'reservation_time',
						minWidth: 110
					},
				    {
				        title: '手艺人',
				        key: 'staff_name',
				        minWidth: 140
				    },
					{
					    title: '预约状态',
					    slot: 'reservation_status',
					    minWidth: 100
					},
				    {
				        title: '操作',
				        slot: 'action',
				        width: 320,
						fixed: 'right'
				    }
				],
				rowActive:{},
				refuseReason: '',
			}
		},
		computed: {
		  ...mapState('store/layout', [
		  	'isMobile'
		  ]),
		  labelWidth() {
		    return this.isMobile ? undefined : 97;
		  },
		  labelPosition() {
		    return this.isMobile ? "top" : "right";
		  },
		},
	    mounted () {
			this.allStaffList();
			this.getList();
		},
	    methods: {
			// 删除
			del(row, tit, num) {
			  let delfromData = {
			    title: tit,
			    num: num,
			    url: `reservation/order/del/${row.id}`,
			    method: "DELETE",
			    ids: "",
			  };
			  this.$modalSure(delfromData)
			    .then((res) => {
			      this.$Message.success(res.msg);
				  this.orderList.splice(num, 1);
				  if (!this.orderList.length) {
				    this.formValidate.page =
				        this.formValidate.page == 1 ? 1 : this.formValidate.page - 1;
				  }
				  this.getList();
			    })
			    .catch((res) => {
			      this.$Message.error(res.msg);
			    });
			},
			addReservationTap(){
				this.$refs.addReservation.open();
			},
			confirmTap(row) {
			  let delfromData = {
			    title: '接单',
			    url: `reservation/order/confirm/${row.id}`,
			    method: "post",
			  };
			  this.$modalSure(delfromData)
			    .then((res) => {
				  this.$Message.success(res.msg);
				  this.submitSuccess();
				})
			    .catch((err) => {
			      this.$Message.error(err.msg);
			    });
			},
			refuseTap(row) {
			  this.$Modal.confirm({
			    title: '拒绝预约',
			    render: (h) => {
			      return h('Input', {
			        props: {
			          type: 'textarea',
			          rows: 3,
			          placeholder: '请填写拒绝原因',
			          value: this.refuseReason,
			        },
			        on: {
			          input: (val) => {
			            this.refuseReason = val;
			          },
			        },
			      });
			    },
			    onOk: () => {
			      if (!this.refuseReason || !this.refuseReason.trim()) {
			        this.$Message.error('请填写拒绝原因');
			        return Promise.reject();
			      }
			      return postOrderRefuse(row.id, { refuse_reason: this.refuseReason.trim() })
			        .then((res) => {
			          this.$Message.success(res.msg);
			          this.refuseReason = '';
			          this.submitSuccess();
			        })
			        .catch((err) => {
			          this.$Message.error(err.msg);
			          return Promise.reject();
			        });
			    },
			    onCancel: () => {
			      this.refuseReason = '';
			    },
			  });
			},
			writeTap(row){
			  let ids = {
			    status: 2,
			    service_staff_id: row.service_staff_id || 0,
			  };
			  if (row.staff_choose && row.staff_choose.length) {
			    ids.sync_all = [{
			      type: 3,
			      staffChoose: row.staff_choose,
			      goods_id: row.product_id || 0,
			      value: 1,
			    }];
			  }
			  let delfromData = {
			    title: '立即消耗',
			    url: `reservation/order/service/set/${row.id}`,
			    method: "post",
				ids,
			  };
			  this.$modalSure(delfromData)
			    .then((res) => {
				  this.$Message.success(res.msg);
          if (this.active) {
			this.$refs.details.modals = false;
            this.getList();
          } else {
            this.$refs.reserveBoard.getReservationNoticeBoard();
            if (this.$refs.details) {
              this.$refs.details.modals = false;
            }
          }
				})
			    .catch((err) => {
			      this.$Message.error(err.msg);
			    });
			},
			cancelTap(row) {
			  let delfromData = {
			    title: '取消预约',
			    url: `reservation/order/cancel/${row.id}`,
			    method: "post",
			  };
			  this.$modalSure(delfromData)
			    .then((res) => {
				  this.$Message.success(res.msg);
          if (this.active) {
			this.$refs.details.modals = false;
            this.getList();
          } else {
            this.$refs.reserveBoard.getReservationNoticeBoard();
            if (this.$refs.details) {
              this.$refs.details.modals = false;
            }
          }
				})
			    .catch((err) => {
			      this.$Message.error(err.msg);
			    });
			},
			submitSuccess(){
				this.formValidate.page = 1;
				this.getList();
        this.$refs.reserveBoard.getReservationNoticeBoard();
			},
			editTap(row,num){
				this.$refs.edit.type = num;
				this.$refs.edit.modal = true;
				this.rowActive = JSON.parse(JSON.stringify(row));
				this.$refs.edit.cityInfo({ pid: 0 });
			},
			detailsTap(id){
				this.$refs.details.orderDetail(id);
			},
			navTap(index){
				this.formValidate = {
				  phone: '',
				  service_staff_id: '',
				  reservation_time: '',
				  page: 1,
				  limit: 10,
				  oid: 0
				}
				this.getList();
				this.active = index;
			},
			formatStaffServiceDate(reservationTime) {
				if (!reservationTime) return '';
				return String(reservationTime).split(' ')[0];
			},
			allStaffList(){
				const params = {};
				const serviceDate = this.formatStaffServiceDate(this.formValidate.reservation_time);
				if (serviceDate) {
					params.service_date = serviceDate;
				}
				getReservationStaffList(params).then(res=>{
					this.staffList = formatReservationStaffOptions(res.data.list || []);
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			searchs(e,num){
        if (num && e !== undefined && e !== null && e !== '') {
          this.formValidate.reservation_time = e;
        }
        if (num) {
          this.allStaffList();
        }
        if (this.active) {
          this.formValidate.page = 1;
          this.getList();
        } else if (this.$refs.reserveBoard) {
          this.$refs.reserveBoard.getReservationNoticeBoard(this.formValidate);
        }
			},
			getList(){
				this.loading = true
				getReservationOrder(this.formValidate).then(res=>{
					this.orderList = res.data.list
					this.total = res.data.count
					this.loading = false
				}).catch(err=>{
					this.loading = false
					this.$Message.error(err.msg);
				})
			},
			//分页
			pageChange(status) {
			  this.formValidate.page = status;
			  this.getList()
			},
			async exports(value) {
			  let [th, filekey, data, fileName] = [[], [], [], '']
			  let excelData = {
			    ...this.formValidate,
			    export_type: 0,
			    plat_type: 1,
			  }
			  for (let i = 0; i < excelData.page; i++) {
			    let lebData = await this.downOrderData(excelData)
			    if (!lebData.export.length) {
			      break;
			    }
			    if (!fileName) {
			      fileName = lebData.filename
			    }
			    if (!filekey.length) {
			      filekey = lebData.filekey
			    }
			    if (!th.length) {
			      th = lebData.header
			    }
			    data = data.concat(lebData.export)
			    excelData.page++
			  }
			  let sheetData = []
			  for (let j = 0; j < data.length; j++) {
			    let goodsList = data[j].store_name.split('\n')
			    for (let k = 0; k < goodsList.length; k++) {
			      let row = {...data[j]}
			      row.store_name = goodsList[k]
			      if (k) {
			        for (const key in row) {
			          if (Object.hasOwnProperty.call(row, key)) {
			            if (key !== 'store_name') {
			              row[key] = null
			            }
			          }
			        }
			      }
			      sheetData.push(row)
			    }
			  }
			  exportExcel(th, filekey, fileName, sheetData)
			},
			downOrderData(excelData) {
			  return new Promise((resolve, reject) => {
			    getExportOrder(excelData).then((res) => {
			      return resolve(res.data)
			    })
			  })
			},
      serviceTap(list) {
        if (!list || !list.length) return;
        this.detailsTap(list[0].id);
      },
	    },
	}
</script>

<style scoped lang="less">
	/deep/.ivu-input-icon-clear{
		line-height: 40px;
	}
	::-webkit-scrollbar {
	  display: none;
	}
	.reservation{
		position: absolute;
		top: 0;
		right: 0;
		bottom: 0;
		left: 0;
		padding: 20px;
		background: #F5F5F5;
		overflow-x: hidden;
		display: flex;
		flex-direction: column;
	}
	.nav{
		width: 66px;
		height: 40px;
		border-radius: 200px;
		text-align: center;
		line-height: 40px;
		cursor: pointer;
		&.on{
			background-color: #fff;
			color: #1890FF;
		}
	}
	.table{
		border:1px solid #DDDDDD;
		border-radius: 8px 8px 0 0;
	}

    /deep/.ivu-table-fixed-header thead tr th{
		border-radius: 0 8px 0 0;
	}
	/deep/.ivu-table-fixed-right{
		top:0 !important;
	}
	/deep/.table thead tr th{
		padding: 3px 0 3px 25px !important;
		background-color: #f5f5f5 !important;
		color: #606266 !important;
		font-size: 14px;
		border-bottom: 0;
		font-weight: 400;
	}
	/deep/tbody tr td{
		color: #303133;
	}
	/deep/.ivu-table td{
		border-top:1px solid rgba(216, 216, 216, 0.3) !important;
		border-bottom: 0;
		padding-left: 23px !important;
	}
	/deep/.ivu-card-body{
		padding: 24px 24px 20px 24px;
	}
	/deep/.ivu-card{
		border-radius: 20px;
		flex: 1;
	}
	/deep/.ivu-form .ivu-form-item-label{
		line-height: unset;
	}
	/deep/.ivu-select-selection{
		height: 40px;
		border-radius: 8px;
		border-color: #ddd;
	}
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value{
		height: 40px;
		line-height: 40px;
	}
	/deep/.ivu-input{
		height: 40px;
		line-height: 40px;
		border-radius: 8px;
		border-color: #ddd;
	}
	/deep/.ivu-input-suffix i{
		line-height: 40px;
	}
  .board-card /deep/ .ivu-card-body{
    height: 100%;
  }
  .add-reservation-btn {
    background: #23c471;
    padding: 0 20px;
    min-width: 96px;
    box-sizing: border-box;
  }
  .reservation-status-confirm {
    color: #9254DE;
    background: rgba(146, 84, 222, 0.08);
  }
</style>
