<template>
<!-- 门店-基础设置 -->
<!-- wpm-save-fix-20260723 -->
    <div>
        <Card :bordered="false" dis-hover class="ivu-mt store-setting-tabs-card" :padding="0">
            <div class="store-setting-tabs">
                <store-menu-tabs />
            </div>
        </Card>
        <from-submit
            :validate="ruleValidate"
            :url="url"
            :title="title"
            :rules="rules"
            :on="formSubmitHandlers"
        >
            <div slot="content" class="writeoff-performance-slot">
                <Card :bordered="false" dis-hover class="writeoff-performance-card">
                    <div class="setting-heading">
                        <div>
                            <span class="setting-kicker">总部统一设置</span>
                            <h3>核销业绩计算方式</h3>
                            <p>由总部统一控制，保存后对所有门店生效，门店不可单独修改。</p>
                        </div>
                        <span class="preview-tag">总部统一 · 全部门店生效</span>
                    </div>
                    <div class="performance-options">
                        <button
                            type="button"
                            :class="['performance-option', { active: writeoffPerformanceMode === 'commission' }]"
                            @click="writeoffPerformanceMode = 'commission'"
                        >
                            <span class="radio-dot"><i></i></span>
                            <span class="option-copy">
                                <strong>按业绩提成计算 <em>原逻辑</em></strong>
                                <small>继续使用系统当前的项目业绩与提成规则计算手艺人的核销业绩。</small>
                            </span>
                            <Icon type="ios-people-outline" />
                        </button>
                        <button
                            type="button"
                            :class="['performance-option', { active: writeoffPerformanceMode === 'writeoff_amount' }]"
                            @click="writeoffPerformanceMode = 'writeoff_amount'"
                        >
                            <span class="radio-dot"><i></i></span>
                            <span class="option-copy">
                                <strong>按核销金额计算</strong>
                                <small>手艺人的核销业绩，直接按照其负责项目的本次核销金额计算。</small>
                            </span>
                            <Icon type="ios-calculator-outline" />
                        </button>
                    </div>
                    <div class="impact-note">
                        <Icon type="ios-information-circle" />
                        <span><strong>统计口径：</strong>该设置只改变手艺人的核销业绩金额，不改变顾客卡项余额、核销金额及核销订单金额。切换后只影响新产生的核销业绩，历史记录不变。</span>
                    </div>
                    <div class="setting-footer">
                        <span><Icon type="ios-lock-outline" /> 仅总部可保存；与上方门店设置一并点「提交」生效，也可单独保存</span>
                        <Button type="primary" :loading="savingPerformanceMode" @click="savePerformanceMode(true)">保存设置</Button>
                    </div>
                </Card>
            </div>
        </from-submit>
    </div>
</template>

<script>
    import fromSubmit from '@/components/fromSubmit/fromSubmit.vue';
    import storeMenuTabs from '@/components/storeMenuTabs';
    import buildData from "@/pages/setting/shop/buildData";
    import { getWriteoffPerformanceMode, saveWriteoffPerformanceMode } from '@/api/system';

    export default {
        name: "index",
        components:{ fromSubmit, storeMenuTabs },
        mixins:[buildData],
        data() {
            return {
                ruleValidate: {},
                rules: [],
                url: '',
                title:'门店设置',
                type: 'store',
                writeoffPerformanceMode: 'commission',
                savingPerformanceMode: false
            };
        },
        computed: {
            formSubmitHandlers() {
                return {
                    submit: () => {
                        this.savePerformanceMode(false);
                    }
                };
            }
        },
        created() {
            this.loadPerformanceMode();
        },
        methods: {
            loadPerformanceMode() {
                getWriteoffPerformanceMode().then((res) => {
                    const mode = (res && res.data && res.data.mode) || (res && res.mode) || 'commission';
                    this.writeoffPerformanceMode = mode === 'writeoff_amount' ? 'writeoff_amount' : 'commission';
                }).catch(() => {
                    this.writeoffPerformanceMode = 'commission';
                });
            },
            /**
             * @param {boolean} showSuccess 单独点「保存设置」时提示；跟主表单「提交」一起时不重复刷成功 toast
             */
            savePerformanceMode(showSuccess = true) {
                if (this.savingPerformanceMode) return Promise.resolve();
                this.savingPerformanceMode = true;
                const idem = `wpm_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
                return saveWriteoffPerformanceMode({
                    mode: this.writeoffPerformanceMode,
                    idempotency_key: idem
                }).then(() => {
                    if (showSuccess) {
                        const label = this.writeoffPerformanceMode === 'writeoff_amount'
                            ? '按核销金额计算'
                            : '按业绩提成计算（原逻辑）';
                        this.$Message.success(`已保存为“${label}”，对所有门店生效`);
                    }
                }).catch((err) => {
                    this.$Message.error((err && (err.msg || err.message)) || '核销业绩计算方式保存失败');
                    return Promise.reject(err);
                }).finally(() => {
                    this.savingPerformanceMode = false;
                });
            }
        }
    }
</script>

<style scoped lang="stylus">
.store-setting-tabs-card
    /deep/.ivu-card-body
        padding 0 16px
.store-setting-tabs
    padding-top 4px
.writeoff-performance-slot
    margin 8px 0 24px
.writeoff-performance-card
    border-radius 12px
    border 1px solid #eef0f3
    /deep/.ivu-card-body
        padding 24px
.setting-heading
    display flex
    align-items flex-start
    justify-content space-between
    padding-bottom 18px
    border-bottom 1px solid #eef0f3
    h3
        margin 5px 0 6px
        color #303133
        font-size 20px
    p
        margin 0
        color #909399
.setting-kicker
    color #1890ff
    font-size 12px
    font-weight 600
    letter-spacing 1px
.preview-tag
    padding 5px 10px
    border-radius 12px
    color #7c672f
    background #fff6d9
    font-size 11px
.performance-options
    display grid
    grid-template-columns repeat(2, minmax(0, 1fr))
    gap 14px
    margin-top 20px
.performance-option
    min-height 120px
    padding 20px
    display flex
    align-items flex-start
    border 1px solid #e5e8ec
    border-radius 12px
    color #303133
    background #fff
    text-align left
    cursor pointer
    transition .2s
    &:hover
        border-color #a8d1fa
    &.active
        border-color #1890ff
        background #f6fbff
        box-shadow 0 0 0 2px rgba(24, 144, 255, .07)
        .radio-dot
            border-color #1890ff
            i
                width 9px
                height 9px
                border-radius 50%
                background #1890ff
    > .ivu-icon
        margin-left 12px
        color #86bae9
        font-size 30px
.radio-dot
    width 18px
    height 18px
    flex 0 0 18px
    display flex
    align-items center
    justify-content center
    margin 2px 11px 0 0
    border 1px solid #bdc4cc
    border-radius 50%
.option-copy
    flex 1
    display flex
    flex-direction column
    strong
        font-size 15px
    em
        padding 2px 7px
        margin-left 5px
        border-radius 9px
        color #61758a
        background #edf2f7
        font-size 10px
        font-style normal
    small
        margin-top 9px
        color #909399
        line-height 1.65
.impact-note
    margin-top 15px
    padding 12px 14px
    display flex
    border-radius 9px
    color #536d84
    background #f2f7fc
    .ivu-icon
        margin 1px 8px 0 0
        color #1890ff
        font-size 18px
.setting-footer
    margin-top 18px
    padding-top 16px
    display flex
    align-items center
    justify-content space-between
    border-top 1px solid #eef0f3
    > span
        color #909399
        font-size 12px
@media (max-width: 900px)
    .performance-options
        grid-template-columns 1fr
</style>
