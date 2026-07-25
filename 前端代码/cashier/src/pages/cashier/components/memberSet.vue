<template>
	<div>
	   <Modal v-model="modal" footer-hide title="会员查询" class-name="remarks-modal" width="528" @on-cancel='clear'>
	     <div class="w-80 h-80 auto">
	   	    <img src="../../../assets/images/yonghu.png" class="w-full h-full"/>
	     </div>
	     <Input class="" v-model="search" type="number" placeholder="请输入手机号或会员编码"/>
	     <div class="acea-row row-center-wrapper pb-13">
			<div class="w-176 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 acea-row row-center-wrapper mt-24 pointer" @click="addUserInfo">添加会员</div>
			<div class="w-176 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF acea-row row-center-wrapper ml20 mt-24 pointer" @click="searchUserInfo(0)">查询会员</div>
	     </div>
	   </Modal>
	   <Modal v-model="modal2" footer-hide :title="isPhone?'完善手机号':'添加会员'" class-name="member-modal" width="528" @on-cancel='clear'>
		  <Form ref="formValidate" :model="formValidate" :label-width="90">
			  <FormItem label="用户昵称：">
			    <Input v-model="formValidate.nickname" :disabled="isPhone?true:false" placeholder="请输入用户昵称" class="w-408"></Input>
			  </FormItem>
			  <FormItem label="手机号：" required>
			    <Input v-model="formValidate.phone" placeholder="请输入手机号" class="w-408"></Input>
			  </FormItem>
		  </Form>
		  <div v-if="isPhone" class="w-480 h-50 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF acea-row row-center-wrapper pointer auto" @click="registerUser">确定</div>
		  <div v-else class="acea-row row-center-wrapper">
			  <div class="w-144 h-46 bg-w111-F5F5F5 rd-30px acea-row row-center-wrapper fs-16 text-wlll-606266 pointer" @click="returnTap">取消</div>
			  <div class="w-144 h-46 bg-w111-1890FF rd-30px acea-row row-center-wrapper fs-16 text-wlll-FFFFFF ml-20 pointer" @click="registerUser">确定</div>
		  </div>
	   </Modal>
	   <Modal v-model="modal3" footer-hide title="查询结果" width="528">
		   <div class="w-80 h-80 auto">
		      <img src="../../../assets/images/yonghu.png" class="w-full h-full"/>
		   </div>
		   <div class="text-center mt-30 fs-15 text-wlll-606266"><Icon type="ios-alert" class="fs-18 mr10 text-wlll-FFB200" />用户{{search}}不存在</div>
		   <div class="acea-row row-center-wrapper pb-13 mt-42">
			   <div class="w-144 h-46 rd-30px fs-16 bg-w111-F5F5F5 text-wlll-606266 acea-row row-center-wrapper pointer" @click="cancelBnt">取消</div>
			   <div class="w-144 h-46 rd-30px fs-16 bg-w111-1890FF text-wlll-FFFFFF acea-row row-center-wrapper ml20 pointer" @click="registerBnt">{{isRegister?'注册':'确定'}}</div>
		   </div>
	   </Modal>
	   <Modal v-model="modal4" footer-hide :title="pickerTitle" width="864" @on-cancel="clear" class-name="memberList-modal">
       <div style="display: flex;align-items: center">
		   <Input @input="searchList" v-model="search" search enter-button="查询" placeholder="请输入用户手机号/昵称/ID" class="w-500 h-40" @on-search='searchUser' />
       <div style="display: flex;margin-left: 20px">
          <Button v-if="!hideGuest" type="primary" @click="findYouke" class="lookUser">游客</Button>
          <Button type="primary" @click="addUserInfo" class="lookUser" style="background-color: #409eff">添加客户</Button>
       </div>
       </div>
       <div>
            <Button class="searchLog" v-for="(item,index) in searchHistory" @click="doSearch(item)" >{{ item }}</Button>
       </div>
		   <Table :columns="columns" :data="memberInfo" border no-data-text="暂无数据"
              @on-row-dblclick="handleRowClick"
		          highlight-row no-filtered-data-text="暂无筛选结果" max-height="350" class="mt-20">
		   	<template slot-scope="{ row, index }" slot="info">
				<div class="member-info-cell acea-row row-middle">
					<div class="member-avatar">
						<img :src="row.avatar" class="member-avatar__img" alt="" />
						<img
							v-if="row.is_money_level"
							src="@/assets/images/svip.png"
							class="member-avatar__svip"
							alt="SVIP"
						/>
					</div>
					<div class="member-meta">
						<div class="member-meta__name acea-row row-middle">
							<div class="line1 max-w-124">{{row.nickname}}</div>
							<div
								v-if="row.level"
								class="member-level h-19 m-w-39 pl-6 pr-6 border-1-FACC7D rd-50 lh-15 fs-12 text-center bg-w111-FEF0D9 text-wlll-DFA541 ml-5"
							>
								<span class="iconfont iconhuiyuandengji fs-12 mr-4"></span>V{{row.level}}
							</div>
						</div>
						<div class="fs-14 text-wlll-909399 mt-5">ID：{{row.uid}}</div>
					</div>
				</div>
		   	</template>
		   </Table>
		   <div class="acea-row row-right mt-25 mb-11">
		     <Page
		       :current="page"
		       :total="total"
		       show-elevator
		       show-total
		       @on-change="pageChange"
		       :page-size="limit"
		     />
		   </div>
	   </Modal>
	</div>
