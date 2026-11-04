<template>
<!-- 系统设置 -->
    <div>
        <from-submit :validate="ruleValidate" :url="url" :title="title" :rules="rules">
        <template #store-settings>
        <StaffRosterSetting />
        <!-- 这些独立配置保留各自的读取、版本保护和保存接口，仅随门店设置页签展示。 -->
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
        <section class="deal-people-threshold" aria-label="业务来源设置">
            <div class="deal-people-threshold__title">业务来源设置</div>
            <div class="deal-people-threshold__fields">
                <label>来源设置
                    <i-switch v-model="commerce.source_enabled" :true-value="1" :false-value="0" size="large" :disabled="!commerceLoaded || commerceSaving">
                        <span slot="open">开启</span><span slot="close">关闭</span>
                    </i-switch>
                </label>
                <label v-if="commerce.source_enabled === 1">默认方式
                    <Select v-model="commerce.inherit_previous" style="width:200px" :disabled="!commerceLoaded || commerceSaving">
                        <Option :value="0">不默认上次选择</Option>
                        <Option :value="1">默认上次选择</Option>
                    </Select>
                </label>
                <!-- 会员下单不弹出来源选择器；购买来源必须始终可见，且从现有目录中显式配置。 -->
                <label>手机端购买来源
                    <Select v-model="commerce.purchase_primary_id" style="width:180px" :disabled="!commerceLoaded || commerceSaving" placeholder="请选择一级来源" @on-change="commerce.purchase_secondary_id = 0">
                        <Option v-for="source in commerce.sources || []" :key="source.id" :value="Number(source.id)">{{source.name}}</Option>
                    </Select>
                    <Select v-if="commerceChildren('purchase').length" v-model="commerce.purchase_secondary_id" style="width:160px" :disabled="!commerceLoaded || commerceSaving" placeholder="请选择二级来源">
                        <Option :value="0">请选择二级来源</Option>
                        <Option v-for="source in commerceChildren('purchase')" :key="source.id" :value="Number(source.id)">{{source.name}}</Option>
                    </Select>
                </label>
                <label v-for="entry in commerce.source_enabled === 0 ? commerceFixedKinds : []" :key="entry.key">{{entry.label}}
                    <Select v-model="commerce[entry.key + '_primary_id']" style="width:180px" :disabled="!commerceLoaded || commerceSaving" @on-change="commerce[entry.key + '_secondary_id'] = 0">
                        <Option v-for="source in commerce.sources || []" :key="source.id" :value="Number(source.id)">{{source.name}}</Option>
                    </Select>
                    <Select v-if="commerceChildren(entry.key).length" v-model="commerce[entry.key + '_secondary_id']" style="width:160px" :disabled="!commerceLoaded || commerceSaving">
                        <Option :value="0">请选择二级来源</Option>
                        <Option v-for="source in commerceChildren(entry.key)" :key="source.id" :value="Number(source.id)">{{source.name}}</Option>
                    </Select>
                </label>
                <Button type="primary" :disabled="!commerceLoaded || commerceSaving" :loading="commerceSaving" @click="saveCommerce">保存</Button>
            </div>
            <div v-if="commerceLoaded && !commerce.purchase_primary_id" class="deal-people-threshold__error" role="status">请在这里设置手机端购买来源；未设置前，会员端不能购买。</div>
            <div v-if="commerceLoadFailed" class="deal-people-threshold__error">读取业务来源设置失败，请刷新页面后重试。</div>
        </section>
        </template>
        </from-submit>
    </div>
</template>

