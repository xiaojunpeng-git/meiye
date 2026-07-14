<template>
	<div>
		<Form ref="formItem" class="mt20" :model="formItem" @submit.native.prevent>
			<div v-for="(item, index) in dataInfo">
				<div class="tips" v-if="item.name == closeName[0]">未启用的进店规则</div>
				<Card :bordered="false" dis-hover class="ivu-mt" :class='!item.is_use?"on":""' v-if="item.name == 'user_belong_store'">
					<div :ref="'contentBox'+index" class="collapsible acea-row overflow" :style="{height: item.collapse ? `${item.contentHeight}px` : '23px'}">
						<div class="iconfont fs-12 w-22 pt-3 cup" :class="item.collapse?'iconshangyi':'iconxiayi'" @click="collapsible(item,index)"></div>
						<div class="flex-1">
							<div class="acea-row row-between-wrapper">
								<div class="title acea-row row-middle">
									<div class="num acea-row row-center-wrapper" v-if="item.is_use">{{index+1}}</div>
									<div class="name">客户归属门店</div>
								</div>
								<div class="acea-row row-middle">
									<div class="pointer acea-row row-middle" v-if="item.is_use" @click="move(item)"><span class="iconfont" :class='index?"iconshangyi-01":"iconxiayi-01"'></span>{{index?'上移':'下移'}}</div>
									<div class="pointer acea-row row-middle" v-if="index" @click="switchs(item)"><span class="iconfont" :class='item.is_use?"iconjinyong-01":"iconqiyong-01"'></span>{{item.is_use?'禁用':'开启'}}</div>
								</div>
							</div>
							<div class="info" v-if="index == 1">
								<Checkbox v-model="item.is_change_store" size='small'>允许切换门店</Checkbox>
							</div>
							<div class="info">有归属门店的客户{{index == 1?'允许':'不允许'}}切换门店</div>
						</div>
					</div>
				</Card>
				<Card :bordered="false" dis-hover class="ivu-mt" v-if="item.name == 'user_param_store'">
					<div :ref="'contentBox'+index" class="collapsible acea-row overflow" :style="{height: item.collapse ? `${item.contentHeight}px` : '23px'}">
						<div class="iconfont fs-12 w-22 pt-3 cup" :class="item.collapse?'iconshangyi':'iconxiayi'" @click="collapsible(item,index)"></div>
						<div class="flex-1">
							<div class="acea-row row-between-wrapper">
								<div class="title acea-row row-middle">
									<div class="num acea-row row-center-wrapper">{{index+1}}</div>
									<div class="name">优先进带参门店</div>
								</div>
								<div class="pointer acea-row row-middle" v-if="!(!index && dataInfo[1].name != 'user_belong_store')" @click="move(item)"><span class="iconfont" :class='index?"iconshangyi-01":"iconxiayi-01"'></span>{{index?'上移':'下移'}}</div>
							</div>
							<div class="info">商家从门店后台获取的链接或二维码，带有门店参数。若直接用来投放，则买家可通过扫码或点击链接直接进入该门店。</div>
							<div class="info">
								<Checkbox v-model="item.is_cache" size='small' @on-change='openTime(item,$event)'>缓存店员分享门店</Checkbox>
							</div>
							<div class="info acea-row row-middle" v-if="item.is_cache">
								<div>缓存时效性：</div>
								<InputNumber v-model="item.cache_time" :min='0' :precision="0" controls-outside />
								<div class="ml7">小时</div>
							</div>
						</div>
					</div>
				</Card>
				<Card :bordered="false" dis-hover class="ivu-mt" :class='!item.is_use?"on":""' v-if="item.name == 'user_visit_store'">
					<div :ref="'contentBox'+index" class="collapsible acea-row overflow" :style="{height: item.collapse ? `${item.contentHeight}px` : '23px'}">
						<div class="iconfont fs-12 w-22 pt-3 cup" :class="item.collapse?'iconshangyi':'iconxiayi'" @click="collapsible(item,index)"></div>
						<div class="flex-1">
							<div class="acea-row row-between-wrapper">
								<div class="title acea-row row-middle">
									<div class="num acea-row row-center-wrapper" v-if="item.is_use">{{index+1}}</div>
									<div class="name">客户优先进最近访问门店(支持门店隔离)</div>
								</div>
								<div class="pointer acea-row row-middle" @click="switchs(item)"><span class="iconfont" :class='item.is_use?"iconjinyong-01":"iconqiyong-01"'></span>{{item.is_use?'禁用':'开启'}}</div>
							</div>
							<div class="info">最近访问门店的历史缓存为90天</div>
							<div class="info acea-row row-middle">
								<div>门店隔离：</div>
								<Checkbox v-model="item.is_alone" :disabled='!item.is_use' size='small'>仅当该门店为“隔离门店”时才会进入</Checkbox>
							</div>
						</div>
					</div>
				</Card>
				<Card :bordered="false" dis-hover class="ivu-mt" :class='!item.is_use?"on":""' v-if="item.name == 'user_locate_store'">
					<div :ref="'contentBox'+index" class="collapsible acea-row overflow" :style="{height: item.collapse ? `${item.contentHeight}px` : '23px'}">
						<div class="iconfont fs-12 w-22 pt-3 cup" :class="item.collapse?'iconshangyi':'iconxiayi'" @click="collapsible(item,index)"></div>
						<div class="flex-1">
							<div class="acea-row row-between-wrapper">
								<div class="title acea-row row-middle">
									<div class="num acea-row row-center-wrapper" v-if="item.is_use">{{index+1}}</div>
									<div class="name">用户定位推荐</div>
								</div>
								<div class="pointer acea-row row-middle" @click="switchs(item)"><span class="iconfont" :class='item.is_use?"iconjinyong-01":"iconqiyong-01"'></span>{{item.is_use?'禁用':'开启'}}</div>
							</div>
							<div class="info">系统会根据客户的当前定位，推荐服务范围内最近的门店。</div>
							<div class="info">
								<Checkbox v-model="item.is_region_alone" :disabled='!item.is_use' size='small'>开启区域隔离推荐</Checkbox>
							</div>
						</div>
					</div>
				</Card>
				<Card :bordered="false" dis-hover class="ivu-mt" v-if="item.name == 'default_store'">
					<div :ref="'contentBox'+index" class="collapsible acea-row overflow" :style="{height: item.collapse ? `${item.contentHeight}px` : '23px'}">
						<div class="iconfont fs-12 w-22 pt-3 cup" :class="item.collapse?'iconshangyi':'iconxiayi'" @click="collapsible(item,index)"></div>
						<div class="flex-1">
							<div class="acea-row row-between-wrapper">
								<div class="title acea-row row-middle">
									<div class="num acea-row row-center-wrapper">{{index+1}}</div>
									<div class="name">无门店推荐结果，直接进入指定页面</div>
								</div>
							</div>
							<div class="info acea-row row-middle">
								<div>指定页面：</div>
								<RadioGroup v-model="item.default_type">
									<Radio :label="1">指定门店</Radio>
									<Radio :label="2">指定页面</Radio>
								</RadioGroup>
							</div>
							<div class="info acea-row row-middle" v-if="item.default_type == 1">
								<div>选择门店：</div>
								<Select
								    v-model="item.store_id"
								    clearable
								    filterable
									class="w-213"
								    placeholder="请选择门店"
								>
								  <Option
								      v-for="(items, index) in storeList"
								      :value="items.id"
								      :key="items.id"
								  >{{ items.name }}</Option
								  >
								</Select>
							</div>
							<div class="info acea-row row-middle" v-else>
								<div>选择页面：</div>
								<div class="w-213" @click="getLink">
									<Input v-model="item.url" readonly icon="ios-arrow-forward" placeholder="请选择页面" />
								</div>
							</div>
						</div>
					</div>
				</Card>
				<Card :bordered="false" dis-hover class="ivu-mt" :class='!item.is_use?"on":""' v-if="item.name == 'region_recommend_store'">
					<div :ref="'contentBox'+index" class="collapsible acea-row overflow" :style="{height: item.collapse ? `${item.contentHeight}px` : '23px'}">
						<div class="iconfont fs-12 w-22 pt-3 cup" :class="item.collapse?'iconshangyi':'iconxiayi'" @click="collapsible(item,index)"></div>
						<div class="flex-1">
							<div class="acea-row row-between-wrapper">
								<div class="title acea-row row-middle">
									<div class="num acea-row row-center-wrapper" v-if="item.is_use">{{index+1}}</div>
									<div class="name">地区定向推荐</div>
								</div>
								<div class="pointer acea-row row-middle" @click="switchs(item)"><span class="iconfont" :class='item.is_use?"iconjinyong-01":"iconqiyong-01"'></span>{{item.is_use?'禁用':'开启'}}</div>
							</div>
							<div class="info">当客户定位到指定地区时，系统将推荐客户进入指定门店。</div>
							<div class="info acea-row row-middle">
								<div>选择地区：</div>
								<LazyCascader
								    v-model="item.region_id"
								    class="w-213"
								    :props="props"
								    collapse-tags
								    clearable
								    :filterable="false"
								    size="mini"
									:disabled='!item.is_use'
								/>
							</div>
							<div class="info acea-row row-middle">
								<div>推荐门店：</div>
								<Select
								    v-model="item.store_id"
								    clearable
								    filterable
									class="w-213"
								    placeholder="请选择门店"
									:disabled='!item.is_use'
								>
								  <Option
								      v-for="(items, index) in storeList"
								      :value="items.id"
								      :key="items.id"
								  >{{ items.name }}</Option
								  >
								</Select>
							</div>
						</div>
					</div>
				</Card>
			</div>
		</Form>
		<Card :bordered="false" dis-hover class="fixed-card" :style="{left: `${!menuCollapse?'236px':isMobile?'0':'60px'}`}">
		  <Form>
		    <FormItem>
		      <Button
		          type="primary"
		          class="ml10"
		          @click="handleSubmit('formItem')"
		      >保存</Button>
		    </FormItem>
		  </Form>
		</Card>
		<linkaddress ref="linkaddres" :fromType='"diyPage"' @linkUrl="linkUrl"></linkaddress>
	</div>