</template>

<script>
import {
	postSearchUserInfo,
	postRegisterUser,
	userListApi
} from '@/api/user';
export default {
  name: 'memberSet',
  props: {
    attr: {
      type: Object,
      default: () => {
      }
    },
    hideGuest: {
      type: Boolean,
      default: false,
    },
    pickerTitle: {
      type: String,
      default: '选择会员',
    },
  },
  data() {
    return {
      modal: false,
	  search:'',
	  modal2:false,
	  formValidate:{
		  nickname:'',
		  phone:'',
		  uid:0
	  },
	  isPhone:0,
	  modal3:false,
	  isRegister:false,
	  modal4:false,
	  currentid: 0,
	  columns: [
		// {
		//   title: " ",
		//   key: "chose",
		//   width: 50,
		//   align: "center",
		//   render: (h, params) => {
		//     let uid = params.row.uid;
		//     let flag = false;
		//     if (this.currentid === uid) {
		//       flag = true;
		//     } else {
		//       flag = false;
		//     }
		//     let self = this;
		//     return h("div", [
		//       h("Radio", {
		//         props: {
		//           value: flag,
		// 		  size:'large'
		//         },
		//         on: {
		//           "on-change": () => {
		//             self.currentid = uid;
		//             if (params.row.uid) {
		// 				this.$emit("submitSuccess", params.row);
		// 				this.modal4 = false;
		// 				this.clear();
		//             } else {
		//               this.$Message.warning("请先选择会员");
		//             }
		//           },
		//         },
		//       }),
		//     ]);
		//   },
		// },
	  	{
	  		title: "用户信息",
	  		slot: "info",
	  		minWidth: 200
	  	},
      {
        title: "用户姓名",
        key: "real_name",
        minWidth: 75
      },
	  	{
	  		title: "手机号",
	  		key: "phone",
	  		minWidth: 75
	  	},
	  	{
	  		title: "积分",
	  		key: "integral",
	  		minWidth: 70
	  	},
	  	{
	  		title: "余额",
	  		key: "now_money",
	  		minWidth: 70
	  	},
	  ],
    searchHistory: [],
	  memberInfo:[],
	  page:1,
	  total:0,
	  limit:10
    }
  },
  created() {
    this.getSearchHistory();
  },
  methods: {
    handleRowClick(row) {
      if(row.real_name !== ''){
          this.addSearchHistory(row.real_name+"/"+row.phone);
      }else{
          this.addSearchHistory(row.phone);
      }
      // 1. 把当前点击行的uid赋值给currentid，实现单选框的选中状态联动
      this.currentid = row.uid;
      // 2. 执行你原有的全部业务逻辑，和点击单选框的逻辑完全一致
      if (row.uid) {
        this.$emit("submitSuccess", row);
        this.modal4 = false;
        this.clear();
      } else {
        this.$Message.warning("请先选择会员");
      }
    },
	pageChange(e){
		this.page = e;
		this.searchUserInfo(1);
	},
	returnTap(){
		this.modal = true;
		this.modal2 = false;
	},
    searchList(){
      this.page = 1;
      this.searchUserInfo(1);
    },
    findYouke(){
       this.$parent.changeMenu(2);
      this.modal4 = false;
    },
	clear(){
		this.formValidate={
			nickname:'',
			phone:'',
			uid:0
		}
		this.search = ''
		this.isPhone = 0
	},
	addUserInfo(){
		this.clear();
		this.modal = false
		this.modal2 = true
	},
	registerUser(){
		if(!/^1(3|4|5|7|8|9|6)\d{9}$/.test(this.formValidate.phone)){
			return this.$Message.error('请输入正确的手机号');
		}
		postRegisterUser(this.formValidate).then(res=>{
			this.modal2 = false;
			this.modal4 = false;
			this.$emit("submitSuccess", res.data);
			this.clear();
			this.$refs['formValidate'].resetFields();
		}).catch(err=>{
			this.$Message.error(this.apiErrMsg(err));
		})
	},
	apiErrMsg(err) {
		if (!err) return '操作失败，请稍后重试';
		return err.msg || err.message || '操作失败，请稍后重试';
	},
    // 1. 读取本地存储的搜索记录
    getSearchHistory() {
      const history = localStorage.getItem('searchHistory');
      this.searchHistory = history ? JSON.parse(history) : [];
    },

    // 2. 新增搜索记录（核心方法）
    addSearchHistory(keyword) {
      if (!keyword.trim()) return; // 空值不存储

      // 步骤1：去重（过滤掉和当前关键词相同的记录）
      const newHistory = this.searchHistory.filter(item => item !== keyword.trim());
      // 步骤2：将新关键词插入到数组头部（最新的在最前）
      newHistory.unshift(keyword.trim());
      // 步骤3：截取前10条，保证最多10条
      this.searchHistory = newHistory.slice(0, 10);
      // 步骤4：存入localStorage
      localStorage.setItem('searchHistory', JSON.stringify(this.searchHistory));
    },
    doSearch(key){
        if(key.indexOf('/') !== -1) {
               var keyAttr=key.split("/");
               key=keyAttr[1];
        }
        this.search=key;
        this.searchUser();
    },
	searchUser(){
		this.page = 1;
		this.searchUserInfo(1);
	},
	searchUserInfo(num){
		// if(!this.search){
		//    return this.$Message.error('请输入手机号或会员编码');
		// }
		// num 为真：列表弹窗(modal4)；为假：快捷查询（0/1/多结果分流）
		const listMode = !!num;
		if(!listMode){
			this.page = 1;
		}
		userListApi({
		   keyword:this.search,
		   page:this.page,
		   limit:this.limit
		}).then(res=>{
			const data = res && res.data;
			if (!data || typeof data !== 'object') {
				this.$Message.error('会员查询返回异常，请稍后重试');
				return;
			}
			const list = Array.isArray(data.list) ? data.list : [];
			const count = Number(data.count || 0);
			this.total = count;
			if(listMode){
				this.memberInfo = list;
				this.modal = false;
				// 列表查询路径必须打开完整「选择会员」弹窗（modal4），不能依赖父组件抢先赋值
				this.modal4 = true;
			}else{
				if(count == 1 && list[0]){
					this.modal = false
					this.$emit("submitSuccess", list[0]);
					this.clear();
				}else if(count>1){
					this.memberInfo = list;
					this.modal = false
					this.modal4 = true;
					this.currentid = 0;
				}else{
					// 仅用 modal3 展示无结果；旧代码另调 $Modal.confirm 且引用未定义 flag，
					// ReferenceError 落入 catch 后 $Message.error(undefined) 触发 reading 'content'
					this.modal3 = true;
					this.isRegister = /^1(3|4|5|7|8|9|6)\d{9}$/.test(this.search);
				}
			}
		}).catch(err=>{
			this.$Message.error(this.apiErrMsg(err));
		})
	},
	registerBnt(){
		if(this.isRegister){
			this.formValidate.phone = this.search;
			this.modal = false
			this.modal3 = false
			this.modal2 = true
		}else{
			this.modal3 = false
		}
	},
	cancelBnt(){
		this.modal3 = false
	}
  }
}
</script>

