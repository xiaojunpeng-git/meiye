<template>
<!-- 运营-交易统计 -->
    <div>
        <to-day :storeList='storeList'></to-day>
        <transaction-from :storeList='storeList'></transaction-from>
    </div>
</template>

<script>
    import toDay from './components/toDay'
    import transactionFrom from './components/transaction'
	import { staffListInfo } from '@/api/store';
    export default {
        name: "index",
        components: {
            toDay,
            transactionFrom
        },
		data(){
			return {
				storeList:[]
			}
		},
		mounted(){
			this.allStore();
		},
		methods:{
			allStore(){
				staffListInfo().then(res=>{
					this.storeList = res.data;
				}).catch(err=>{
					this.$Message.error(res.msg);
				})
			},
		}
    }
</script>

<style scoped>

</style>
