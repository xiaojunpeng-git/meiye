<template>
    <div class="auxScreen">
        <div class="header acea-row row-between-wrapper">
            <div class="left acea-row row-middle">
                <div class="pictrue">
                    <img :src="userInfo.avatar" v-if="userInfo.avatar">
                    <img src="@/assets/images/ren.png" v-else>
                </div>
                <div class="name">{{userInfo.nickname}}</div>
                <div class="phone">手机号：{{userInfo.phone || '-'}}</div>
            </div>
            <div class="right acea-row row-middle">
                <div class="item">积分<span class="num">{{userInfo.integral || '-'}}</span></div>
                <div class="item">余额<span class="num">{{userInfo.now_money || '-'}}</span></div>
            </div>
        </div>
        <div class="selected acea-row row-middle">已选购<span class="num">{{count}}</span>件</div>
        <div class="list" v-if="cartList.length">
            <div class="item" v-for="(item,index) in cartList" :key="index+'cart'">
                <div class="activeCon" v-if="item.promotions.length">
                    <div class="activity" v-for="(n,nIndex) in  item.promotions" :key="nIndex">
                        <span class="label">{{n.title}}</span>{{n.desc}}
                    </div>
                </div>
                <div class="picTxt acea-row row-between-wrapper" v-for="(list,indexs) in item.cart" :key="indexs+'l'">
                    <div class="acea-row row-middle">
                        <div class="pictrue">
                            <img v-if="list.productInfo.attrInfo" :src="list.productInfo.attrInfo.image" alt="">
                            <img v-else :src="list.productInfo.image"/>
                        </div>
                        <div class="text">
                            <div class="name line1"><span class="giftTxt" v-if="list.is_gift">赠品</span>{{ list.productInfo.store_name }}</div>
                            <div v-if="list.productInfo.attrInfo && list.productInfo.spec_type">{{ list.productInfo.attrInfo.suk }}</div>
                            <div v-else>默认</div>
                        </div>
                    </div>
                    <div>
                        <span class="cartNum">x{{ list.cart_num }}</span>
                        <span class="money">¥{{ list.sum_price }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="list" v-else>
            <div class="noGoods">
                <img src="@/assets/images/no-cart.png" class="img">
                <div class="tip">暂无商品，快去添加吧～</div>
            </div>
        </div>
        <footer class="footer acea-row row-middle row-right">
            <div>优惠：-{{
                this.$computes.Sub(
                priceInfo.sumPrice || 0,
                priceInfo.payPrice || 0
                ) || 0
                }}</div>
            <div class="payment acea-row row-middle">实付：<span class="label">￥</span><span class="num">{{priceInfo.payPrice ? priceInfo.payPrice : 0}}</span></div>
        </footer>
    </div>
</template>
<script>
    import wsSocket from "@/libs/socket";
    import {
        cashierUser,
        cashierCartList,
        cashierCompute,
        auxScreen
    } from "@/api/order";
    export default{
        data(){
            return{
                socket:null,
                userInfo:{},
                cartList:[],
                uid:0,//用户uid
                count:0,
                cashierId:0, //店员id
                touristUid:0, //游客uid
                priceInfo:{}
            }
        },
        created(){
            this.getAuxScreen();
            this.socket = new wsSocket({key:1});
            //监听用户
            this.socket.$on("changUser", (data) => {
                if(data.uid){
                    this.uid = data.uid
                  this.touristUid = 0;
                }
                if(data.cashier_id){
                    this.cashierId = data.cashier_id;
                }
                console.log(data)
                if(data.tourist_uid){
                    this.touristUid = data.tourist_uid;
                   this.uid = 0;
                    this.$set(this,'userInfo',{});
                }
                if(data.change_price){
                  this.priceInfo.payPrice = data.change_price;
                }else{
                  let datas = { tourist_uid: this.touristUid ,socket:1};
                  if(this.uid){
                    this.userInfoData({uid:this.uid,socket:1})
                  }
                  this.getCartList(this.uid,this.cashierId,datas);
                }
            });
            //监听购物车
            this.socket.$on("changCart", (data) => {
                let uid = data.uid || this.uid;
                let datas = { tourist_uid: this.touristUid ,socket:1};
                this.getCartList(uid, this.cashierId, datas);
            });

            this.socket.$on("changCartRemove", (data) => {
              this.priceInfo = {};
            })

            //监听购物车清空
            this.socket.$on("changSuccess", (data) => {
                this.cartList = [];
                this.priceInfo = {};
                this.userInfo = {};
            });
            //监听计算
            this.socket.$on("changCompute", (data) => {
                let uid = data.uid || this.uid;
                let postData = data.post_data;
                postData.socket = 1;
                this.cartCompute(uid,postData);
            });
        },
        mounted(){},
        methods:{
            getAuxScreen(){
                auxScreen().then(res=>{
                    this.cashierId = res.data.cashier_id || 0;
                    this.uid = res.data.uid || 0;
                    this.touristUid = res.data.tourist_uid || 0;
                    if(!res.data.tourist){
                        this.touristUid = 0
                    }else{
                        this.uid = 0
                    }
                    if(this.uid){
                        this.userInfoData({uid:this.uid,socket:1})
                        let data = { tourist_uid: this.touristUid ,socket:1};
                        this.getCartList(this.uid,this.cashierId,data);
                    }
                }).catch(err=>{
                    this.$Message.error(err);
                })
            },
            userInfoData(data) {
                cashierUser(data)
                    .then((res) => {
                        this.userInfo = res.data;
                    })
                    .catch((err) => {
                        this.$Message.error(err);
                    });
            },
            getCartList(uid, staffId, data) {
                cashierCartList(uid, staffId, data)
                    .then((res) => {
                        this.cartList = res.data.valid;
                        this.count = res.data.count
                        let datas = {
                            coupon: false,
                            coupon_id: 0,
                            integral: false,
                            cart_id:[],
                            socket:1
                        };
                        res.data.valid.forEach(item=>{
                            item.cart.forEach(j=>{
                                datas.cart_id.push(j.id)
                            })
                        });
                        this.cartCompute(uid,datas);
                    })
                    .catch((err) => {
                        this.$Message.error(err.msg);
                    })
            },
            cartCompute(uid,data) {
                if(!data.cart_id.length) return false
                cashierCompute(uid, data)
                    .then((res) => {
                        this.priceInfo = res.data;
                    })
                    .catch((err) => {
                        this.$Message.error(err.msg);
                    });
            },
        }
    }
</script>
<style lang="less" scoped>
    .auxScreen{
        width: 100%;
        height: 100vh;
        background-color: #fff;
        padding: 20px 25px;
        .header{
            height: 66px;
            background: rgba(255,119,0,0.05);
            border-radius: 10px;
            border: 2px solid #FF7700;
            padding: 0 24px;
            .left{
                .pictrue{
                    width: 40px;
                    height: 40px;
                    border-radius: 100%;
                    img{
                        width: 100%;
                        height: 100%;
                        border-radius: 100%;
                    }
                }
                .name{
                    font-size: 16px;
                    font-weight: 600;
                    margin-left: 12px;
                }
                .phone{
                    margin-left: 18px;
                    font-size: 12px;
                    color: #999;
                }
            }
            .right{
                .item{
                    margin-left: 20px;
                    color: rgba(51,51,51,0.85);
                    font-size: 12px;
                    .num{
                        color: #303133;
                        font-size: 17px;
                        font-weight: 600;
                        margin-left: 5px;
                    }
                }
            }
        }
        .selected{
            height: 49px;
            font-size: 14px;
            font-weight: 400;
            color: rgba(0,0,0,0.85);
            border-bottom: 1px solid #eee;
            .num{
                color: #FF7700;
                padding: 0 5px;
            }
        }
        .list{
            height: calc(~'100vh - 205px');
            overflow: auto;
            .noGoods{
                width: 180px;
                margin: 74px auto 0 auto;
                .img{
                    width: 100%;
                    height: 140px;
                    display: block;
                }
                .tip{
                    text-align: center;
                    margin-top: 26px;
                    color: rgba(153,153,153,0.85);
                    font-size: 14px;
                }
            }
            .item{
                border-bottom: 1px solid #eeee;
                padding: 15px 0 15px 0;
                .activeCon{
                    margin-bottom: 16px;
                    .activity{
                        margin-bottom: 10px;
                        .label{
                            background: rgba(255, 119, 0, 0.1);
                            border-radius: 3px;
                            padding: 3px 6px;
                            font-size: 12px;
                            color: #FF7700;
                            margin-right: 6px;
                        }
                    }
                }
                .picTxt{
                    font-weight: bold;
                    .cartNum{
                        font-size: 16px;
                        color: #333333;
                    }
                    .money{
                        font-size: 16px;
                        color: rgba(0,0,0,0.85);
                        margin-left: 89px;
                    }
                    .pictrue{
                        width: 66px;
                        height: 66px;
                        border-radius: 4px;
                        img{
                            width: 100%;
                            height: 100%;
                            border-radius: 4px;
                        }
                    }
                    .text{
                        font-size: 13px;
                        color: #999999;
                        margin-left: 13px;
                        .name{
                            font-size: 16px;
                            color: rgba(0,0,0,0.85);
                            margin-bottom: 2px;
                            width: 300px;
                            .giftTxt{
                                border-radius: 2px;
                                border: 1px solid #F5222D;
                                color: #E93323;
                                font-size: 12px;
                                width: 36px;
                                height: 20px;
                                line-height: 20px;
                                text-align: center;
                                display: inline-block;
                                margin-right: 2px;
                            }
                        }
                    }
                }
            }
        }
        .footer{
            background-color: #fff;
            width: 100%;
            height: 70px;
            box-shadow: 0px -1px 11px 0px rgba(0,0,0,0.06);
            position: fixed;
            bottom: 0;
            left:0;
            padding-right: 27px;
            color: #000000;
            font-size: 14px;
            .payment{
                margin-left: 31px;
                .label{
                    font-size: 16px;
                    color: #F5222D;
                }
                .num{
                    font-size: 24px;
                    color: #F5222D;
                }
            }
        }
    }
</style>
