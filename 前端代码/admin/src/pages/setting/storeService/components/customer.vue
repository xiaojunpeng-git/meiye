<template>
	<div>
		<Modal
		   v-model="modals"
		   @on-cancel='cancel'
		   :closable="true"
		   :title="form.id ? '编辑客服' : '添加客服'"
		   :mask-closable="false"
		   :z-index="10"
		   width="700">
		  <div>
		    <Form size="small" ref="form" :rules="rules" :model="form" :label-width="100">
			  <FormItem label="客服头像：" prop="avatar">
			    <div v-if="form.avatar" class="upload-list">
			      <div class="upload-item">
			        <img :src="form.avatar" />
			        <Button
			            shape="circle"
			            icon="ios-close"
			            @click="delImage"
			        ></Button>
			      </div>
			    </div>
			    <Button
			        v-else
			        class="upload-select"
			        type="dashed"
			        icon="ios-add"
			        @click="modalPicTap('avatar')"
			    ></Button>
			  </FormItem>
		      <FormItem label="客服名称：" prop="nickname">
		        <Input v-model="form.nickname" placeholder="请输入客服名称" class="w-420"></Input>
		      </FormItem>
			  <FormItem label="手机号码：" prop="phone">
			    <Input v-model="form.phone" placeholder="请输入手机号码" class="w-420"></Input>
			  </FormItem>
			  <FormItem label="客服账号：" prop="account">
			    <Input v-model="form.account" placeholder="请输入客服账号" class="w-420"></Input>
			  </FormItem>
			  <FormItem label="客服密码：" :required='form.id?false:true'>
			    <Input type="password" v-model="form.password" placeholder="请输入客服密码" class="w-420"></Input>
			  </FormItem>
			  <FormItem label="确认密码：" :required='form.id?false:true'>
			    <Input type="password" v-model="form.true_password" placeholder="请输入确认密码" class="w-420"></Input>
			  </FormItem>
			  <FormItem label="账号状态：">
			    <i-switch v-model="form.account_status" :true-value="1" :false-value="0" size="large">
			      <span slot="open">开启</span>
			      <span slot="close">关闭</span>
			    </i-switch>
			  </FormItem>
			  <FormItem label="客服状态：" v-if="form.account_status">
			    <i-switch v-model="form.status" :true-value="1" :false-value="0" size="large">
			      <span slot="open">开启</span>
			      <span slot="close">关闭</span>
			    </i-switch>
			  </FormItem>
			  <FormItem label="移动端管理：" v-if="form.account_status">
			    <i-switch v-model="form.customer" :true-value="1" :false-value="0" size="large">
			      <span slot="open">开启</span>
			      <span slot="close">关闭</span>
			    </i-switch>
			  </FormItem>
			  <FormItem label="选择角色：" prop="roles" v-if="form.account_status && form.customer">
			    <Select
			        v-model="form.roles"
			        clearable
					filterable
			        class="w-420"
					placeholder="请选择角色"
			    >
			      <Option
			          v-for="(item, index) in roleList"
			          :value="(item.value)+''"
			          :key="item.value"
			      >{{ item.label }}</Option
			      >
			    </Select>
			  </FormItem>
			  <FormItem label="商城用户：" prop="uid" v-if="form.account_status && form.customer">
				  <div v-if="form.uid">{{userName}}（ID：{{form.uid}}）<span @click="customer" class="ml-10 text-wlll-2d8cf0 pointer">换绑</span></div>
				  <div v-else @click="customer" class="text-wlll-2d8cf0 pointer">+选择用户</div>
			  </FormItem>
		      <FormItem label="订单通知：" v-if="form.account_status">
		        <i-switch v-model="form.notify" :true-value="1" :false-value="0" size="large">
		          <span slot="open">开启</span>
		          <span slot="close">关闭</span>
		        </i-switch>
		      </FormItem>
		    </Form>
		  </div>
		  <div slot="footer">
		    <Button @click="cancel">取消</Button>
		    <Button type="primary" @click="addWordsConfirm('form')">保存</Button>
		  </div>
		</Modal>
		<Modal
		    v-model="modalPic"
		    width="960px"
		    scrollable
		    footer-hide
		    closable
		    title="上传图标"
		    :mask-closable="false"
		    :z-index="500"
		>
		  <uploadPictures
		      isChoice="单选"
		      @getPic="getPic"
		      v-if="modalPic"
		  ></uploadPictures>
		</Modal>
		<Modal
		    v-model="customerShow"
		    scrollable
		    title="请选择商城用户"
		    :closable="false"
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
	import uploadPictures from "@/components/uploadPictures";
	import customerInfo from "@/components/customerInfo";
	import { kefuRolelistApi, kefuApi, kefuInfoApi } from "@/api/setting";
	export default{
		name: "customer",
		components:{
		  uploadPictures,
		  customerInfo
		},
		data() {
			let validateUpload = (rule, value, callback) => {
				if (!this.form.avatar) {
					callback(new Error('请上传客服头像'))
				} else {
					callback()
				}
			};
			let validateUid = (rule, value, callback) => {
				if (!this.form.uid) {
					callback(new Error('请上传商城用户'))
				} else {
					callback()
				}
			};
			let validatePhone = (rule, value, callback) => {
				if (!value) {
					return callback(new Error('请填写手机号'));
				} else if (!/^1[3456789]\d{9}$/.test(value)) {
					callback(new Error('手机号格式不正确!'));
				} else {
					callback();
				}
			};
			return {
				customerShow: false,
				modalPic:false,
				modals:false,
				roleList:[],
				id:0, //客服id
				userName:'',
				form:{
					avatar:'',
					nickname:'',
					phone:'',
					account:'',
					password:'',
					true_password:'',
					account_status:0,
					status:0,
					customer:0,
					roles:'',
					uid:0,
					notify:0
				},
				rules: {
					avatar: [
						{ required: true, validator: validateUpload, trigger: 'change' }
					],
					nickname: [
						{ required: true, message: '请输入客服名称', trigger: 'blur' }
					],
					phone: [
						{ required: true, validator: validatePhone, trigger: 'blur' }
					],
					account: [
						{ required: true, message: '请输入管理员账号', trigger: 'blur' }
					],
					roles: [
						{ required: true, message: '请选择角色', trigger: 'change' }
					],
					uid: [
						{ required: true, validator: validateUid, trigger: 'change' }
					]
				},
			}
		},
		created() {
			this.kefuRolelist();
		},
		methods: {
			kefuRolelist(){
				kefuRolelistApi().then(res=>{
					this.roleList = res.data;
				}).catch(err=>{
					this.$Message.error(error.msg);
				})
			},
			customer() {
			  this.customerShow = true;
			  this.$refs.form.validateField('uid')
			},
			imageObject(e) {
			  this.customerShow = false;
			  this.form.uid = e.uid;
			  this.userName = e.name;
			  this.$refs.form.validateField('uid')
			},
			delImage(){
			  this.form.avatar = '';
			},
			modalPicTap() {
			  this.modalPic = true;
			  this.$refs.form.validateField('avatar')
			},
			getPic(pic) {
			  this.modalPic = false;
			  this.form.avatar = pic.att_dir;
			  this.$refs.form.validateField('avatar')
			},
			cancel(){
				this.modals = false;
				this.form = {
					avatar:'',
					nickname:'',
					phone:'',
					account:'',
					password:'',
					true_password:'',
					account_status:0,
					status:0,
					customer:0,
					roles:'',
					uid:0,
					notify:0
				}
				this.$refs.form.resetFields();
			},
			kefuInfo(id){
				kefuInfoApi(id).then(res=>{
					this.form = res.data;
					this.form.password = '';
					if(res.data.userInfo instanceof Object){
						this.userName = res.data.userInfo.nickname;
					}
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			addWordsConfirm(name){
				this.$refs[name].validate((valid) => {
					if (valid) {
						if(!this.form.id){
							if(!this.form.password){
								return this.$Message.error('请输入客服密码');
							}
							if(this.form.password !== this.form.true_password){
								return this.$Message.error('确认密码与客服密码不一致');
							}
						}
						kefuApi(this.id,this.form).then(async res => {
							this.$Message.success(res.msg);
							this.cancel();
							this.$emit('changeCustomer');
						}).catch(res => {
							this.$Message.error(res.msg);
						})
					} else {
						return false;
					}
				})
			}
		}
	}
</script>

<style lang="stylus" scoped>
	.upload-select {
	  width: 58px;
	  height: 58px;
	  font-size: 35px !important;
	  color #ccc;
	}
	.upload-list {
	  display: inline-block;
	  margin: 0 0 -10px 0;
	
	  .upload-item {
	    position: relative;
	    display: inline-block;
	    width: 58px;
	    height: 58px;
	    border: 1px dashed #DDDDDD;
	    border-radius: 4px;
	    margin: 0 15px 10px 0;
	  }
	
	  img {
	    width: 64px;
	    height: 64px;
	    border-radius: 4px;
	    vertical-align: middle;
	  }
	
	  .ivu-btn {
	    position: absolute;
	    top: 0;
	    right: 0;
	    width: 20px;
	    height: 20px;
	    margin: -10px -10px 0 0;
	  }
	}
</style>