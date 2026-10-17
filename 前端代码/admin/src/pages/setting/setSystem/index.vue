<template>
    <div class="article-manager">
        <div class="i-layout-page-header">
            <PageHeader class="product_tabs" :title="title" hidden-breadcrumb>
                <div v-if="currentTab" slot="content">
                    <Tabs v-model="currentTab" @on-click="changeTab">
                        <TabPane :icon="item.icon" :label="item.label" :name="item.value.toString()" v-for="(item,index) in headerList" :key="index"/>
                    </Tabs>
                </div>
            </PageHeader>
        </div>
        <Card :bordered="false" dis-hover class="ivu-mt fromBox">
            <section v-if="$route.name === 'setting_setSystem'" class="deal-people-threshold" aria-label="成交人数门槛">
                <div class="deal-people-threshold__title">成交人数门槛</div>
                <div class="deal-people-threshold__fields">
                    <label>A 来源
                        <InputNumber v-model="dealPeopleThreshold.a_yuan" :min="1" :max="99999999" :precision="0" :disabled="dealPeopleSaving" />
                        <span>元</span>
                    </label>
                    <label>其他来源
                        <InputNumber v-model="dealPeopleThreshold.other_yuan" :min="1" :max="99999999" :precision="0" :disabled="dealPeopleSaving" />
                        <span>元</span>
                    </label>
                    <Button type="primary" :loading="dealPeopleSaving" @click="saveDealPeopleThreshold">保存</Button>
                </div>
            </section>
            <Tabs type="card" v-model="childrenId" v-if="headerChildrenList.length" @on-click="changeChildrenTab">
                <TabPane :label="item.label" :name="item.id.toString()" v-for="(item,index) in headerChildrenList" :key="index"></TabPane>
            </Tabs>
            <form-create :option="option"  :rule="rules" @on-submit="onSubmit" v-if="rules.length!==0"></form-create>
            <Spin size="large" fix v-if="spinShow"></Spin>
        </Card>
    </div>
</template>

