<template>
<!-- 运营-商品统计 -->
    <div>
        <product-info :storeList='storeList'></product-info>
        <product-ranking :storeList='storeList'></product-ranking>
    </div>
</template>

<script>
    import productInfo from './components/productInfo'
    import productRanking from './components/productRanking'
	import { staffListInfo } from '@/api/store';
    export default {
        name: "index",
        components: {
            productInfo,
            productRanking
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