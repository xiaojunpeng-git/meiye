<template>
<!-- 系统设置 -->
    <div>
        <section class="deal-people-threshold" aria-label="成交人数门槛">
            <div class="deal-people-threshold__title">成交人数门槛</div>
            <div class="deal-people-threshold__fields">
                <label>A 来源
                    <InputNumber v-model="dealPeopleThreshold.a_yuan" :min="1" :max="99999999" :precision="0" :disabled="!dealPeopleLoaded || dealPeopleSaving" />
                    <span>元</span>
                </label>
                <label>其他来源
                    <InputNumber v-model="dealPeopleThreshold.other_yuan" :min="1" :max="99999999" :precision="0" :disabled="!dealPeopleLoaded || dealPeopleSaving" />
                    <span>元</span>
                </label>
                <Button type="primary" :loading="dealPeopleSaving" :disabled="!dealPeopleLoaded" @click="saveDealPeopleThreshold">保存</Button>
            </div>
            <div v-if="dealPeopleLoadFailed" class="deal-people-threshold__error">读取门槛失败，请刷新页面后重试。</div>
        </section>
        <from-submit :validate="ruleValidate" :url="url" :title="title" :rules="rules"/>
    </div>
</template>

<script>
    import fromSubmit from '@/components/fromSubmit/fromSubmit.vue';
    import { getDealPeopleThreshold, saveDealPeopleThreshold as updateDealPeopleThreshold } from '@/api/system';
    import buildData from "./buildData";

    export default {
        name: "base-index",
        components:{fromSubmit},
        mixins:[buildData],
        data() {
            return {
                ruleValidate: {
                    site_name: [
                        { required: true, message: '请输入网站名称', trigger: 'blur' }
                    ],
                    site_url: [
                        { required: true, message: '请填写网站地址', trigger: 'blur' }
                    ]
                },
                rules: [],
                url: '',
                title:'基础设置',
                type: 'base',
                dealPeopleThreshold: { a_yuan: 1000, other_yuan: 500, version: 0 },
                dealPeopleLoaded: false,
                dealPeopleLoadFailed: false,
                dealPeopleSaving: false
            };
        },
        created() {
            this.loadDealPeopleThreshold();
        },
        methods: {
            // 门槛由版本化接口读取；加载失败时禁止把页面默认值误保存为新配置。
            loadDealPeopleThreshold() {
                getDealPeopleThreshold().then(res => {
                    this.dealPeopleThreshold = res.data;
                    this.dealPeopleLoaded = true;
                    this.dealPeopleLoadFailed = false;
                }).catch(res => {
                    this.dealPeopleLoadFailed = true;
                    this.$Message.error(res.msg || '读取成交人数门槛失败');
                });
            },
            // 独立保存成交门槛，不混入基础设置表单或修改历史订单资格。
            saveDealPeopleThreshold() {
                const value = this.dealPeopleThreshold;
                if (!this.dealPeopleLoaded || ![value.a_yuan, value.other_yuan].every(v => Number.isInteger(v) && v > 0)) {
                    this.$Message.error('门槛必须填写正整数元');
                    return;
                }
                this.dealPeopleSaving = true;
                updateDealPeopleThreshold(value).then(res => {
                    this.dealPeopleThreshold = res.data;
                    this.$Message.success('保存成功');
                }).catch(res => this.$Message.error(res.msg || '保存失败'))
                    .finally(() => { this.dealPeopleSaving = false; });
            }
        }
    }
</script>

<style scoped>
.deal-people-threshold { padding: 18px 22px; background: #fff; border-bottom: 1px solid #e8edf2; }
.deal-people-threshold__title { margin-bottom: 12px; font-weight: 600; color: #263746; }
.deal-people-threshold__fields { display: flex; align-items: end; flex-wrap: wrap; gap: 16px; }
.deal-people-threshold__fields label { display: flex; align-items: center; gap: 8px; color: #526273; }
.deal-people-threshold__fields .ivu-input-number { width: 120px; }
.deal-people-threshold__error { margin-top: 10px; color: #d4380d; }
</style>