</template>

<script>
	import { staffListInfo } from '@/api/store';
	import { cityData, getNewFormBuildRuleApi, saveBasicsApi } from '@/api/setting';
	import linkaddress from '@/components/linkaddress';
	import LazyCascader from '@/components/lazyCascader';
	import { mapState,mapMutations } from "vuex";
	const cacheAddress = {};
	export default{
		name: 'rules',
		components: {
		    linkaddress,
			LazyCascader
		},
		data () {
			return {
			   dataInfo:[
				   {
					  name:'user_belong_store',
					  is_change_store:1,
					  is_use:1,
					  collapse:false,
					  contentHeight:23
				   },
				   {
					  name:'user_param_store',
					  is_use:1,
					  is_cache:0,
					  cache_time:0,
					  collapse:false,
					  contentHeight:23
				   },
				   {
					  name:'user_visit_store',
					  is_use:1,
					  is_alone:0,
					  collapse:false,
					  contentHeight:23
				   },
				   {
				   	  name:'user_locate_store',
					  is_use:1,
					  is_region_alone:0,
					  collapse:false,
					  contentHeight:23
				   },
				   {
				   	  name:'default_store',
					  is_use:1,
					  default_type:1,
					  store_id:0,
					  url:'',
					  collapse:false,
					  contentHeight:23
				   },
				   {
					  name:'region_recommend_store',
					  is_use:0,
					  region_id:[],
					  store_id:0,
					  collapse:false,
					  contentHeight:23
				   },
			   ],
			   formItem:{},
			   storeList:[],
			   props: {
			       children: 'children',
			       label: 'label',
			       value: 'value',
			       multiple: true,
			       lazy: true,
			       lazyLoad: this.lazyLoad,
			       checkStrictly: true
			   },
			   closeName:['region_recommend_store']
			}
		},
		computed: {
			...mapState("admin/layout", ["isMobile","menuCollapse"]),
			labelWidth () {
				return this.isMobile ? undefined : 120;
			},
			labelPosition () {
				return this.isMobile ? 'top' : 'right';
			}
		},
		created(){
			this.allStore();
			this.getInfo();
		},
		methods:{
			openTime(item,e){				if(e){					item.contentHeight = 140;				}else{					item.contentHeight = 92;				}			},
			collapsible(item,index){
				let refName = `contentBox${index}`;
				this.$nextTick(() => {
					item.contentHeight = this.$refs[refName].length ? this.$refs[refName][0].scrollHeight : 23;
				});
				item.collapse = !item.collapse
				this.$set(this.dataInfo, index, this.dataInfo[index]);
			},
			getInfo(){
				getNewFormBuildRuleApi('user_entry_rules').then(res=>{
					this.dataInfo = res.data.user_entry_rules;
					let data = []
					this.dataInfo.forEach(item=>{
						item.collapse = false;
						if(!item.is_use){
							data.push(item.name);
						}
					})
					this.closeName = data;
				}).catch(err=>{
					this.$message.error(res.msg)
				})
			},
			handleSubmit(name){
				this.$refs[name].validate((valid) => {
				  if (valid) {
					  let flag = 1
					  this.dataInfo.forEach(item=>{
						  if(item.name == 'user_param_store'){
							  if(!item.cache_time){
								  item.cache_time = 0;
							  }
						  } else if(item.name == 'region_recommend_store'){
							  if(item.is_use && item.region_id.length && !item.store_id){
								  flag = 0;
								  return this.$Message.error('推荐门店不能为空');
							  }
						  }else if(item.name == 'default_store'){
							  if(item.default_type == 1 && !item.store_id){
								  flag = 0;
								  return this.$Message.error('指定门店不能为空');
							  }else if(item.default_type == 2 && !item.url){
								  flag = 0;
								  return this.$Message.error('指定指定不能为空');
							  }
						  }
					  })
					  if(!flag){
						  return
					  }
					  saveBasicsApi({user_entry_rules:this.dataInfo}).then(res=>{
						  this.$Message.success(res.msg);
					  }).catch(err=>{
						  this.$Message.error(res.msg);
					  })
				  }
				});
			},
			lazyLoad(node, resolve) {
			    if (cacheAddress[node]) {
			        cacheAddress[node]().then(res => {
			            resolve([...res.data])
			        })
			    } else {
			        const p = cityData({pid:node,level:2})
			        cacheAddress[node] = () => p
			        p.then(res => {
						res.data.forEach(item => {
						    item.leaf = !item.hasOwnProperty('children')
						})
						cacheAddress[node] = () => new Promise((resolve1) => {
						    setTimeout(() => resolve1(res), 300)
						})
						resolve(res.data)
			        }).catch(res => {
			            this.$message.error(res.message)
			        })
			    }
			},
			linkUrl(e){
				this.dataInfo.forEach(item=>{
					if(item.name == 'default_store'){
						item.url = e
					}
				})
			},
			getLink (){
			    let obj = {
					id: 7,
					pid: 1,
					type: "special"
				}
				this.$refs.linkaddres.handleCheckChange('',obj)
				this.$refs.linkaddres.modals = true
			},
			allStore(){
				staffListInfo().then(res=>{
				   this.storeList = res.data;
				}).catch(err=>{
				   this.$Message.error(err.msg);
				})
			},
			switchs(item){
				let dataInfo = this.dataInfo;
				let index = dataInfo.indexOf(item);
				let defaultIndex = 0;
				dataInfo.forEach((item,index)=>{
					if(item.name == 'default_store'){
						defaultIndex = index
					}
				})
				if(item.is_use){
					item.is_use = 0
					this.closeName.push(item.name);
					dataInfo.push(dataInfo.splice(index, 1)[0])
				}else{
					item.is_use = 1
					let I1 = this.closeName.indexOf(item.name);
					this.closeName.splice(I1,1);
					let I2 = dataInfo.indexOf(item);
					if(item.name == 'user_belong_store'){
						dataInfo.splice(1, 0, dataInfo.splice(I2, 1)[0]);
					}else if(item.name == 'user_locate_store'){
						dataInfo.splice(defaultIndex, 0, dataInfo.splice(I2, 1)[0]);
					}else if(item.name == 'region_recommend_store'){
						if(dataInfo[defaultIndex-1].name == 'user_locate_store'){
							dataInfo.splice(defaultIndex-1, 0, dataInfo.splice(I2, 1)[0]);
						}else{
							dataInfo.splice(defaultIndex, 0, dataInfo.splice(I2, 1)[0]);
						}
					}else{
						if(dataInfo[1].name == 'user_belong_store' || dataInfo[0].name == 'user_belong_store'){
							dataInfo.splice(2, 0, dataInfo.splice(I2, 1)[0]);
						}else{
							dataInfo.splice(1, 0, dataInfo.splice(I2, 1)[0]);
						}
					}
				}
			},
			move(item){
				let dataInfo = this.dataInfo;
				let index = dataInfo.indexOf(item);
				if(index == 0){
					dataInfo.splice(index + 1, 0, dataInfo.splice(index, 1)[0]);
				}else{
					dataInfo.splice(index - 1, 0, dataInfo.splice(index, 1)[0]);
				}
				dataInfo.forEach((j,i)=>{
					if(j.name == 'user_belong_store'){
						if(!i){
							j.contentHeight = 58;
						}else{
							j.contentHeight = 92;
						}
					}
				})
				this.dataInfo = dataInfo
			}
		}
	}
