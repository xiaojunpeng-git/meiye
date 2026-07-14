<template>
	<div>
		<div class="i-layout-page-header">
		  <PageHeader class="product_tabs" hidden-breadcrumb>
		    <div slot="title" class="acea-row row-middle">
		      <router-link :to="{ path: `${routerPre}/store/region/list`, query: { tab: 'manager' } }">
		        <div class="font-sm after-line">
		          <span class="iconfont iconfanhui"></span>
		          <span class="pl10">返回</span>
		        </div>
		      </router-link>
		      <span v-text="$route.params.id ? '编辑管理人员' : '添加管理人员'" class="mr20 ml16"></span>
		    </div>
		  </PageHeader>
		</div>
		<Card :bordered="false" dis-hover :padding="16" class="ivu-mt mb-100">
			<Form ref="formItem" :model="formItem" :label-width="labelWidth" :label-position="labelPosition" :rules="ruleValidate" @submit.native.prevent>
				<Row type="flex" :gutter="24" class="mt20">
					<Col span="24">
						<FormItem label="选择区域：" prop="manageRegion" label-for="manageRegion" required>
						  <Cascader
						    :data="manageRegionTree"
						    v-model="formItem.manage_region_path"
						    change-on-select
						    filterable
						    placeholder="请选择区域"
						    class="inputW"
						    @on-change="changeManageRegion"
						  ></Cascader>
						</FormItem>
					</Col>
					<Col span="24">
					    <FormItem label="联系人名称：" prop="name" label-for="name">
					        <Input v-model="formItem.name" placeholder="请输入联系人名称" class="inputW" :maxlength="50"/>
					    </FormItem>
					</Col>
					<Col span="24">
					    <FormItem label="选择用户：" prop="image" label-for="image">
							<div class="picBox" @click="customer">
							  <div class="pictrue" v-if="formItem.image || formItem.uid">
							    <img v-if="formItem.image" v-lazy="formItem.image" />
							    <Icon v-else type="ios-person" size="26" />
							  </div>
							  <div class="upLoad acea-row row-center-wrapper" v-else>
							    <Icon type="ios-camera-outline" size="26" />
							  </div>
							</div>
							<div class="tips">在选定商城用户后，该区域管理员能够在移动端的个人中心界面，看到区域统计的入口，进而查看区域统计数据。</div>
					    </FormItem>
					</Col>
					<Col span="24">
					    <FormItem label="管理员账号：" prop="account" label-for="account">
					        <Input v-model="formItem.account"  placeholder="请输入管理员账号" class="inputW"/>
					    </FormItem>
					</Col>
					<Col span="24">
					    <FormItem label="管理员密码：" prop="pwd" label-for="pwd">
					        <Input type="password" password v-model="formItem.pwd"  placeholder="请输入密码" class="inputW"/>
					    </FormItem>
					</Col>
					<Col span="24">
					    <FormItem label="确认密码：" prop="conf_pwd" label-for="conf_pwd">
					        <Input type="password" password v-model="formItem.conf_pwd"  placeholder="请输入确认密码" class="inputW"/>
					    </FormItem>
					</Col>
					<Col span="24">
					    <FormItem label="管理员手机号：" label-for="phone" prop="phone">
					        <Input v-model="formItem.phone"  placeholder="请输入管理员手机号" class="inputW"/>
					    </FormItem>
					</Col>
					<Col span="24" v-if="formItem.manage_region_id">
						<FormItem label="区域隔离：" label-for="is_alone" prop="is_alone">
							<Switch size="large" v-model="formItem.is_alone" :false-value="0" :true-value="1">
								<span slot="open" :true-value="1">开启</span>
								<span slot="close" :false-value="0">关闭</span>
							</Switch>
							<div class="tips">开启后，需于进店规则 > 用户定位处开启区域隔离推荐。当用户定位处于本区域设置的推荐地区时，系统会推荐区域内的最近门店，并且用户只能在本区域内切换门店。</div>
						</FormItem>
					</Col>
					<Col span="24">
						<FormItem label="排序：" label-for="sort" prop="sort">
							<InputNumber :min="0" v-model="formItem.sort" class="inputW"></InputNumber>
						</FormItem>
					</Col>
				</Row>
			</Form>
		</Card>
		<Card :bordered="false" dis-hover class="fixed-card" :style="{left: `${!menuCollapse?'236px':isMobile?'0':'60px'}`}">
		  <Form>
		    <FormItem>
		      <Button
		          type="primary"
		          @click="handleSubmit('formItem')"
		      >保存</Button>
		    </FormItem>
		  </Form>
		</Card>
		<Modal
		  v-model="customerShow"
		  scrollable
		  title="请选择商城用户"
		  width="900"
		>
		  <customerInfo
		    v-if="customerShow"
		    @imageObject="imageObject"
		  ></customerInfo>
		</Modal>
	</div>
</template>

