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
	   <Modal v-model="modal2" footer-hide :title="isPhone?'完善手机号':'添加会员'" class-name="member-modal" :width="fullProfile ? 920 : 528" @on-cancel='clear'>
		  <Form ref="formValidate" :model="formValidate" :label-width="90">
			  <template v-if="!fullProfile || isPhone">
				  <FormItem label="用户昵称：">
				    <Input v-model="formValidate.nickname" :disabled="isPhone?true:false" placeholder="请输入用户昵称" class="w-408"></Input>
				  </FormItem>
				  <FormItem label="手机号：" required>
				    <Input v-model="formValidate.phone" placeholder="请输入手机号" class="w-408"></Input>
				  </FormItem>
			  </template>
        <div v-if="!isPhone" class="mb-12">
          <Button type="text" class="full-profile-toggle" @click="toggleFullProfile">{{ fullProfile ? '收起完整资料' : '录入完整资料' }}</Button>
        </div>
        <div v-if="fullProfile && !isPhone" class="full-profile-box">
          <Tabs v-model="fullActiveTab">
            <TabPane label="基本信息" name="basic">
              <FormItem label="姓名：">
                <Input v-model="formValidate.real_name" placeholder="请输入姓名" class="w-408" />
              </FormItem>
              <FormItem label="昵称：">
                <Input v-model="formValidate.nickname" placeholder="请输入昵称" class="w-408" />
              </FormItem>
              <FormItem label="手机号：" required>
                <Input v-model="formValidate.phone" placeholder="请输入手机号" class="w-408" />
              </FormItem>
              <FormItem label="性别：">
                <Select v-model="formValidate.sex" transfer class="w-408" placeholder="请选择">
                  <Option :value="0">保密</Option>
                  <Option :value="1">男</Option>
                  <Option :value="2">女</Option>
                </Select>
              </FormItem>
              <FormItem label="生日：">
                <DatePicker
                  :value="formValidate.birthday"
                  type="date"
                  transfer
                  class="w-408"
                  placeholder="请选择生日"
                  @on-change="(v) => (formValidate.birthday = v || '')"
                />
              </FormItem>
              <FormItem label="身份证：">
                <Input v-model="formValidate.card_id" placeholder="请输入身份证号" class="w-408" />
              </FormItem>
              <FormItem label="地址：">
                <Input v-model="formValidate.addres" placeholder="请输入地址" class="w-408" />
              </FormItem>
              <FormItem label="备注：">
                <Input v-model="formValidate.mark" type="textarea" :rows="2" placeholder="请输入备注" class="w-408" />
              </FormItem>
            </TabPane>
            <TabPane label="档案信息" name="archive">
              <div class="archive-scroll">
                <Alert v-if="!profileFields.length" type="warning" show-icon>
                  暂无已启用的档案字段
                </Alert>
                <CustomerProfileFields
                  v-else
                  ref="profileFields"
                  :fields="profileFields"
                  :groups="profileGroups"
                  v-model="extendValues"
                  :skip-params="builtinSkipParams"
                />
              </div>
            </TabPane>
          </Tabs>
        </div>
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
            <Button class="searchLog" v-for="(item,index) in searchHistory" :key="index" @click="doSearch(item)" >{{ item }}</Button>
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
	userListApi,
	getCashierProfileFields,
} from '@/api/user';
import CustomerProfileFields from '@/components/customerProfileFields';
export default {
  name: 'memberSet',
  components: { CustomerProfileFields },
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
		  uid:0,
      real_name: '',
      sex: 0,
      birthday: '',
      card_id: '',
      addres: '',
      mark: '',
	  },
    fullProfile: false,
    fullActiveTab: 'basic',
    profileFields: [],
    profileGroups: [],
    extendValues: {},
    builtinSkipParams: ['real_name', 'phone', 'sex', 'birthday', 'card_id', 'address', 'addres', 'mark'],
	  isPhone:0,
	  modal3:false,
	  isRegister:false,
	  modal4:false,
	  currentid: 0,
	  columns: [
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
    toggleFullProfile() {
      this.fullProfile = !this.fullProfile;
      if (this.fullProfile) {
        this.fullActiveTab = 'basic';
        if (!this.profileFields.length) this.loadProfileFields();
      }
    },
    loadProfileFields() {
      getCashierProfileFields({ uid: 0 }).then((res) => {
        const data = res.data || {};
        this.profileFields = data.fields || [];
        this.profileGroups = data.groups || [];
        const vals = { ...this.extendValues };
        this.profileFields.forEach((f) => {
          if (vals[f.field_key] === undefined) {
            vals[f.field_key] = f.value !== undefined && f.value !== null ? f.value : '';
          }
        });
        this.extendValues = vals;
      }).catch(() => {
        this.profileFields = [];
        this.profileGroups = [];
      });
    },
    customRequiredFields() {
      return (this.profileFields || []).filter((f) => {
        if (!f || !f.use || !f.required) return false;
        if (f.param && this.builtinSkipParams.includes(f.param)) return false;
        return true;
      });
    },
    mergeBuiltinIntoExtend(extendInfo) {
      const out = { ...(extendInfo || {}) };
      (this.profileFields || []).forEach((f) => {
        if (!f || !f.use || !f.param) return;
        if (!this.builtinSkipParams.includes(f.param)) return;
        let v = '';
        if (f.param === 'real_name') v = this.formValidate.real_name || this.formValidate.nickname;
        else if (f.param === 'phone') v = this.formValidate.phone;
        else if (f.param === 'sex') v = this.formValidate.sex;
        else if (f.param === 'birthday') v = this.formValidate.birthday;
        else if (f.param === 'card_id') v = this.formValidate.card_id;
        else if (f.param === 'address' || f.param === 'addres') v = this.formValidate.addres;
        else if (f.param === 'mark') v = this.formValidate.mark;
        else return;
        if (f.field_key) out[f.field_key] = v;
        out[f.param] = v;
        if (f.info) out[f.info] = v;
      });
      return out;
    },
    handleRowClick(row) {
      if(row.real_name !== ''){
          this.addSearchHistory(row.real_name+"/"+row.phone);
      }else{
          this.addSearchHistory(row.phone);
      }
      this.currentid = row.uid;
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
			uid:0,
      real_name: '',
      sex: 0,
      birthday: '',
      card_id: '',
      addres: '',
      mark: '',
		}
		this.search = ''
		this.isPhone = 0
    this.fullProfile = false
    this.fullActiveTab = 'basic'
    this.extendValues = {}
    this.profileFields = []
    this.profileGroups = []
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
    if (this.fullProfile) {
      const customs = this.customRequiredFields();
      for (let i = 0; i < customs.length; i++) {
        const f = customs[i];
        const v = this.extendValues[f.field_key];
        if (v === '' || v === null || v === undefined) {
          this.fullActiveTab = 'archive';
          return this.$Message.warning(f.tip || `请填写${f.info}`);
        }
      }
    }
    const payload = {
      phone: this.formValidate.phone,
      nickname: this.formValidate.nickname || this.formValidate.real_name,
      uid: this.formValidate.uid || 0,
      full_profile: this.fullProfile ? 1 : 0,
    };
    if (this.fullProfile) {
      let extend_info = this.$refs.profileFields
        ? this.$refs.profileFields.getExtendInfoPayload()
        : { ...this.extendValues };
      extend_info = this.mergeBuiltinIntoExtend(extend_info);
      payload.extend_info = extend_info;
      payload.real_name = this.formValidate.real_name || this.formValidate.nickname;
      payload.sex = this.formValidate.sex;
      payload.birthday = this.formValidate.birthday;
      payload.card_id = this.formValidate.card_id;
      payload.addres = this.formValidate.addres;
      payload.mark = this.formValidate.mark;
    }
		postRegisterUser(payload).then(res=>{
			this.modal2 = false;
			this.modal4 = false;
			this.$emit("submitSuccess", res.data);
			this.clear();
			this.$refs['formValidate'] && this.$refs['formValidate'].resetFields();
		}).catch(err=>{
			this.$Message.error(this.apiErrMsg(err));
		})
	},
	apiErrMsg(err) {
		if (!err) return '操作失败，请稍后重试';
		return err.msg || err.message || '操作失败，请稍后重试';
	},
    getSearchHistory() {
      const history = localStorage.getItem('searchHistory');
      this.searchHistory = history ? JSON.parse(history) : [];
    },
    addSearchHistory(keyword) {
      if (!keyword.trim()) return;
      const newHistory = this.searchHistory.filter(item => item !== keyword.trim());
      newHistory.unshift(keyword.trim());
      this.searchHistory = newHistory.slice(0, 10);
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
					// 仅用 modal3 展示无结果；禁止未定义 flag 的 $Modal.confirm
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
.full-profile-toggle
  padding-left 0
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
  .full-profile-box
    max-height 56vh
    overflow-y auto
  .archive-scroll
    min-height 180px
    padding-right 4px
  .mb-12
    margin-bottom 12px
  .w-408
    width 408px
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
