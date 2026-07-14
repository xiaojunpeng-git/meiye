<template>
<!-- 门店-基础设置 -->
    <div>
        <Card :bordered="false" dis-hover class="ivu-mt store-setting-tabs-card" :padding="0">
            <div class="store-setting-tabs">
                <store-menu-tabs />
            </div>
        </Card>
        <from-submit :validate="ruleValidate" :url="url" :title="title" :rules="rules"/>
    </div>
</template>

<script>
    import fromSubmit from '@/components/fromSubmit/fromSubmit.vue';
    import storeMenuTabs from '@/components/storeMenuTabs';
    import buildData from "@/pages/setting/shop/buildData";

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
                type: 'store'
            };
        },
    }
</script>

<style scoped lang="stylus">
.store-setting-tabs-card
    /deep/.ivu-card-body
        padding 0 16px
.store-setting-tabs
    padding-top 4px
</style>
