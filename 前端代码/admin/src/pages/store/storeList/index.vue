<template>
<!-- 门店-门店列表 -->
  <div>
	<Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
		<div class="new_card_pd">
			<Form
			    ref="formValidate"
			    :model="formValidate"
			    :label-width="labelWidth"
			    :label-position="labelPosition"
			    inline
			>
			  <FormItem label="门店分类：" prop='cate_id'>
			    <Cascader
			        :data="treeSelect"
			        placeholder="请选择门店分类"
			        change-on-select
			        filterable
					clearable
			        class="input-add"
			        v-model="formValidate.cate_id"
					@on-change="searchs"
			    ></Cascader>
			  </FormItem>
			  <FormItem label="营业状态：" prop='status'>
			  	<Select
					v-model="formValidate.status"
					placeholder="请选择"
					clearable
					@on-change="searchs"
					class="input-add"
					>
			  		<Option value="1">营业中</Option>
			  		<Option value="-1">已停业</Option>
			  	</Select>
			  </FormItem>
			  <FormItem label="门店区域：" prop='region_id'>
			  	<Select
					v-model="formValidate.region_id"
					placeholder="请选择"
					clearable
					@on-change="searchs"
					class="input-add"
					>
			  		<Option v-for="item in regionList"
					:value="item.id"
					:key="item.id">
					{{ item.name }}
					</Option>
			  	</Select>
			  </FormItem>
			  <FormItem label="自建商品：" prop='product_status'>
			  	<Select
					v-model="formValidate.product_status"
					placeholder="请选择"
					clearable
					@on-change="searchs"
					class="input-add"
					>
			  		<Option value="1">开启</Option>
			  		<Option value="0">关闭</Option>
			  	</Select>
			  </FormItem>
			  <FormItem label="商品免审：" prop='product_verify_status'>
			  	<Select
					v-model="formValidate.product_verify_status"
					placeholder="请选择"
					clearable
					@on-change="searchs"
					class="input-add"
					>
			  		<Option value="1">开启</Option>
			  		<Option value="0">关闭</Option>
			  	</Select>
			  </FormItem>
			  <FormItem label="门店隔离：" prop='is_alone'>
			  	<Select
					v-model="formValidate.is_alone"
					placeholder="请选择"
					clearable
					@on-change="searchs"
					class="input-add"
					>
			  		<Option value="1">开启</Option>
			  		<Option value="0">关闭</Option>
			  	</Select>
			  </FormItem>
			  <FormItem label="自建商品分类：" prop='product_category_status'>
			  	<Select
					v-model="formValidate.product_category_status"
					placeholder="请选择"
					clearable
					@on-change="searchs"
					class="input-add"
					>
			  		<Option value="1">开启</Option>
			  		<Option value="0">关闭</Option>
			  	</Select>
			  </FormItem>
			  <FormItem label="使用平台余额：" prop='use_system_money'>
			  	<Select
					v-model="formValidate.use_system_money"
					placeholder="请选择"
					clearable
					@on-change="searchs"
					class="input-add"
					>
			  		<Option value="1">开启</Option>
			  		<Option value="0">关闭</Option>
			  	</Select>
			  </FormItem>
			  <FormItem label="门店名称：" prop='keywords'>
			    <Input
			        placeholder="请输入门店名称/ID/联系电话"
			        v-model="formValidate.keywords"
			        class="input-add mr14"
			    />
			    <Button type="primary" class="mr14" @click="searchs">查询</Button>
				<Button @click="resetForm">重置</Button>
			  </FormItem>
			</Form>
		</div>
	</Card>
    <Card :bordered="false" dis-hover class="ivu-mt tablebox" :padding="16">
      <div class="new_tab">
        <!-- Tab栏切换 -->
        <Tabs @on-click="onClickTab">
          <TabPane label="全部" name="all" />
          <TabPane label="自营店" name="1" />
          <TabPane label="加盟店" name="2" />
        </Tabs>
      </div>
	  <router-link
	  	:to="`${routerPre}/store/add_store`"
	  >
	    <Button type="primary" v-auth="['admin-store-add_store']">添加门店</Button>
	  </router-link>
      <div class="table">
        <Table
          :columns="columns"
          :data="orderList"
          ref="table"
          class="ivu-mt"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
        >
          <template slot-scope="{ row }" slot="image">
            <img :src="row.image" />
          </template>
          <template slot-scope="{ row, index }" slot="action">
            <a @click="gostore(row)">进入门店</a>
            <Divider type="vertical" />
            <a @click="operation(row)">{{
              row.is_show == 0 ? "开业" : "停业"
            }}</a>
            <Divider type="vertical" />
			<Dropdown @on-click="changeMenu(row, $event)" transfer>
			  <a href="javascript:void(0)"
			    >更多
			    <Icon type="ios-arrow-down"></Icon>
			  </a>
			  <DropdownMenu slot="list">
			    <DropdownItem name="1">重置账密</DropdownItem>
			    <DropdownItem name="2" v-auth="['admin-store-edit_store']">编辑</DropdownItem>
			    <DropdownItem name="3" v-auth="['admin-store-delete_store']">删除</DropdownItem>
			  </DropdownMenu>
			</Dropdown>
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
      </div>
    </Card>
  </div>
</template>