<style lang="stylus" scoped>
.member-info-cell
	flex-wrap nowrap
.member-avatar
	position relative
	width 48px
	height 48px
	border-radius 50%
	margin-right 12px
	flex-shrink 0
	.member-avatar__img
		width 100%
		height 100%
		border-radius 50%
		display block
		object-fit cover
	.member-avatar__svip
		position absolute
		width 39px
		height 17px
		left 50%
		bottom -1px
		margin-left -19px
		display block
		border-radius 0
		object-fit contain
		pointer-events none
		z-index 1
.member-meta
	min-width 0
.member-meta__name
	flex-wrap nowrap
.member-level
	flex-shrink 0
	white-space nowrap
.searchLog{
  margin-top: 20px;
  margin-right: 10px;
  width: auto;
  padding: 0px 10px;
  height: 40px;
  line-height: 40px;
  text-align: center;
  border-color: #f7f8fa;
  border-radius: 0.343042rem;
  background-color: #f7f8fa;
  font-weight: 500;
  font-size: 16px !important;
  color: #515a6e;
  display: inline-block;
}
.lookUser{
  margin-right: 20px;
  width: 120px !important;
  height: 40px;
  line-height: 40px;
  text-align: center;
  border-radius: 0.343042rem;
  font-weight: 500;
  font-size: 16px !important;
}
/deep/tr{
    cursor: pointer;
}
	.fs-12{
		font-size: 12px !important;
	}
	/deep/.ivu-table-wrapper{
		overflow: hidden !important;
	}
	/deep/.memberList-modal{
		.ivu-modal-body{
			padding: 20px 25px 20px 25px;
		}
		.ivu-input{
			height: 40px;
			border-radius: 50px 0 0 50px;
			padding-left: 20px;
		}
		.ivu-input-search{
			border-radius: 0 50px 50px 0;
			width: 67px;
		}
	}
	/deep/.ivu-form .ivu-form-item-label{
		font-size: 13px !important;
	}
	/deep/.remarks-modal{
		.ivu-input{
			width: 372px;
			height: 40px;
			border-radius: 50px !important;
			margin: 24px auto 0 auto;
			display: block;
			text-align: center;
		}
	}
	/deep/.member-modal{
		.ivu-input{
			width: 408px;
			height: 36px;
			font-size: 13px !important;
		}
		.ivu-modal-body{
			padding-top: 23px;
			padding-bottom: 20px;
		}
	}
	/deep/.ivu-table{
		border-radius: 10px;
	}
	/deep/.ivu-table-wrapper-with-border{
		border: 1px solid #DDDDDD;
		border-right:0;
		border-radius: 10px;
	}
	/deep/.ivu-table-header thead tr th{
		padding: 5px 0 5px 14px !important;
		background-color: #f5f5f5 !important;
		color: #606266 !important;
		font-size: 14px;
		border-bottom: 0;
	}
	/deep/.ivu-table-border th, /deep/.ivu-table-border td{
		border-right: 0;
	}
	/deep/.ivu-table td{
		border-top:1px solid rgba(216, 216, 216, 0.3) !important;
		border-bottom: 0;
		padding: 8px 0 8px 14px !important;
	}
</style>
