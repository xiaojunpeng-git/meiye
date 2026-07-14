<template>
    <GlobalFooter class="i-copyright" :links="links" :copyright="copyright" />
</template>
<script>
    import { copyright } from '@/api/account';
    export default {
        name: 'i-copyright',
        data () {
            return {
                links: [],
                copyright: ''
            }
        },
        mounted () {
            this.getCopyright();
        },
        methods: {
            getCopyright () {
                copyright().then(res=>{
                    this.copyright += res.data.copyrightContext?res.data.copyrightContext:'Copyright © 2014-2024 ';
                    this.getVersion(res);
                }).catch(err=>{
                    this.$Message.error(err.msg)
                })
            },
            getVersion (res) {
                this.$store.dispatch('store/db/get', {
                    dbName: 'sys',
                    path: 'user.info',
                    user: true
                }).then(data => {
                    this.copyright += (data.version && !res.data.copyrightContext) ? data.version : '';
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
