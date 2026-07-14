<template>
    <div class="transfer-box" v-if="labelLists.length">
        <div class="list-wrapper">
            <div class="user-item" v-for="(item,index) in labelLists" :key="index" @click="bindActive(item)">
                <img v-lazy="item.avatar" alt="">
                <p class="line1">{{item.wx_name}}</p>
            </div>
        </div>
    </div>
</template>

<script>
    import { transferList,serviceTransfer } from '@/api/kefu'
    export default {
        name: "transfer",
        props:{
            userUid:{
                type:String | Number,
                default:''
            }
        },
        data(){
            return{
                loading: false,
                currentChoose:'',
                labelLists: [],
                name:'',
            }
        },
        mounted() {
            this.getList()
        },
        methods:{
            getList(){
                transferList({
                    nickname:this.name,
                    uid:this.userUid
                }).then(res=>{
                    this.labelLists = res.data.list
                })
            },
            bindActive(item){
                if(this.userUid == ""){
                    return this.$Message.error('转接失败')
                }
                serviceTransfer({
                    uid:this.userUid,
                    kefuToUid:item.uid
                }).then(res=>{
                    this.$Message.success(res.msg)
                    this.$emit('close')
                }).catch(error=>{
                    this.$Message.error(error.msg)
                })
            }
        }
    }
</script>

<style lang="stylus" scoped>
.transfer-box {
	z-index: 60;
	position: absolute;
	right: 1px;
	bottom: 43px;
	width: 140px;
	background: #fff;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
	padding: 16px;
}
.list-wrapper{
	.user-item{
		display flex
		align-items center
		margin-bottom 12px
		cursor pointer
		&:last-child{
			margin-bottom 0
		}
		img{
			width 32px
			height 32px
			border-radius 50%
			margin-right 8px
		}
		p{
			width 80%
			color #333
			font-size 13px
		}
	}
}
</style>