<script>
    import formCreate from '@form-create/iview'
    import { headerListApi, dataFromApi } from '@/api/setting';
    import { getDealPeopleThreshold, saveDealPeopleThreshold as updateDealPeopleThreshold } from '@/api/system';
    import request from '@/plugins/request';
    import fromSubmit from '@/components/fromSubmit/fromSubmit.vue';
    import util from '@/libs/util';

    export default {
        name: 'setting_setSystem',
        components: { formCreate: formCreate.$form(),fromSubmit:fromSubmit },
        data () {
            return {
                rules: [],
                option: {
                    form: {
                        labelWidth: 185
                    },
                    submitBtn: {
                        col: {
                            span: 3,
                            push: 3
                        }
                    },
                    global: {
                        upload: {
                            props: {
                                onSuccess (res, file) {
                                    if (res.status === 200) {
                                        file.url = res.data.src;
                                    } else {
                                        this.$Message.error(res.msg);
                                    }
                                }
                            }
                        },
                        frame: {
                            props: {
                                closeBtn: false,
                                okBtn: false
                            }
                        }
                    }
                },
                spinShow: false,
                FromData: null,
                currentTab: '',
                headerList: [],
                headerChildrenList: [],
                childrenId: '',
                title: '',
                dealPeopleThreshold: { a_yuan: 1000, other_yuan: 500, version: 0 },
                dealPeopleSaving: false
            }
        },
        created () {
            this.getAllData();
            if (this.$route.name === 'setting_setSystem') this.loadDealPeopleThreshold();
        },
        watch: {
            $route (to, from) {
                this.headerChildrenList = [];
                this.getAllData();
                if (to.name === 'setting_setSystem' && from.name !== to.name) this.loadDealPeopleThreshold();
            },
            childrenId () {
                this.getFrom();
            }
        },
        methods: {
            // 门槛是实例级版本化设置；本页仅录入整数元，历史销售单不被重新定价。
            loadDealPeopleThreshold () {
                getDealPeopleThreshold().then(res => {
                    this.dealPeopleThreshold = res.data;
                }).catch(res => this.$Message.error(res.msg || '读取成交人数门槛失败'));
            },
            saveDealPeopleThreshold () {
                const value = this.dealPeopleThreshold;
                if (![value.a_yuan, value.other_yuan].every(v => Number.isInteger(v) && v > 0)) {
                    this.$Message.error('门槛必须填写正整数元');
                    return;
                }
                this.dealPeopleSaving = true;
                updateDealPeopleThreshold(value).then(res => {
                    this.dealPeopleThreshold = res.data;
                    this.$Message.success('保存成功');
                }).catch(res => this.$Message.error(res.msg || '保存失败')).finally(() => {
                    this.dealPeopleSaving = false;
                });
            },
            childrenList () {
                let that = this;
                that.headerList.forEach(function (item) {
                    if (item.value.toString() === that.currentTab) {
                        if (item.children === undefined) {
                            that.childrenId = item.id;
                            that.headerChildrenList = [];
                        } else {
                            that.headerChildrenList = item.children
                            that.childrenId = item.children.length ? item.children[0].id.toString() : ''
                        }
                    }
                });
            },
            // 头部tab
            getHeader () {
                this.spinShow = true;
                return new Promise((resolve, reject) => {
                    let tab_id = this.$route.params.tab_id;
                    let data = {
                        type: this.$route.params.type ? this.$route.params.type : 0,
                        pid: tab_id || 0
                    };
                    headerListApi(data).then(async res => {
                        let config = res.data.config_tab;
                        this.headerList = config;
                        this.currentTab = config[0].value.toString();
                        this.childrenList();
                        resolve(this.currentTab);
                        this.spinShow = false;
                    }).catch(res => {
                        this.spinShow = false;
                        this.$Message.error(res.msg);
                    })
                });
            },
            // 表单
            getFrom () {
                this.spinShow = true;
                return new Promise((resolve, reject) => {
                    let ids = '';
                    if (this.$route.params.type === '3') {
                        ids = this.$route.params.tab_id
                    } else {
                        if (this.childrenId) {
                            ids = this.childrenId;
                        } else {
                            ids = this.currentTab;
                        }
                    }
                    let data = {
                        tab_id: Number(ids)
                    };
                    let logistics = 'freight/config/edit_basics', agent = 'agent/config/edit_basics',
                        integral = 'marketing/integral_config/edit_basics', sms = 'serve/sms_config/edit_basics',
                        config = 'setting/config/edit_basics';
                    let url = this.$route.name === 'setting_logistics' ? logistics : this.$route.name === 'setting_distributionSet'
                        ? agent : this.$route.name === 'setting_message' ? sms : this.$route.name === 'setting_setSystem' ? config : integral;
                    dataFromApi(data, url).then(async res => {
                        this.spinShow = false;
                        if (res.data.status === false) {
                            return this.$authLapse(res.data);
                        }
                        this.FromData = res.data;
                        this.rules = res.data.rules;
                        this.title = res.data.title;
                    }).catch(res => {
                        this.spinShow = false;
                        this.$Message.error(res.msg);
                    })
                });
            },
            async getAllData () {
                if (this.$route.params.type !== '3') {
                    await this.getHeader();
                } else {
                    this.headerList = [];
                    this.getFrom();
                }
            },
            // 选择
            changeTab () {
                this.childrenList();
            },
            // 二级选择
            changeChildrenTab (name) {
                this.childrenId = name;
            },
            // 提交表单 group
            onSubmit (formData) {
                request({
                    url: this.FromData.action,
                    method: this.FromData.method,
                    data: formData
                }).then(res => {
                    this.$store.dispatch('admin/account/setPageTitle')
                    this.$Message.success(res.msg);
                }).catch(res => {
                    this.$Message.error(res.msg);
                });
            }
        }
    }
</script>

<style scoped>
.deal-people-threshold { padding: 4px 0 18px; margin-bottom: 18px; border-bottom: 1px solid #e8edf2; }
.deal-people-threshold__title { margin-bottom: 12px; font-weight: 600; color: #263746; }
.deal-people-threshold__fields { display: flex; align-items: end; flex-wrap: wrap; gap: 16px; }
.deal-people-threshold__fields label { display: flex; align-items: center; gap: 8px; color: #526273; }
.deal-people-threshold__fields .ivu-input-number { width: 120px; }
</style>

<style scoped lang="stylus">
.ivu-tabs{
    // margin-bottom: 18px;
  }
.fromBox
    min-height 600px;
    // /*>>> .ivu-form-item-label*/
    //    /*width 155px !important*/
    // /*>>> .ivu-form-item-content*/
    //    /*margin-left 155px !important*/
</style>
