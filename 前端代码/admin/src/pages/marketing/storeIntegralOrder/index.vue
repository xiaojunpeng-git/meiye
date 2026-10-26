<template>
<!-- 订单-积分订单 -->
    <div>
        <productlist-details v-if="currentTab === 'article' || 'project' || 'app'" ref="productlist" ></productlist-details>
        <Spin size="large" fix v-if="spinShow"></Spin>
    </div>
</template>

<script>
    import productlistDetails from './orderlistDetails';
    import { mapMutations } from 'vuex';
    export default {
        name: 'list',
        components: {
            productlistDetails
        },
        data () {
            return {
                spinShow: false,
                currentTab: '',
                data: [],
                tablists: null
            }
        },
        created () {
            this.getOrderType('');
            this.getOrderStatus('');
            this.getOrderTime('');
            this.getOrderNum('');
            this.getfieldKey('');
            this.onChangeTabs('');
            this.getOrderRealName('');
        },
        beforeDestroy () {
            this.getOrderType('');
            this.getOrderStatus('');
            this.getOrderTime('');
            this.getOrderNum('');
            this.getfieldKey('');
            this.onChangeTabs('');
            this.getOrderRealName('');
        },
        mounted () {
            this.getTabs()
        },
        methods: {
            ...mapMutations('admin/integralOrder', [
                'onChangeTabs',
                'getOrderStatus',
                'getOrderTime',
                'getOrderNum',
                'getfieldKey',
                'onChangeTabs',
                'getOrderTabs',
                'getOrderType',
                'getOrderRealName'
                // 'onChangeChart'
            ]),
            // 订单类型  @on-changeTabs="getChangeTabs"
            getTabs () {
                this.spinShow = true;
                this.$store.dispatch('admin/integralOrder/getOrderTabs', {product_id: this.$route.query.product_id || ""}).then(res => {
                    this.tablists = res.data;
                    // this.onChangeChart(this.tablists)
                    this.spinShow = false;
                }).catch(res => {
                    this.spinShow = false;
                    this.$Message.error(res.msg);
                })
            },
            onClickTab () {
                this.onChangeTabs(Number(this.currentTab))
                this.$refs.productlist.getChangeTabs()
                this.$store.dispatch('admin/integralOrder/getOrderTabs', { type: this.currentTab });
            }
        }
    }
</script>
<style scoped lang="stylus">
    .product_tabs >>> .ivu-tabs-bar
      margin-bottom 0px !important
    .product_tabs >>> .ivu-page-header-content
      margin-bottom 0px !important
    .product_tabs >>> .ivu-page-header-breadcrumb
        margin-bottom 0px !important
</style>
