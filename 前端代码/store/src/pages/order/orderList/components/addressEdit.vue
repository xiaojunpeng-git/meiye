<template>
	<Modal
	  v-model="editAddressFormShow"
	  scrollable
	  title="修改地址"
	  closable
	  width="640"
	  :mask-closable="false"
	>
	  <div class="border-dcdee2 rd-4 mb20">
		  <Input v-model="addressInfo" type="textarea" :autosize="{ minRows: 3, maxRows: 5 }" placeholder="输入/粘贴地址信息，点击识别后自动拆分姓名、电话和地址" />
		  <div class="acea-row row-right">
			  <Button type="primary" shape="circle" size='small' class="mb10 mr20" @click="parseAddress">识别</Button>
		  </div>
	  </div>
	  <Form ref="formEditAddress" :model="formEditAddress" :rules="ruleValidate" @submit.native.prevent :label-width="90">
	    <FormItem label="用户名称：" prop="real_name">
	      <Input v-model="formEditAddress.real_name" type="text" placeholder="请输入用户名称" />
	    </FormItem>
	    <FormItem label="联系方式：" prop="user_phone">
	      <Input v-model="formEditAddress.user_phone" type="number" placeholder="请输入联系方式" />
	    </FormItem>
	    <FormItem label="收货地址：" prop="user_address">
	      <Input v-model="formEditAddress.user_address" type="text" placeholder="请输入收货地址" />
	    </FormItem>
	  </Form>
	  <div slot="footer">
	    <Button @click="cancelAddressForm">取消</Button>
	    <Button type="primary" @click="saveAddressForm('formEditAddress')">确认</Button>
	  </div>
	</Modal>
</template>

<script>
	import {
	  editAddressApi
	} from "@/api/order";
	import AddressParse from 'address-parse';
	export default {
		name: 'addressEdit',
		data() {
			let validatePhone = (rule, value, callback) => {
				if (!value) {
					return callback(new Error('请填写手机号'));
				} else if (!/^400[0-9]{7}|^1[3456789]\d{9}$|^0[0-9]{2,3}-[0-9]{7,8}/.test(value)) {
					callback(new Error('手机号格式不正确!'));
				} else {
					callback();
				}
			};
			return {
				editAddressFormShow:false,
				formEditAddress:{
					real_name:'',
					user_phone:'',
					user_address:''
				},
				ruleValidate:{
					real_name: [
						{ required: true, message: '请输入用户名称', trigger: 'blur' }
					],
					user_phone: [
						{ required: true, validator: validatePhone, trigger: 'blur' }
					],
					user_address: [
						{ required: true, message: '请输入收货地址', trigger: 'blur' }
					]
				},
				addressInfo:'',
				id:0
			}
		},
		mounted(){},
		methods:{
			parseAddress(){
				if(this.addressInfo.trim()){
					const result = AddressParse.parse(this.addressInfo);
					this.formEditAddress.real_name = result[0].name;
					this.formEditAddress.user_phone = result[0].mobile;
					this.formEditAddress.user_address = `${result[0].province}${result[0].city}${result[0].area}${result[0].details}`
				}else{
					this.$Message.error('请输入您要识别的地址');
				}
			},
			cancelAddressForm(){
				this.editAddressFormShow = false;
				this.addressInfo = '';
			},
			saveAddressForm(name){
				this.$refs[name].validate((valid) => {
					if (valid) {
						editAddressApi(this.id,this.formEditAddress).then(res=>{
							this.editAddressFormShow = false;
							this.addressInfo = '';
							this.$Message.success(res.msg);
							this.$emit('submitSuccess');
						}).catch(err=>{
							this.$Message.error(err.msg);
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
	/deep/textarea.ivu-input{
		resize: none;
		border:0;
		&:focus{
			border-color: #fff;
			box-shadow: none;
		}
	}
</style>