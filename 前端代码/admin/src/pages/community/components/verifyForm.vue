<template>
	<Modal
	  v-model="verifyFormShow"
	  scrollable
	  title="批量审核"
	  closable
	  width="540"
	  :mask-closable="false"
	>
	  <Form :model="verifyForm" :label-width="80">
	    <FormItem label="审核状态：">
	      <RadioGroup
	        v-model="verifyForm.is_verify"
	      >
	        <Radio :label="1">通过</Radio>
	        <Radio :label="-1">拒绝</Radio>
	      </RadioGroup>
	    </FormItem>
	    <FormItem label="拒绝原因：" v-if="verifyForm.is_verify == -1">
	      <Input
	        v-model="verifyForm.refusal"
	        type="textarea"
	        :autosize="{ minRows: 2, maxRows: 5 }"
	        placeholder="请输入拒绝原因"
	      ></Input>
	    </FormItem>
	  </Form>
	  <div slot="footer">
	    <Button @click="cancelVerifyForm">取消</Button>
	    <Button type="primary" @click="saveVerifyForm">确认</Button>
	  </div>
	</Modal>
</template>

<script>
	import {
	  commentBatchVerifyApi
	} from "@/api/community";
	export default {
		name: 'verifyForm',
		data() {
			return {
				verifyFormShow:false,
				verifyForm:{
					where:{},
					ids:'',
					all:0,
					is_verify:1,
					refusal:''
				}
			}
		},
		mounted(){},
		methods:{
			cancelVerifyForm(){
				this.verifyFormShow = false;
				this.verifyForm.is_verify = 1;
				this.verifyForm.refusal = "";
			},
			saveVerifyForm(){
				if(this.verifyForm.is_verify == -1 && !this.verifyForm.refusal){
					return this.$Message.error(err.msg);
				}
				commentBatchVerifyApi(this.verifyForm).then(res=>{
					this.$Message.success(res.msg);
					this.cancelVerifyForm();
					this.$emit('submitSuccess')
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			}
		}
	}
</script>

<style>
</style>