<script>
import util from "@/libs/util";
import Setting from "@/setting";
import { mapState } from "vuex";
import {
  storeListApi,
  storeLogin,
  storeSetShowApi,
  resetApi,
  getAllRegion,
  cascaderList
} from "@/api/store";
export default {
  name: "storeList",
  data() {
    return {
	  routerPre: Setting.roterPre,
      BaseURL: '',
      total: 0,
      loading: false,
      formValidate: {
		is_alone:'',
		product_verify_status:'',
		product_category_status:'',
		product_verify_status:'',
		use_system_money:'',
        status: "",
		cate_id: [],
		region_id:'',
		type:'all',
		keywords:'',
        page: 1,
        limit: 15,
		name: ''
      },
      columns: [
        {
          title: "ID",
          key: "id",
          width: 60,
        },
        {
          title: "门店图片",
          slot: "image",
          minWidth: 80,
        },
        {
          title: "门店名称",
          key: "name",
          minWidth: 80,
        },
		{
		  title: "门店类型",
		  key: "type_name",
		  minWidth: 80,
		},
        {
          title: "联系电话",
          key: "phone",
          minWidth: 90,
        },
        {
          title: "门店地址",
          key: "address",
          ellipsis: true,
          minWidth: 150,
        },
        {
          title: "营业时间",
          key: "day_time",
          minWidth: 120,
        },
        {
          title: "营业状态",
          key: "status_name",
          minWidth: 80,
        },
		{
		  title: "门店区域",
		  key: "recommend_region_name",
		  minWidth: 100,
		},
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          minWidth: 190,
        },
      ],
      orderList: [],
	  regionList:[],
	  treeSelect: []
    };
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
  mounted() {
	this.goodsCategory();
	this.allRegion();
    this.getList();
  },
  methods: {
	changeMenu(row, name) {
	  switch (name) {
	    case '1':
		  this.reset(row);
	      break;
	    case '2':
	      this.edit(row);
	      break;
	    case '3':
	      this.delte(row, '删除门店（同步删除商品）', name);
	      break;
	    default:
	  }
	},
	// 重置搜索表单
	resetForm(){
		this.$refs.formValidate.resetFields();
		this.formValidate.page = 1;
		this.getList();
	},
	//门店分类
	goodsCategory () {
	  cascaderList(1).then(res => {
	    this.treeSelect = res.data;
	  }).catch(res => {
	    this.$Message.error(res.msg);
	  })
	},
	// 区域列表
	allRegion(){
		getAllRegion().then(res=>{
			this.regionList = res.data;
		}).catch(err=>{
			this.$Message.error(err.msg);
		})
	},
    getList() {
      this.loading = true;
      storeListApi(this.formValidate).then((res) => {
        this.orderList = res.data.list;
        this.total = res.data.count;
        this.loading = false;
      });
    },
    reset(row) {
      this.$modalForm(resetApi(row.id)).then(() => this.getList());
    },
    edit(row) {
	  this.$router.push({ path: this.routerPre + "/store/add_store/" + row.id });
    },
    getExpiresTime(expiresTime) {
      let nowTimeNum = Math.round(new Date() / 1000);
      let expiresTimeNum = expiresTime - nowTimeNum;
      return parseFloat(parseFloat(parseFloat(expiresTimeNum / 60) / 60) / 24);
    },
    // 进入门店
    gostore(item) {
      storeLogin(item.id)
        .then((res) => {
          util.openStoreBackend(res.data, { pageTitle: item.name });
        })
        .catch((err) => {
          this.$Message.error(err.msg || "进入门店失败");
        });
    },
    delte(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `store/store/del/${row.id}`,
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
    operation(row) {
      let a = 0; //修改营业状态的反值
      if (row.is_show == 0) {
        a = 1;
      }
      if (row.is_show == 1) {
        a = 0;
      }
      storeSetShowApi(row.id, a)
        .then((res) => {
          this.getList();
          this.$Message.success(res.msg);
        })
        .catch((err) => {
          this.$Message.error(res.msg);
        });
    },
    searchs() {
		this.formValidate.page = 1;
		this.getList();
	},
    onClickTab(e) {
      this.formValidate.type = e;
	  this.searchs();
    },
    //分页
    pageChange(status) {
      this.formValidate.page = status;
      this.getList();
    },
  },
};
</script>

<style scoped lang="stylus">
/deep/.ivu-tabs-nav {
  height: 45px;
}
.tablebox {
  margin-top: 15px;
}
.btnbox {
  padding: 20px 0px 0px 30px;
  .btns {
    width: 99px;
    height: 32px;
    background: #1890ff;
    border-radius: 4px;
    text-align: center;
    line-height: 32px;
    color: #ffffff;
    cursor: pointer;
  }
}
.table {
  padding: 0;
  img {
    width: 40px;
    height: 40px;
  }
}
.search {
  width: 86px;
  height: 32px;
  background: #1890ff;
  border-radius: 4px;
  text-align: center;
  line-height: 32px;
  font-size: 13px;
  font-family: PingFangSC-Regular, PingFang SC;
  font-weight: 400;
  color: #ffffff;
  cursor: pointer;
}
.reset {
  width: 86px;
  height: 32px;
  border-radius: 4px;
  border: 1px solid rgba(151, 151, 151, 0.36);
  text-align: center;
  line-height: 32px;
  font-size: 13px;
  font-family: PingFangSC-Regular, PingFang SC;
  font-weight: 400;
  color: rgba(0, 0, 0, 0.85);
  cursor: pointer;
}
.new_tab {
    >>>.ivu-tabs-nav .ivu-tabs-tab{
        padding:4px 16px 20px !important;
        font-weight: 500;
    }
  }
</style>
