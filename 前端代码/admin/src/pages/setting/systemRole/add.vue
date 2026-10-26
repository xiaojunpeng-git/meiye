<template>
	<div>
	  <div class="i-layout-page-header">
	    <PageHeader class="product_tabs" hidden-breadcrumb>
	      <div slot="title">
	        <router-link :to="{ path: `${roterPre}/setting/system_role/index` }">
				<div class="font-sm after-line">
					<span class="iconfont iconfanhui"></span>
					<span class="pl10">返回</span>
				</div>
			</router-link>
	        <span
	          v-text="$route.params.id !== '0' ? '编辑角色' : '添加角色'"
	          class="mr20 ml16"
	        ></span>
	      </div>
	    </PageHeader>
	  </div>
	  <Card :bordered="false" dis-hover class="ivu-mt">
		<Form
		  ref="formInline"
		  :model="formInline"
		  :rules="ruleValidate"
		  :label-width="labelWidth"
		  :label-position="labelPosition"
		  @submit.native.prevent
		>
		  <FormItem label="角色名称：" label-for="role_name" prop="role_name">
		    <Input placeholder="请输入角色昵称" v-model="formInline.role_name" v-width="'710'" />
		  </FormItem>
		  <FormItem label="是否开启：" prop="status">
			<i-switch
			  v-model="formInline.status"
			  :value="formInline.status"
			  :true-value="1"
			  :false-value="0"
			  size="large"
			>
			  <span slot="open">显示</span>
			  <span slot="close">隐藏</span>
			</i-switch>
		  </FormItem>
		  <FormItem label="权限类型：" prop="type ">
		    <RadioGroup v-model="formInline.type" @on-change='getmenusTap'>
		      <Radio :label="0">后台权限</Radio>
		      <Radio :label="4">移动端权限</Radio>
		    </RadioGroup>
		  </FormItem>
		  <FormItem label="">
			<div class="acea-row row-between-wrapper pr-10 fs-14 w-710">
				<Checkbox v-model="allMenus" :indeterminate="indeterminate" @on-change="handleCheckAll"> 全选</Checkbox>
				<div class="text-wlll-2d8cf0 cup" @click="changeMenus">展开/折叠</div>
			</div>
		    <div class="trees-coadd">
		      <div class="scollhide">
		        <div class="iconlist">
		          <Tree :data="menusList" show-checkbox ref="tree" @on-check-change='checkChange'></Tree>
		        </div>
		      </div>
		    </div>
			<Spin size="large" fix v-if="spinShow"></Spin>
		  </FormItem>
		  
		</Form>  
	  </Card>
	  <Card
	    :bordered="false"
	    dis-hover
	    class="fixed-card"
	    :style="{ left: `${!menuCollapse ? '236px' : isMobile ? '0' : '60px'}` }"
	  >
	    <Form>
	      <FormItem>
	        <Button
	          type="primary"
	          class="submission"
	          @click="handleSubmit('formInline')"
	          >保存</Button
	        >
	      </FormItem>
	    </Form>
	  </Card>
	</div>
</template>

