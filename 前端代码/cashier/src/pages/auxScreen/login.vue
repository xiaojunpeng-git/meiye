<template>
    <div class="login">
        <div>
            <div class="pictrue">
                <img src="@/assets/images/logos.png">
            </div>
            <Form ref="formInline" :model="formInline" :rules="ruleInline">
                <FormItem prop="appUrl">
                    <Input v-model="formInline.appUrl" placeholder="请输入域名">
                        <template #prepend>
                            <Select v-model="select1" style="width: 90px">
                                <Option value="https:">https://</Option>
                                <Option value="http:">http://</Option>
                            </Select>
                        </template>
                    </Input>
                </FormItem>
                <FormItem>
                    <Button
                            type="primary"
                            long
                            size="default"
                            @click="handleSubmit('formInline')"
                            class="btn"
                    >确定</Button>
                </FormItem>
            </Form>
        </div>
    </div>
</template>
<script>
    export default{
        data(){
            return{
                select1:'https:',
                formInline: {
                    appUrl: ""
                },
                ruleInline: {
                    appUrl: [
                        { required: true, message: "请输入域名", trigger: "blur" },
                    ]
                }
            }
        },
        created(){},
        mounted(){},
        methods:{
            handleSubmit(name) {
                this.$refs[name].validate((valid) => {
                    if (valid) {
                        localStorage.setItem('protocol',this.select1);
                        localStorage.setItem('api-url',this.formInline.appUrl);
                        // window.location.href = location.origin+location.pathname+'#/cashier/login'
                        window.location.reload()
                    }
                });
            }
        }
    }
</script>
<style lang="less" scoped>
    /deep/.ivu-select-item{
        font-size: 16px !important;
    }
    /deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
    /deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value{
        font-size: 16px !important;
    }
    /deep/.ivu-input{
        font-size: 16px !important;
    }
    /deep/.ivu-input-group{
        width: 429px;
        height: 56px;
        font-size: 16px;
    }
    /deep/.ivu-input-group > .ivu-input:last-child{
        height: 56px;
    }
    /deep/.ivu-btn-primary{
        width: 429px;
        height: 56px;
        margin-top: 25px;
        font-size: 16px !important;
    }
    .login{
        width: 100%;
        height: 100vh;
        background-image: url('../../assets/images/screenBg.png');
        background-repeat: no-repeat;
        background-position: center;
        background-size: cover;
        display: flex;
        align-items: center;
        justify-content: center;
        .pictrue{
            height: 81px;
            width: 191px;
            margin: 0 auto 50px auto;
            img{
                width: 100%;
                height: 100%;
            }
        }
    }
</style>