<script>
    import StaffRosterSetting from './StaffRosterSetting.vue';
    import fromSubmit from '@/components/fromSubmit/fromSubmit.vue';
    import { getDealPeopleThreshold, saveDealPeopleThreshold as updateDealPeopleThreshold } from '@/api/system';
    import buildData from "./buildData";
    import request from '@/plugins/request';

    export default {
        name: "base-index",
        components:{fromSubmit,StaffRosterSetting},
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
                commerce: { sources: [], version: 0, source_enabled: 0, inherit_previous: 0,
                    purchase_primary_id: 0, purchase_secondary_id: 0, recharge_primary_id: 0,
                    recharge_secondary_id: 0, repayment_primary_id: 0, repayment_secondary_id: 0 },
                commerceLoaded: false, commerceLoadFailed: false, commerceSaving: false,
                commerceKinds: [{key:'purchase',label:'手机端购买来源'}, {key:'recharge',label:'充值固定来源'}, {key:'repayment',label:'补交固定来源'}],
                commerceFixedKinds: [{key:'recharge',label:'充值固定来源'}, {key:'repayment',label:'补交固定来源'}]
            };
        },
        created() {
            this.loadDealPeopleThreshold();
            this.loadCommerce();
        },
        methods: {
            // 配置以同一接口快照为准；严格接收布尔或 0/1，避免关闭值被默认成开启。
            normalizeCommerce(data) {
                if (!data || !Array.isArray(data.sources)) throw new Error('业务来源设置返回不完整');
                const value = Object.assign({}, data);
                ['source_enabled', 'inherit_previous'].forEach(key => {
                    if (![true, false, 0, 1, '0', '1'].includes(data[key])) throw new Error('业务来源模式返回无效');
                    value[key] = Number(data[key]);
                });
                const keys = ['version'];
                this.commerceKinds.forEach(entry => keys.push(entry.key + '_primary_id', entry.key + '_secondary_id'));
                keys.forEach(key => {
                    if (data[key] === null || data[key] === undefined || data[key] === '' || typeof data[key] === 'boolean'
                        || !Number.isSafeInteger(Number(data[key])) || Number(data[key]) < 0) throw new Error('业务来源配置返回无效');
                    value[key] = Number(data[key]);
                });
                return value;
            },
            // 读取失败立即封闭写入，不能将页面初值保存为客户配置。
            loadCommerce() {
                this.commerceLoaded = false;
                this.commerceLoadFailed = false;
                return request({url:'setting/member-commerce-defaults',method:'get'}).then(res => {
                    this.commerce = this.normalizeCommerce(res.data);
                    this.commerceLoaded = true;
                }).catch(e => {
                    this.commerceLoadFailed = true;
                    this.$Message.error(e.msg || e.message || '读取业务来源设置失败');
                });
            },
            // 二级候选只能来自当前一级；切换一级时由控件清空原二级 ID。
            commerceChildren(kind) {
                const source = (this.commerce.sources || []).find(s => Number(s.id) === this.commerce[kind + '_primary_id']);
                return source ? (source.children || []) : [];
            },
            saveCommerce() {
                if (!this.commerceLoaded || this.commerceSaving) return;
                // 仅关闭模式要求三类固定来源；开启时保留既有配置，不阻塞模式切换。
                if (this.commerce.source_enabled === 0) {
                    for (const entry of this.commerceKinds) {
                        const primary = (this.commerce.sources || []).find(source => Number(source.id) === this.commerce[entry.key + '_primary_id']);
                        const children = this.commerceChildren(entry.key);
                        if (!primary || (children.length && !children.some(source => Number(source.id) === this.commerce[entry.key + '_secondary_id']))) {
                            this.$Message.error('请选择完整的' + entry.label);
                            return;
                        }
                    }
                }
                this.commerceSaving = true;
                const key = 'SETTINGS-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2);
                // 写入仅携带配置白名单与原版本，不发送来源目录或其他基础设置。
                const data = { version: this.commerce.version, source_enabled: this.commerce.source_enabled,
                    inherit_previous: this.commerce.inherit_previous, idempotency_key: key };
                this.commerceKinds.forEach(entry => {
                    data[entry.key + '_primary_id'] = this.commerce[entry.key + '_primary_id'];
                    data[entry.key + '_secondary_id'] = this.commerce[entry.key + '_secondary_id'];
                });
                return request({url:'setting/member-commerce-defaults',method:'put',data})
                    .then(res => { this.commerce = this.normalizeCommerce(res.data); this.$Message.success('保存成功'); })
                    .catch(e => this.$Message.error(e.msg || e.message || '保存失败'))
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