</script>

<style scoped lang="less">
	.collapsible{
		transition:all 0.2s;
	}
	/deep/.lazy-cascader .lazy-cascader-input-disabled{
		background-color: #f3f3f3;
	}
	/deep/.lazy-cascader .lazy-cascader-input{
		padding:0 5px;
		line-height: 0;
		min-height: 32px;
	}
	/deep/.lazy-cascader .lazy-cascader-input > .lazy-cascader-placeholder{
		color: #ccc;
	}
	/deep/.lazy-cascader .lazy-cascader-input > .lazy-cascader-clear{
		line-height: 32px;
	}
	/deep/.ivu-checkbox{
	    margin-right: 4px;
	}
	/deep/.ivu-checkbox-group-item{
		font-size: 12px;
	}
	/deep/.ivu-checkbox-small{
		font-size: 12px;
	}
	.ivu-mt.on{
		.title{
			.name{
				color: #909399;
			}
		}
		.info{
			color: #909399;
		}
	}
	.tips{
		font-size: 14px;
		color: #303133;
		margin-top: 30px;
	}
	.title{
		.num{
			width: 20px;
			height: 20px;
			background: #E7F4FF;
			font-size: 14px;
			color: #1098FF;
			border-radius: 50%;
			font-weight: 700;
			margin-right: 8px;
		}
		.name{
			font-size: 14px;
			color: #303133;
			font-weight: 700;
		}
	}
	.info{
		font-size: 12px;
		color: #303133;
		margin-top: 16px;
	}
	.pointer{
		font-size: 13px;
		color: #1098FF;
		margin-left: 13px;
		.iconfont{
			margin-right: 5px;
		}
	}
	.fixed-card {
	    position: fixed;
	    right: 0;
	    bottom: 0;
	    left: 200px;
	    z-index: 45;
	    box-shadow: 0 -1px 2px rgb(240, 240, 240);
	
	    /deep/ .ivu-card-body {
	      padding: 15px 16px 14px;
	    }
	
	    .ivu-form-item {
	      margin-bottom: 0 !important;
	    }
	
	    /deep/ .ivu-form-item-content {
	      margin-right: 124px;
	      text-align: center;
	    }
	
	    .ivu-btn {
	      height: 36px;
	      padding: 0 20px;
	    }
	}
</style>