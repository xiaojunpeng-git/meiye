<template>
	<div>
		<div class="i-layout-page-header">
		  <PageHeader class="product_tabs" hidden-breadcrumb>
		    <div slot="title" class="acea-row row-middle">
		      <router-link :to="{ path: `${routePre}/admin/system_role` }">
		        <div class="font-sm after-line">
		          <span class="iconfont iconfanhui"></span>
		          <span class="pl10">返回</span>
		        </div>
		      </router-link>
		      <span
		        v-text="$route.params.id ? '编辑角色' : '添加角色'"
		        class="mr20 ml16 fs-18"
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
			  <FormItem label="权限设置：" prop="type ">
				<Tabs class="menuTabs" v-model="currentTab" @on-click="onhangeTab">
					<TabPane label="后台权限" :name="1">
						<Tree :data="menusList" ref="tree" show-checkbox></Tree>
					</TabPane>
					<TabPane label="收银台权限" :name="2">
						<Tree :data="cashList" ref="treeCash" show-checkbox></Tree>
					</TabPane>
					<TabPane label="移动端权限" :name="3">
						<Tree :data="mallList" ref="treeMall" show-checkbox></Tree>
					</TabPane>
				</Tabs>
				<Spin size="large" fix v-if="spinShow"></Spin>
			  </FormItem>
			</Form>
		</Card>
		<div class="h-68"></div>
		<Card
		  :bordered="false"
		  dis-hover
		  class="fixed-card"
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
	import { mapState } from "vuex";
    import Setting from "@/setting";
    import { menuList,roleInfoApi,roleCreatApi,cashierMenusList } from '@/api/setting';
    export default {
        name: 'roleAdd',
        data () {
            return {
                routePre: Setting.routePre,
                menusList: [],
                cashList: [],
				mallList: [],
                spinShow: false,
				currentTab:1,
                formInline:{
                    role_name: '',
                    status: 1,
                    checked_menus: [],
                    id:0,
                    checked_cashier_menus: [],
					checked_mall_menus: []
                },
				ruleValidate: {
				    role_name: [
				        { required: true, message: '请输入管理员角色', trigger: 'blur' }
				    ],
				    status: [
				        { required: true, type: 'number', message: '请选择是否开启', trigger: 'change' }
				    ]
				}
            }
        },
		computed: {
		  ...mapState("store/layout", ["isMobile"]),
		  labelWidth() {
		    return this.isMobile ? undefined : 120;
		  },
		  labelPosition() {
		    return this.isMobile ? "top" : "right";
		  },
		},
        created(){
			if(this.$route.params.id>0){
				this.getInfo(this.$route.params.id);
			}else{
				this.getMenuList()
			}
		},
        methods: {
			onhangeTab(name){
				this.currentTab = name;
			},
			//默认首页被选中并禁止更改
			checkedFun(data){
				data.forEach(item=>{
					if(item.menu_path == `${this.routePre}/home`){
						 item.disabled = true;
						 item.children.forEach(j=>{
							 if(j.menu_path==`${this.routePre}/home/index`){
								  this.formInline.checked_menus.push(j.id);
									j.checked = true;
									j.disabled = true;
									if(j.children.length){
										 j.children.forEach(v=>{
											  v.checked = true;
											  v.disabled = true;
										 })
									}
							 }
						 })
					}
				})
			},
            getMenuList(){
                this.spinShow = true
                menuList().then(res=>{
                    this.spinShow = false
					this.checkedFun(res.data.store_menu);
					this.$set(this, 'menusList', res.data.store_menu);
					res.data.cashier_menu.forEach(item=>{
						if(item.menu_path == `/cashier/cashier/index`){
							item.checked = true;
							item.disabled = true;
						}
					})
					this.$set(this, 'cashList', res.data.cashier_menu);
					this.$set(this, 'mallList', res.data.mall_menu);
                }).catch(err=>{
                    this.spinShow = false
                    this.$Message.error(err.msg)
                })
            },
            // 详情
            getInfo (id) {
                this.spinShow = true;
                this.formInline.id = id;
                roleInfoApi(id).then(async res => {
                    let data = res.data
                    this.formInline = data.role || this.formInline;
                    this.formInline.checked_menus = Array.from(new Set(this.formInline.rules));
                    this.formInline.checked_cashier_menus = Array.from(new Set(this.formInline.cashier_rules));
					this.formInline.checked_mall_menus = Array.from(new Set(this.formInline.mall_rules));
					this.tidyRes(data.menus,0);
                    this.tidyRes(data.cashier_menus,1);
					this.tidyRes(data.mall_menus,2);
                    this.spinShow = false;
                }).catch(res => {
                    this.spinShow = false;
                    this.$Message.error(res.msg);
                })
            },
            tidyRes (menus,num) {
                let data = [];
                menus.map((menu) => {
                  data.push(this.initMenu(menu,num));
                });
                if(num==1){
					this.$set(this, 'cashList', data);
				}else if(num==2){
					this.$set(this, 'mallList', data);
				}else{
                  this.checkedFun(data);
                  this.$set(this, 'menusList', data);
                }
            },
            initMenu (menu,num) {
                let data = {};
                let checkMenus = ',' + this.formInline.checked_menus.join(',') + ',';
                let checkCashMenus = ',' + this.formInline.checked_cashier_menus.join(',') + ',';
				let checkMallMenus = ',' + this.formInline.checked_mall_menus.join(',') + ',';
                data.title = menu.title;
                data.id = menu.id;
				data.menu_path = menu.menu_path;
                if (menu.children && menu.children.length > 0) {
                    data.children = [];
                    menu.children.map((child) => {
                        data.children.push(this.initMenu(child,num));
                    })
                } else {
                    if(num==1){
                      data.checked = checkCashMenus.indexOf(String(',' + data.id + ',')) !== -1;
                    }else if(num==2){
					  data.checked = checkMallMenus.indexOf(String(',' + data.id + ',')) !== -1;
					}else{
                      data.checked = checkMenus.indexOf(String(',' + data.id + ',')) !== -1;
                    }
                }
                return data;
            },
            // 提交
            handleSubmit (name) {
                this.$refs[name].validate((valid) => {
                    if (valid) {
						this.formInline.checked_menus = [];
                        this.formInline.checked_cashier_menus = [];
						this.formInline.checked_mall_menus = [];
                        this.$refs.tree.getCheckedAndIndeterminateNodes().map((node) => {
                            this.formInline.checked_menus.push(node.id);
                        });
                        this.$refs.treeCash.getCheckedAndIndeterminateNodes().map((node) => {
                          this.formInline.checked_cashier_menus.push(node.id);
                        });
						this.$refs.treeMall.getCheckedAndIndeterminateNodes().map((node) => {
						  this.formInline.checked_mall_menus.push(node.id);
						});
                        let checkedMenus = this.formInline.checked_menus;
                        this.formInline.checked_menus = Array.from(new Set(checkedMenus))
                        roleCreatApi(this.formInline).then(async res => {
                            this.$Message.success(res.msg);
							this.$router.push({ path: this.routePre + "/admin/system_role" });
                        }).catch(res => {
                            this.$Message.error(res.msg);
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
	  left: 220px;
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
	.menuTabs{
		margin: -5px 14px 0 0;
	}
	.menuTabs /deep/.ivu-tabs-bar{
		margin-bottom: 1px;
		border-bottom: 0;
	}
	.menuTabs /deep/.ivu-tabs-nav .ivu-tabs-tab{
		padding: 12px 0;
		margin-right: 32px;
	}
	.menuTabs /deep/.ivu-tabs-ink-bar{
		height: 0;
	}
	.menuTabs /deep/.ivu-tabs-nav .ivu-tabs-tab-active:before{
		content:'';
		position: absolute;
		width: 100%;
		height: 1px;
		background-color: #2d8cf0;
		bottom: 4px;
		
	}
</style>