<script>
	import { mapState } from 'vuex'
	import {
	  menusListApi,
	  roleInfoApi,
	  roleCreatApi
	} from '@/api/setting'
	import Setting from '@/setting';
	export default {
		name: 'roleAdd',
		data() {
			return{
				roterPre: Setting.roterPre,
				allMenus: false,
				indeterminate: false,
				menusList: [],
				expand: true,
				spinShow: false,
				formInline: {
				  role_name: '',
				  status: 0,
				  checked_menus: [],
				  id: 0,
				  type: 0
				},
				ruleValidate: {
				  role_name: [
				    { required: true, message: '请输入角色昵称', trigger: 'blur' },
				  ],
				  status: [
				    {
				      required: true,
				      type: 'number',
				      message: '请选择是否开启',
				      trigger: 'change',
				    },
				  ],
				},
			}
		},
		computed: {
		  ...mapState("admin/layout", ["isMobile", "menuCollapse"]),
		  labelWidth() {
		    return this.isMobile ? undefined : 96
		  },
		  labelPosition() {
		    return this.isMobile ? 'top' : 'right'
		  },
		},
		created() {
			if(this.$route.params.id>0){
				this.getIofo();
			}else{
				this.getmenusList()
			}
		},
		methods: {
			checkChange(e){
				if(e.length==this.getAllIds().length){
					this.indeterminate = false;
					this.allMenus = true;
				} else if(e.length>0){
					this.indeterminate = true;
					this.allMenus = true;
				}else{
					this.indeterminate = false;
					this.allMenus = false;
				}
				let checkedMenus = [];
				if(e.length){
					e.forEach(item=>{
						if(item.id){
							checkedMenus.push(item.id)
						}
					})
				}
				this.formInline.checked_menus = checkedMenus.length?checkedMenus:e;
			},
			changeMenus(){
				this.expand  = !this.expand
				this.tidyRes(this.menusList);
			},
			handleCheckAll(e){
				this.indeterminate = false;
				if(this.allMenus){
					this.formInline.checked_menus = this.getAllIds();
				}else{
					this.formInline.checked_menus = [];
				}
				this.tidyRes(this.menusList);
			},
			//默认添加打开时首页被选中
			checkedFun(data) {
			  let checkedMenus = this.formInline.checked_menus;
			  data.forEach(item=>{
				  if(item.id == 7){
					  let children = item.children[0];
					  children.checked = true;
					  checkedMenus.push(children.id);
					  children.children.forEach(j=>{
					  	j.checked = true;
						checkedMenus.push(j.id);
					   })
				  }
			  })
			  if(checkedMenus.length){
			  	 this.checkChange(checkedMenus);
			  }
			},
			// 获取菜单列表所有id；
			getAllIds(){
				let ids = [];
				this.menusList.forEach(item=>{
					ids.push(item.id);
					let getIds = function(item){
						if(item.children && item.children.length){
							item.children.forEach(j=>{
								ids.push(j.id);
								getIds(j)
							})
						}
					}
					getIds(item)
				})
				return ids;
			},
			getmenusTap(e){
				if(this.$route.params.id>0){
					if(e == this.ediType){
						this.getIofo();
					}else{
						this.getmenusList()
					}
				}else{
					this.getmenusList()
				}
			},
			// 菜单列表
			getmenusList() {
			  this.spinShow = true
			  this.formInline.checked_menus = [];
			  this.indeterminate = false;
			  this.allMenus = false;
			  menusListApi({type:this.formInline.type})
			    .then(async (res) => {
			      let data = res.data.menus
			      this.menusList = data
			      this.checkedFun(data)
			      this.spinShow = false
			    })
			    .catch((res) => {
			      this.spinShow = false
			      this.$Message.error(res.msg)
			    })
			},
			// 详情
			getIofo() {
			  this.spinShow = true
			  roleInfoApi(this.$route.params.id)
			    .then(async (res) => {
			      let data = res.data
				  this.ediType = data.role.type;
			      this.formInline = data.role || this.formInline
			      this.formInline.checked_menus = this.formInline.rules
				  let checkedMenus = this.formInline.checked_menus.split(',');
			      this.tidyRes(data.menus,checkedMenus)
			      this.spinShow = false
			    })
			    .catch((res) => {
			      this.spinShow = false
			      this.$Message.error(res.msg)
			    })
			},
			tidyRes(menus,checkedMenus) {
			  let data = []
			  menus.map((menu) => {
			    data.push(this.initMenu(menu))
			  })
			  this.$set(this, 'menusList', data)
			  if(checkedMenus && checkedMenus.length){
				  this.checkChange(checkedMenus);
			  }
			},
			initMenu(menu) {
			  let data = {},
			    checkMenus = ',' + this.formInline.checked_menus + ','
			  data.title = menu.title
			  data.id = menu.id
			  data.expand = this.expand
			  if (menu.children && menu.children.length > 0) {
			    data.children = []
			    menu.children.map((child) => {
			      data.children.push(this.initMenu(child))
			    })
			  } else {
				data.checked = checkMenus.indexOf(String(',' + data.id + ',')) !== -1
			  }
			  return data
			},
			// 提交
			handleSubmit(name) {
			  this.$refs[name].validate((valid) => {
			    if (valid) {
			      this.formInline.checked_menus = []
			      this.$refs.tree.getCheckedAndIndeterminateNodes().map((node) => {
			        this.formInline.checked_menus.push(node.id)
			      })
			      if (this.formInline.checked_menus.length === 0){
					  return this.$Message.error('请至少选择一个权限')
				  }
			      roleCreatApi(this.formInline)
			        .then(async (res) => {
			          this.$Message.success(res.msg)
					  this.$router.push({ path: this.roterPre + "/setting/system_role/index" });
			        })
			        .catch((res) => {
			          this.$Message.error(res.msg)
			        })
			    } else {
			      return false
			    }
			  })
			}
		}
	}
</script>

<style lang="less" scoped>
	.fixed-card {
	  position: fixed;
	  right: 0;
	  bottom: 0;
	  left: 200px;
	  z-index: 99;
	  box-shadow: 0 -1px 2px rgb(240, 240, 240);
	
	  /deep/ .ivu-card-body {
	    padding: 15px 16px 14px;
	  }
	
	  .ivu-form-item {
	    margin-bottom: 0;
	  }
	
	  /deep/ .ivu-form-item-content {
	    margin-right: 124px;
	    text-align: center;
	  }
	
	  .ivu-btn {
	    height: 36px;
	    padding: 0 20px;
	  }
	}
</style>