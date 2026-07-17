<template>
	<div>
		<Row :gutter="24" class="dashboard-console-grid pl-6 pr-6">
		    <Col v-bind="grid2" class="ivu-mb">
		        <Card :bordered="false" dis-hover>
					<div class="title">待办事项</div>
					<Row :gutter="24" class="list">
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(1)">
								<div class="pictrue">
									<img src="@/assets/images/home1.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unshippedOrder}}</div>
									<div>待发货订单</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(2)">
								<div class="pictrue">
									<img src="@/assets/images/home2.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unVerifyRefundOrder}}</div>
									<div>待处理售后</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(3)">
								<div class="pictrue">
									<img src="@/assets/images/home3.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.outofStockProduct}}</div>
									<div>售罄商品</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(4)">
								<div class="pictrue">
									<img src="@/assets/images/home4.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.policeForceProduct}}</div>
									<div>预警库存</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(6)">
								<div class="pictrue">
									<img src="@/assets/images/home6.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unVerifyExtract}}</div>
									<div>待审核提现</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(5)">
								<div class="pictrue">
									<img src="@/assets/images/home5.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unVerifyProduct}}</div>
									<div>待审核商品</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(9)">
								<div class="pictrue">
									<img src="@/assets/images/home9.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unVerifyCommunity}}</div>
									<div>待审核内容</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(7)">
								<div class="pictrue">
									<img src="@/assets/images/home7.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unVerifyCommunityComment}}</div>
									<div>待审核评论</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(11)">
								<div class="pictrue">
									<img src="@/assets/images/home11.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unVerifyStore}}</div>
									<div>门店申请</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(8)">
								<div class="pictrue">
									<img src="@/assets/images/home8.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unVerifySupplier}}</div>
									<div>供应商申请</div>
								</div>
							</div>
						</Col>
						<Col v-bind="grid4">
							<div class="item acea-row" @click="goPage(12)">
								<div class="pictrue">
									<img src="@/assets/images/home1.png"/>
								</div>
								<div class="text">
									<div class="num">{{dataInfo.unHandleStockRequest || 0}}</div>
									<div>总部仓请货待办</div>
								</div>
							</div>
						</Col>
					</Row>
		        </Card>
		    </Col>
		    <Col v-bind="grid" class="ivu-mb">
		        <Card :bordered="false" dis-hover>
					<div class="acea-row row-between-wrapper">
						<div class="title on">快捷入口</div>
						<div class="set acea-row row-middle" @click="setQuick">
							<span class="iconfont iconshezhi2"></span>
							<span class="ml-3">设置</span>
						</div>
					</div>
					<Row :gutter="24" class="right">
						<Col v-bind="grid3" v-for="(item,index) in quickList" :key="index">
							<div class="item" @click="goPage(10,item.url)">
								<div class="pictrue">
									<img :src="item.image" />
								</div>
								<div class="name">{{item.name}}</div>
							</div>
						</Col>
					</Row>
		        </Card>
		    </Col>
		</Row>
		<Modal 
		  v-model="quickModal"
		  scrollable
		  title="快捷入口管理"
		  :closable="true"
		  width="710"
		  :mask-closable="false"
		  @on-visible-change="handleVisibleChange"
		>
		  <div class="quick">
		  	<div class="titles">已添加<span class="num">最多可添加6项</span></div>
		  	<div class="list acea-row row-middle">
		  		<div class="item acea-row row-center-wrapper" v-for="(item,index) in quickList" :key="index">
					<div>
						<div class="pictrue">
							<img :src="item.image" />
						</div>
						<div class="name">{{item.name}}</div>
					</div>
					<div class="icon iconfont iconshanchu-01" @click="removeMenus(item,index)"></div>
		  		</div>
		  	</div>
			<div>
				<div class="titles">商城</div>
				<div class="list acea-row row-middle">
					<div class="item acea-row row-center-wrapper" v-for="(item, index) in dataList" :key="index">
						<div>
							<div class="pictrue">
								<img :src="item.image" />
							</div>
							<div class="name">{{item.name}}</div>
						</div>
						<div class="icon iconfont icontianjia-01" v-if="!item.is_show" @click="addMenus(item)"></div>
						<div class="bottom acea-row row-center-wrapper" v-if="!item.is_show && item.admin_id">
							<span class="iconfont iconbianji" @click="edit(item)"></span>
							<span class="iconfont iconshanchu1" @click="del(item,'删除快捷入口',index)"></span>
						</div>
					</div>
					<div class="item acea-row row-center-wrapper" @click="add">
						<div>
							<div class="pictrue">
								<img src="@/assets/images/quick14.png" />
							</div>
							<div class="name">自定义</div>
						</div>
					</div>
				</div>
			</div>
		  </div>
		  <div slot="footer">
		    <Button @click="cancelQuick">取消</Button>
		    <Button type="primary" @click="saveQuick">保存</Button>
		  </div>
		</Modal>
		<Modal
		  v-model="customModal"
		  scrollable
		  title="自定义快捷入口"
		  :closable="true"
		  width="553"
		  :mask-closable="false"
		>
		   <div>
			   <Form ref="form" :rules="rules" :model="form" :label-width="90">
				   <FormItem label="图标：" prop="image">
				     <div v-if="form.image" class="upload-item">
				       <div class="w-48 h-48 relative">
				         <img class="w-full h-full" :src="form.image" />
				         <Button
						     class="abs-rt"
				             shape="circle"
				             icon="ios-close"
				             @click="delImage"
				         ></Button>
				       </div>
				     </div>
				     <Button
				         v-else
				         class="w-48 h-48"
				         type="default"
				         icon="ios-add"
				         @click="modalPicTap('dan', 'image', 1)"
				     ></Button>
				   </FormItem>
				   <FormItem label="名称：" prop="name">
				     <Input v-model="form.name" maxlength="4" show-word-limit placeholder="请填写名称" class=""></Input>
				   </FormItem>
				   <FormItem label="页面链接：" prop="url">
					   <div class="acea-row row-middle">
						   <Input v-model="form.url" placeholder="请填写页面链接" class="w-367 mr-10"></Input>
						   <Poptip placement="bottom" trigger="hover" width="345" transfer padding="8px">
						     <a>查看示例</a>
						     <div class="exampleImg" slot="content">
							   <div class="fs-12 text-wlll-303133">页面链接:</div>
						       <img
							     src="@/assets/images/link-img.png"
						         alt=""
						       />
						     </div>
						   </Poptip>
					   </div>
				   </FormItem>
			   </Form>
		   </div>
		   <div slot="footer">
		     <Button @click="cancelCustom">取消</Button>
		     <Button type="primary" @click="saveCustom">保存</Button>
		   </div>
		</Modal>
		<Modal
		    v-model="modalPic"
		    width="960px"
		    scrollable
		    footer-hide
		    closable
		    title="上传图标"
		    :mask-closable="false"
		    :z-index="1000"
		>
		  <uploadPictures
		      isChoice="单选"
		      @getPic="getPic"
		      v-if="modalPic"
		  ></uploadPictures>
		</Modal>
	</div>
