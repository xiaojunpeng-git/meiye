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
        <section class="deal-people-threshold" aria-label="会员交易默认来源">
            <div class="deal-people-threshold__title">会员交易默认来源</div>
            <div class="deal-people-threshold__fields">
                <label v-for="entry in commerceKinds" :key="entry.key">{{entry.label}}
                    <Select v-model="commerce[entry.key + '_primary_id']" style="width:180px" :disabled="!commerceLoaded || commerceSaving" @on-change="commerce[entry.key + '_secondary_id'] = 0">
                        <Option v-for="source in commerce.sources || []" :key="source.id" :value="Number(source.id)">{{source.name}}</Option>
                    </Select>
                    <Select v-if="commerceChildren(entry.key).length" v-model="commerce[entry.key + '_secondary_id']" style="width:160px" :disabled="commerceSaving">
                        <Option :value="0">请选择二级来源</Option>
                        <Option v-for="source in commerceChildren(entry.key)" :key="source.id" :value="Number(source.id)">{{source.name}}</Option>
                    </Select>
                </label>
                <Button type="primary" :disabled="!commerceLoaded" :loading="commerceSaving" @click="saveCommerce">保存</Button>
            </div>
        </section>
        <from-submit :validate="ruleValidate" :url="url" :title="title" :rules="rules"/>
    </div>
</template>

<script>
    import fromSubmit from '@/components/fromSubmit/fromSubmit.vue';
    import { getDealPeopleThreshold, saveDealPeopleThreshold as updateDealPeopleThreshold } from '@/api/system';
    import buildData from "./buildData";
    import request from '@/plugins/request';

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
                dealPeopleSaving: false,
                commerce: { sources: [], version: 0 }, commerceLoaded: false, commerceSaving: false,
                commerceKinds: [{key:'purchase',label:'消费默认来源'}, {key:'recharge',label:'充值默认来源'}]
            };
        },
        created() {
            this.loadDealPeopleThreshold();
            this.loadCommerce();
        },
        methods: {
            // Source IDs and versions survive refresh; a failed read disables saving.
            loadCommerce() {
                request({url:'setting/member-commerce-defaults',method:'get'}).then(res => {
                    this.commerce = res.data; this.commerceLoaded = true;
                }).catch(e => this.$Message.error(e.msg || '读取默认来源失败'));
            },
            commerceChildren(kind) {
                const source = (this.commerce.sources || []).find(s => Number(s.id) === this.commerce[kind + '_primary_id']);
                return source ? (source.children || []) : [];
            },
            saveCommerce() {
                this.commerceSaving = true;
                const key = 'SETTINGS-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2);
                request({url:'setting/member-commerce-defaults',method:'put',data:Object.assign({},this.commerce,{idempotency_key:key})})
                    .then(res => { this.commerce = res.data; this.$Message.success('保存成功'); })
                    .catch(e => this.$Message.error(e.msg || '保存失败'))
                    .finally(() => {this.commerceSaving = false;});
            },
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
