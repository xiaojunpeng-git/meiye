<template>
    <GlobalFooter class="i-copyright" :links="links" :copyright="copyright" />
</template>
<script>
    export default {
        name: 'i-copyright',
        data () {
            return {
                links: [],
                copyright: 'Copyright © 2024 魔核'
            }
        },
        mounted () {
            this.getVersion();
        },
        methods: {
            getVersion () {
                this.$store.dispatch('store/db/get', {
                    dbName: 'sys',
                    path: 'user.info',
                    user: true
                }).then(res => {
                    this.copyright += res.version ? '  |  ' + res.version : '';
                })
            }
        }
    }
</script>
<style lang="less">
    .i-copyright{
        flex: 0 0 auto;
        z-index: 1;
    }
</style>
