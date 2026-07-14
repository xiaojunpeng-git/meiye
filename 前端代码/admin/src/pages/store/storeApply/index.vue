<template>
	<div>
		<Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
		    <div class="new_card_pd">
		        <Form
		                ref="formValidate"
		                inline
		                :model="formValidate"
		                :label-width="labelWidth"
		                :label-position="labelPosition"
		                @submit.native.prevent
		        >
					<FormItem label="时间选择：" >
					  <Date-picker
						:editable="false"
						:clearable="true"
						@on-change="onchangeTime"
						:value="timeVal"
						format="yyyy/MM/dd HH:mm:ss"
						type="datetimerange"
						placement="bottom-start"
						placeholder="选择时间"
						class="input-add"
						:options="options"
					  ></Date-picker>
					</FormItem>
		            <FormItem label="审核状态：">
		                <Select
						  v-model="formValidate.status"
						  placeholder="请选择"
						  clearable
						  class="input-add"
						  @on-change="userSearchs"
						>
		                    <Option value="0">未处理</Option>
		                    <Option value="1">已通过</Option>
							<Option value="2">已拒绝</Option>
		                </Select>
		            </FormItem>
		            <FormItem label="门店信息：">
		                <Input
						  v-model="formValidate.keyword"
						  placeholder="请输入门店名称/联系人/联系电话"
						  class="input-add mr14"
						/>
		                <Button class="mr14" @click="userSearchs" type="primary">查询</Button>
						<Button @click="reset">重置</Button>
		            </FormItem>
		        </Form>
		    </div>
		</Card>
		<Card :bordered="false" dis-hover class="ivu-mt">
		  <Table
		      class="mt10"
		      ref="table"
		      :columns="columns"
		      :data="tableList"
		      :loading="loading"
		      highlight-row
		      no-data-text="暂无数据"
		      no-filtered-data-text="暂无筛选结果"
		  >
		    <template slot-scope="{ row }" slot="address">
				<div>{{row.province+row.city+row.district+row.street+row.detail}}</div>
			</template>
		    <template slot-scope="{ row }" slot="images">
				<div class="acea-row row-middle">
					<div class="tabBox" v-viewer v-for="(item, index) in row.images" :key="index">
						<div class="pictrue">
							<img :src="item"/>
						</div>
					</div>
				</div>
		    </template>
			<template slot-scope="{ row }" slot="status">	
				<Tag color="default" size="medium" v-if="row.status == 0">{{row.status_name}}</Tag>
				<Tag color="green" size="medium" v-else-if="row.status == 1">{{row.status_name}}</Tag>
				<Tag color="red" size="medium" v-else="row.status == 2">{{row.status_name}}</Tag>
				<div class="mt-5" v-if="row.fail_msg">原因：{{row.fail_msg}}</div>
			</template>
			<template slot-scope="{ row }" slot="mark">
				{{row.mark||'-'}}
			</template>
		    <template slot-scope="{ row, index }" slot="action">
		      <a @click="verify(row)" v-if="row.status == 0">审核</a>
		      <Divider type="vertical" v-if="row.status == 0" />
			  <a @click="mask(row)">备注</a>
			  <Divider type="vertical" />
		      <a @click="del(row, '删除入驻申请', index)">删除</a>
		    </template>
		  </Table>
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
		<applyFrom ref="applyFrom"></applyFrom>
	</div>
</template>

<script>
	import { mapState } from "vuex";
	import timeOptions from "@/utils/timeOptions";
	import applyFrom from "./components/applyFrom";
	import { getStoreApplyList, getStoreMarkForm } from "@/api/store";
	export default {
		name: "index",
		components: {
		  applyFrom
		},
		data(){
			return{
				loading: false, 
				options: timeOptions,
				timeVal: [],
				formValidate:{
					page: 1,
					limit: 20,
					data:'',
					status:'',
					keyword:''
				},
				total:0,
				tableList:[],
				columns: [
				  {
				    title: "ID",
				    key: "id",
				    width: 80,
				  },
				  {
				    title: "门店名称",
				    key: "system_name",
				    minWidth: 150,
				  },
				  {
				    title: "联系人姓名",
				    key: "name",
				    minWidth: 90,
				  },
				  {
				    title: "联系方式",
				    key: "phone",
				    minWidth: 90,
				  },
				  {
				    title: "门店地址",
				    slot: "address",
				    minWidth: 150,
				  },
				  {
				    title: "资质图片",
				    slot: "images",
				    minWidth: 200,
				  },
				  {
				    title: "状态",
				    slot: "status",
				    minWidth: 200,
				  },
				  {
				    title: "备注",
				    slot: "mark",
				    minWidth: 100,
				  },
				  {
				    title: "申请时间",
				    key: "add_time",
				    minWidth: 150,
				  },
				  {
				    title: "操作",
				    slot: "action",
				    fixed: "right",
				    width: 140,
				  }
				],
			}
		},
		computed: {
		  ...mapState("admin/layout", ["isMobile"]),
		  labelWidth() {
		    return this.isMobile ? undefined : 96;
		  },
		  labelPosition() {
		    return this.isMobile ? "top" : "right";
		  },
		},
		created(){
			this.getList();
		},
		mounted(){},
		methods:{
			verify(row){
				this.$refs.applyFrom.modals = true;
				this.$refs.applyFrom.id = row.id;
			},
			mask(row){
				this.$modalForm(getStoreMarkForm(row.id)).then(() => this.getList());
			},
			// 删除
			del (row, tit, num) {
			    let delfromData = {
			        title: tit,
			        num: num,
			        url: `/store/apply/del/${row.id}`,
			        method: 'DELETE',
			        ids: ''
			    };
			    this.$modalSure(delfromData).then((res) => {
			        this.$Message.success(res.msg);
			        this.tableList.splice(num, 1);
			        if (!this.tableList.length) {
			          this.formValidate.page =
			              this.formValidate.page == 1 ? 1 : this.formValidate.page - 1;
			        }
			        this.getList();
			    }).catch(res => {
			        this.$Message.error(res.msg);
			    });
			},
			userSearchs(){
				this.formValidate.page = 1;
				this.getList();
			},
			reset(){
				this.formValidate = {
				  page: 1,
				  limit: 20,
				  data:'',
				  status:'',
				  keyword:''
				};
				this.timeVal = [];
				this.getList();
			},
			pageChange (index) {
			    this.formValidate.page = index;
			    this.getList();
			},
			// 具体日期
			onchangeTime(e) {
			  this.timeVal = e;
			  this.formValidate.data = this.timeVal[0] ? this.timeVal.join("-") : "";
			  this.formValidate.page = 1;
			  this.getList();
			},
			getList(){
				getStoreApplyList(this.formValidate).then(res=>{
					let data = res.data;
					this.tableList = data.list;
					this.total = data.count;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			}
		}
	}
</script>

<style lang="stylus" scoped>
	.tabBox{
		margin: 2.5px 5px 2.5px 0;
		.pictrue{
			width: 40px;
			height: 40px;
			img{
				width: 100%;
				height: 100%;
			}
		}
	}
</style>