</template>

<script>
	import Setting from '@/setting';
    import echarts from 'echarts';
    import { getJnotice, getFastMenus, setFastMenus, postCustomMenus, getCustomMenus } from '@/api/index';
    import { forEach } from 'lodash';
	import uploadPictures from "@/components/uploadPictures";
    export default {
		components:{
		  uploadPictures
		},
        data () {
            return {
				roterPre: Setting.roterPre,
                grid: {
                    xl: 6,
                    lg: 12,
                    md: 12,
                    sm: 12,
                    xs: 24
                },
				grid2:{
					xl: 18,
					lg: 12,
					md: 12,
					sm: 12,
					xs: 24
				},
				grid3: {
				    xl: 8,
				    lg: 12,
				    md: 12,
				    sm: 12,
				    xs: 24
				},
				grid4: {
				    xl: 5,
				    lg: 12,
				    md: 12,
				    sm: 12,
				    xs: 24
				},
				dataInfo:{},
				quickList:[],
				quickModal:false,
				dataList:[],
				customModal:false,
				rules: {
					image: [
					  { required: true, message: "请上传图标", trigger: "change" }
					],
					name: [
					  { required: true, message: '请填写名称', trigger: 'blur' }
					],
					url: [
					  { required: true, message: '请填写页面链接', trigger: 'blur' }
					]
				},
				form: {
				  image: 0,
				  name: '',
				  url: '',
				  is_show:0
				},
				modalPic: false,
				id:0 //自定义快捷入口id
            }
        },
		mounted () {
			this.jnotice();
			this.fastMenus();
		},
        methods: {
			handleVisibleChange(customModal) {
				console.log('4545');
				if (customModal) {
					console.log('4545');
				  document.body.classList.add('modal-open');
				} else {
					console.log('6666');
				  document.body.classList.remove('modal-open');
				}
			},
			del (row, tit, num) {
			    let delfromData = {
			        title: tit,
			        num: num,
			        url: `system/custom/menus/${row.id}`,
			        method: 'DELETE',
			        ids: ''
			    };
			    this.$modalSure(delfromData).then((res) => {
			        this.$Message.success(res.msg);
					this.fastMenus();
			    }).catch(err => {
			        this.$Message.error(err.msg);
			    });
			},
			add(){
			   this.$refs.form.resetFields();
			   this.customModal = true;
			},
			edit(item){
				this.id = item.id;
				this.$refs.form.resetFields();
				getCustomMenus(item.id).then(res=>{
					this.customModal = true;
					this.form = res.data;
				}).catch(err=>{
					this.$Message.error(res.msg);
				})
			},
			cancelCustom(){
				this.customModal = false;
			},
			saveCustom(){
			   this.$refs.form.validate((valid) => {
			     if (valid){
			       postCustomMenus(this.form,this.id).then(res=>{
			         this.$Message.success(res.msg);
			         this.customModal = false;
			         this.fastMenus();
			       }).catch((err) => {
			         this.$Message.error(err.msg);
			       });
			     }
			   })
			},
			modalPicTap() {
			  this.modalPic = true;
			},
			getPic(pic) {
			  this.modalPic = false;
			  this.form.image = pic.att_dir;
			},
			delImage(){
			  this.form.image = '';
			},
			// 获取默认数据；
			fastMenus(){
				getFastMenus().then(res=>{
					this.dataList = res.data.default_menus;
					let setMenus = res.data.set_menus;
					const aMap = new Set(setMenus.map(item => item.id));
					if(setMenus.length){
						this.quickList = setMenus
						this.dataList.forEach(item => {
							if (aMap.has(item.id)) {
								item.is_show = 1;
							}else{
								item.is_show = 0;
							}
						});
					}else{
						let list = [];
						this.dataList.forEach(item=>{
							if(item.is_show){
								list.push(item)
							}
						})
						this.quickList = list;
					}
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			saveQuick(){
				this.quickModal = false;
				let data = {
					menus:this.quickList
				}
				setFastMenus(data).then(res=>{
					this.$Message.success(res.msg);
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			// 添加
			addMenus(j){
				if(this.quickList.length==6){
					return this.$Message.error('最多可选6项');
				}
				j.is_show = 1
				this.quickList.push(j)
			},
			// 删除
			removeMenus(item,index){
				this.quickList.splice(index, 1)
				this.dataList.forEach(j=>{
					if(j.id == item.id){
						j.is_show = 0;
					}
				})
			},
			cancelQuick(){
				this.quickModal = false;
			},
			setQuick(){
				this.quickModal = true;
			},
			goPage(num,url){
				switch (num) {
					case 1:
					    this.$router.push({ path: this.roterPre + "/order/list?status=1" });
						break;
					case 2:
						this.$router.push({ path: this.roterPre + "/order/refund" });
						break;
					case 3:
						this.$router.push({ path: this.roterPre + "/product/product_list?type=4" });
						break;
					case 4:
						this.$router.push({ path: this.roterPre + "/product/product_list?type=5" });
						break;
					case 5:
						this.$router.push({ path: this.roterPre + "/product/product_list?type=0" });
						break;
					case 6:
						this.$router.push({ path: this.roterPre + "/finance/user_extract/index?status=0" });
						break;
					case 7:
						this.$router.push({ path: this.roterPre + "/community/comment" });
						break;
					case 9:
						this.$router.push({ path: this.roterPre + "/community/content?is_verify=0" });
						break;
					case 11:
						this.$router.push({ path: this.roterPre + "/store/store/apply" });
						break;
					case 12:
						this.$router.push({ path: this.roterPre + "/stock/request?status=1&supply_party_type=hq" });
						break;
					case 10:
						this.$router.push({ path: this.roterPre + url });
						break;
					default:
						this.$router.push({ path: this.roterPre + "/supplier/apply" });
						break
				}
			},
			jnotice(){
				getJnotice().then(res=>{
					this.dataInfo = res.data;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			}
		}
    }
</script>

<style lang="stylus" scoped>
	@media (min-width: 1200px) {
		.ivu-col-span-xl-5 {
		    display: block;
		    flex: 0 0 20%;
		    max-width: 20%;
			padding-left:0px !important;
			padding-right: 0px !important;
		}
	}
	/deep/.ivu-modal-footer{
		border: 0;
	}
	/deep/.ivu-card-body{
		padding: 16px 16px 0 16px !important;
	}
	/deep/.ivu-icon-ios-add{
		font-size: 22px;
	}
	.upload-item{
		.ivu-btn{
			width: 18px !important;
			height: 18px !important;
			top: -8px;
			right: -8px;
		}
	}
	.ivu-mb{
		padding: 0 6px !important;
		margin-bottom: 12px !important;
	}
	.title{
		font-weight: 600;
		font-size: 16px;
		color: #333333;
		text-align: left;
		margin-bottom: 30px;
		&.on{
			margin-bottom: 25px;
		}
	}
	.set{
		margin-bottom: 25px;
		font-size: 14px;
		color: #909399;
		cursor: pointer;
	}
	.right{
		.item{
			margin-bottom: 35px;
			cursor: pointer;
			.pictrue{
				width: 32px;
				height: 32px;
				border-radius: 6px;
				margin: 0 auto;
				img{
					width: 100%;
					height: 100%;
				}
			}
			.name{
				font-size: 13px;
				color: #606266;
				margin-top: 10px;
			}
		}
	}
	.list{
		.item{
			margin: 0 0 40px 15px;
			cursor: pointer;
			.pictrue{
				width: 54px;
				height: 54px;
				margin-right: 14px;
				img{
					width: 100%;
					height: 100%;
				}
			}
			.text{
				font-size: 14px;
				color: #606266;
				text-align: left;
				.num{
					font-size: 30px;
					color: #333333;
					font-family: SemiBold;
					line-height: 1;
					margin-bottom: 3px;
				}
			}
		}
	}
	::-webkit-scrollbar-thumb{
	    -webkit-box-shadow: inset 0 0 6px #fff;
	}
	::-webkit-scrollbar {
	    width: 6px!important; /*对垂直流动条有效*/
	}
	
	::-webkit-scrollbar-track {
	    border-radius: 10px;
	}
	
	::-webkit-scrollbar-thumb {
	    background-color: #bfc1c4;
	}
	.quick{
		max-height: 470px;
		overflow: auto;
		.titles{
			font-size: 14px;
			color: #303133;
			font-weight: 600;
			margin-bottom: 24px;
			.num{
				margin-left: 8px;
				font-size: 13px;
				color: #909399;
				font-weight: 500;
			}
		}
		.list{
			.item{
				width: 78px;
				height: 86px;
				border-radius: 4px;
				border: 1px solid #EEEEEE;
				margin-left: 30px;
				position: relative;
				&:hover{
					.bottom{
						display: flex;
					}
				}
				.bottom{
					position: absolute;
					left: 0;
					bottom: 0;
					width: 100%;
					background-color: #fff;
					height: 20px;
					border-radius: 0 0 4px 4px;
					border-top: 1px solid #EEEEEE;
					display: none;
					
					span{
						font-size: 12px;
						text-align: center;
						width: 50%;
						&~span{
							border-left: 1px solid #eee;
						}
						&:hover{
							color: #2D8CF0;
						}
					}
				}
				.icon{
					position: absolute;
					right: -13px;
					top: -18px;
					font-size: 25px;
					color: #C0C4CC;
					&.icontianjia-01{
						color: #1098FF;
					}
				}
				.pictrue{
					width: 32px;
					height: 32px;
					border-radius: 6px;
					margin: 0 auto;
					img{
						width: 100%;
						height: 100%;
					}
				}
				.name{
					font-size: 13px;
					color: #606266;
					margin-top: 10px;
				}
			}
		}
	}
</style>