<script>
	import { mapState,mapMutations } from "vuex";
	import Setting from "@/setting";
	import customerInfo from '@/components/customerInfo';
	import { getRegionInfo, postRegion, getRegionManageCascader } from '@/api/store';
	export default{
		name: 'region',
		components: {
			customerInfo,
		},
		props: {},
		data () {
			let validateUpload = (rule, value, callback) => {
				if (!this.formItem.uid) {
					callback(new Error('请选择用户'))
				} else {
					callback()
				}
			};
			let validateManageRegion = (rule, value, callback) => {
				if (!this.formItem.manage_region_id) {
					callback(new Error('请选择区域'))
				} else {
					callback()
				}
			};
			let validateAccount = (rule, value, callback) => {
				if (!/^[\dA-Za-z\u4e00-\u9fa5]{3,}$/.test(value)) {
					callback(new Error('管理员账号仅支持数字、字母、汉字组合且最小3位'))
				} else {
					callback()
				}
			};
			let validatePwd = (rule, value, callback) => {
				if (this.id && !value) {
					callback();
					return;
				}
				if (!value) {
					callback(new Error('请输入密码'));
					return;
				}
				if (!/^[\dA-Za-z]{6,}$/.test(value)) {
					callback(new Error('管理员密码仅支持数字、字母组合且最小6位'))
				} else {
					callback()
				}
			};
			let validateConfPwd = (rule, value, callback) => {
				if (this.id && !this.formItem.pwd && !value) {
					callback();
					return;
				}
				if (this.formItem.pwd !== value) {
					callback(new Error('确认密码与密码不一致'))
				} else {
					callback()
				}
			};
			let validatePhone = (rule, value, callback) => {
				if (!value) {
					return callback(new Error('请填写手机号'));
				} else if (!/^400[0-9]{7}|^1[3456789]\d{9}$|^0[0-9]{2,3}-[0-9]{7,8}/.test(value)) {
					callback(new Error('手机号格式不正确!'));
				} else {
					callback();
				}
			};
			return{
				routerPre: Setting.roterPre,
				id:0, //区域id
				formItem:{
					manage_region_id: 0,
					manage_region_path: [],
					pid:[],
					name:'',
					image:'',
					uid:0,
					account:'',
					pwd:'',
					conf_pwd:'',
					phone:'',
					sort:0,
					is_alone:1,
				},
				ruleValidate:{
					manageRegion: [
						{ required: true, validator: validateManageRegion, trigger: 'change' }
					],
					name: [
						{ required: true, message: '请输入联系人名称', trigger: 'blur' },
						{ type: 'string', max: 50, message: '联系人名称最多50个字符', trigger: 'blur' }
					],
					image: [
						{ required: true, validator: validateUpload, trigger: 'change' }
					],
					account: [
						{ required: true, validator: validateAccount, trigger: 'blur' }
					],
					pwd: [
						{ validator: validatePwd, trigger: 'blur' }
					],
					conf_pwd: [
						{ validator: validateConfPwd, trigger: 'blur' }
					],
					phone: [
						{ required: true, validator: validatePhone, trigger: 'blur' }
					],
				},
				manageRegionTree: [],
				selectedManageRegionPid: -1,
				customerShow: false, //用户列表开关
			}
		},
		computed: {
			...mapState("admin/layout", ["isMobile","menuCollapse"]),
			labelWidth () {
				return this.isMobile ? undefined : 120;
			},
			labelPosition () {
				return this.isMobile ? 'top' : 'right';
			}
		},
		created(){
			this.id = this.$route.params.id || 0
			this.loadManageRegions().then(() => {
				if (this.id) {
					this.regionInfo();
				}
			});
		},
		mounted(){
			this.setCopyrightShow({ value: false });
		},
		destroyed () {
		  this.setCopyrightShow({ value: true });
		},
		methods:{
			...mapMutations('admin/layout', [
			  'setCopyrightShow'
			]),
			changeManageRegion(value) {
				const path = value || this.formItem.manage_region_path || [];
				this.formItem.manage_region_path = path;
				this.formItem.manage_region_id = path.length ? Number(path[path.length - 1]) : 0;
				const manageRegionId = this.formItem.manage_region_id;
				const node = this.findManageRegionNode(manageRegionId, this.manageRegionTree);
				this.selectedManageRegionPid = node ? node.pid || 0 : -1;
				this.$refs.formItem.validateField('manageRegion');
			},
			findManageRegionNode(id, tree) {
				for (let i = 0; i < (tree || []).length; i++) {
					const node = tree[i];
					if (Number(node.value) === Number(id)) {
						return { pid: node.pid || 0, label: node.label || '' };
					}
					if (node.children && node.children.length) {
						const found = this.findManageRegionNode(id, node.children);
						if (found) return found;
					}
				}
				return null;
			},
			loadManageRegions() {
				return getRegionManageCascader().then(res => {
					this.manageRegionTree = this.attachRegionPid(res.data || []);
					if (!this.id && this.$route.query.manage_region_id) {
						const manageRegionId = parseInt(this.$route.query.manage_region_id, 10);
						if (manageRegionId > 0) {
							this.formItem.manage_region_path = this.buildManageRegionPath(manageRegionId, this.manageRegionTree);
							this.changeManageRegion(this.formItem.manage_region_path);
						}
					}
				}).catch(err => {
					this.$Message.error(err.msg);
				});
			},
			attachRegionPid(tree, pid = 0) {
				return (tree || [])
					.filter(item => item.value !== 0)
					.map(item => ({
						...item,
						pid,
						children: item.children && item.children.length
							? this.attachRegionPid(item.children, item.value)
							: [],
					}));
			},
			buildManageRegionPath(id, tree, prefix = []) {
				for (let i = 0; i < (tree || []).length; i++) {
					const node = tree[i];
					const path = prefix.concat([node.value]);
					if (Number(node.value) === Number(id)) {
						return path;
					}
					if (node.children && node.children.length) {
						const childPath = this.buildManageRegionPath(id, node.children, path);
						if (childPath.length) {
							return childPath;
						}
					}
				}
				return [];
			},
			customer() {
			  this.customerShow = true;
			},
			imageObject(e) {
			  this.customerShow = false;
			  this.formItem.uid = e.uid;
			  this.formItem.image = e.image;
			  this.$refs.formItem.validateField('image');
			},
			regionInfo(){
				getRegionInfo(this.id).then(res=>{
					const data = res.data || {};
					const contactName = data.name || '';
					this.formItem.manage_region_id = data.manage_region_id || 0;
					this.formItem.manage_region_path = data.manage_region_path || [];
					this.formItem.phone = data.phone || '';
					this.formItem.account = data.account || '';
					this.formItem.uid = Number(data.uid || (data.userInfo && data.userInfo.uid) || 0);
					this.formItem.image = (data.userInfo && data.userInfo.avatar) ? data.userInfo.avatar : '';
					this.formItem.sort = data.sort != null ? data.sort : 0;
					this.formItem.is_alone = data.is_alone != null ? data.is_alone : 0;
					if (data.manage_region_id) {
						if (!this.formItem.manage_region_path.length) {
							this.formItem.manage_region_path = this.buildManageRegionPath(
								data.manage_region_id,
								this.manageRegionTree
							);
						}
						this.changeManageRegion(this.formItem.manage_region_path);
					}
					this.formItem.name = contactName;
					this.$nextTick(() => {
						if (this.$refs.formItem) {
							this.$refs.formItem.validateField('image');
						}
					});
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			handleSubmit(name){
				this.formItem.name = (this.formItem.name || '').trim();
				this.formItem.account = (this.formItem.account || '').trim();
				const path = this.formItem.manage_region_path || [];
				if (path.length) {
					this.formItem.manage_region_id = Number(path[path.length - 1]);
				}
				const payload = {
					...this.formItem,
					uid: Number(this.formItem.uid || 0),
				};
				if (!this.id) {
					payload.nickname = (this.formItem.account || '').trim();
				}
				this.$refs[name].validate((valid) => {
				  if (valid) {
				    postRegion(payload, this.id)
				      .then((res) => {
				        this.$Message.success(res.msg);
				        this.$router.push({
				          path: this.routerPre + "/store/region/list",
				          query: { tab: "manager", _r: Date.now() },
				        });
				      })
				      .catch((err) => {
				        this.$Message.error(err.msg);
				      });
				  } else {
				    this.$Message.error("请完善数据");
				  }
				});
			}
		}
	}
</script>

<style scoped lang="stylus">
	/deep/.ivu-tabs-nav .ivu-tabs-tab{
	  padding:4px 16px 20px !important;
	  font-weight: 500;
	}
	.tips {
	  display: inline-bolck;
	  font-size: 12px;
	  color: #999999;
	  line-height: 1.5;
	  margin-top: 10px;
	}
	.inputW{
		width: 400px;
	}
	.picBox {
	  display: inline-block;
	  cursor: pointer;
	
	  .upLoad {
	    width: 58px;
	    height: 58px;
	    line-height: 58px;
	    border: 1px dotted rgba(0, 0, 0, 0.1);
	    border-radius: 4px;
	    background: rgba(0, 0, 0, 0.02);
	  }
	
	  .pictrue {
	    width: 60px;
	    height: 60px;
	    border: 1px dotted rgba(0, 0, 0, 0.1);
	    margin-right: 10px;
	
	    img {
	      width: 100%;
	      height: 100%;
	    }
	  }
	
	  .iconfont {
	    color: #898989;
	  }
	}
	.fixed-card {
	    position: fixed;
	    right: 0;
	    bottom: 0;
	    left: 200px;
	    z-index: 45;
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