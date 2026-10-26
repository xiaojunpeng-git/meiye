<template>
	<div>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Row :gutter="24">
				<Col span="18">
					<Form
					  ref="formValidate"
					  :label-width="labelWidth"
					  :label-position="labelPosition"
					  :model="formValidate"
					  inline
					>
					  <FormItem label="时间选择：">
					    <DatePicker
					      :editable="false"
					      clearable
					      @on-change="onchangeTime"
					      :value="timeVal"
					      format="yyyy/MM/dd"
					      type="daterange"
					      placement="bottom-start"
					      placeholder="自定义时间"
					       class="input-add"
					      :options="options"
					    ></DatePicker>
					  </FormItem>
					  <FormItem label="选择门店：">
					    <Select
						  clearable
					      v-model="formValidate.store_id"
					      @on-change="agentSearchs"
					       class="input-add"
					    >
					      <Option
					        v-for="item in storeList"
					        :value="item.id"
					        :key="item.id"
					        >{{ item.name }}</Option
					      >
					    </Select>
					  </FormItem>
					</Form>
				</Col>
				<!-- <Col span="6">
					<div class="flex items-end justify-end h-full pb-20">
						<Button type="primary" @click="agentSearchs">查询</Button>
						<Button @click="reset" class="ml10">重置</Button>
					</div>
				</Col> -->
			</Row>
		</Card>
		<Row :gutter="24" class="pl-6 pr-6 mt-12">
		    <Col v-bind="grid" class="ivu-mb" v-for="(item, index) in headerData" :key="index">
		        <Card :bordered="false" dis-hover :padding="20">
		            <p class="title">
		                <span v-text="item.title"></span>
		            </p>
		            <div class="mt-20 text-333">
		                <Numeral :value="item.number" v-font="32" class="SemiBold" />
		                <Divider style="margin: 8px 0" />
		                <div class="fs-14 text-wlll-606266">
		                    <Row>
		                        <Col span="12">同比增长率</Col>
		                        <Col span="12" class="ivu-text-right">{{item.growth_rate}}%</Col>
		                    </Row>
		                </div>
		            </div>
		        </Card>
		    </Col>
		</Row>
		<Card :bordered="false" dis-hover :padding="20">
			<div class="fs-16 PingFang fw-500 text-333">销售额趋势图</div>
			<echarts-new
			  :option-data="optionData"
			  :styles="style"
			  height="100%"
			  width="100%"
			  v-if="optionData"
			></echarts-new>
			<Spin size="large" fix v-if="spinShow"></Spin>
		</Card>
		<Row :gutter="24" class="pl-6 pr-6 mt-12">
			<Col :xl="12" :lg="24" :md="24" :sm="24" :xs="24" class="ivu-mb">
				<Card :bordered="false" dis-hover>
					<div class="fs-16 PingFang fw-500 text-333">门店贡献率</div>
					<echarts-new
					  :option-data="optionData2"
					  :styles="style"
					  height="100%"
					  width="100%"
					  v-if="optionData2"
					></echarts-new>
				</Card>
			</Col>
			<Col :xl="12" :lg="24" :md="24" :sm="24" :xs="24" class="ivu-mb">
				<Card :bordered="false" dis-hover class="h-456">
					<div class="fs-16 PingFang fw-500 text-333 mb-10">门店排行榜</div>
					<Table ref="selection" :columns="columns" :data="rankList"
					       no-data-text="暂无数据" highlight-row
					       no-filtered-data-text="暂无筛选结果">
					</Table>
				</Card>
			</Col>
		</Row>
	</div>
</template>
<script>
	import { mapState } from 'vuex';
	import timeOptions from "@/utils/timeOptions";
	import { formatDate } from "@/utils/validate";
	import echartsNew from "@/components/echartsNew/index";
	import { staffListInfo, getRegionHeader, getRegionOrder, getRegionStore } from '@/api/store';
	export default {
		components: {
		  echartsNew,
		},
	    data () {
	        return {
				grid: {
				    xl: 6,
				    lg: 12,
				    md: 12,
				    sm: 12,
				    xs: 24
				},
				columns: [
				    {
				        title: '门店名称',
				        key: 'name',
				        minWidth: 180
				    },
				    {
				        title: '营业额',
				        minWidth: 130,
				        key: 'number',
						sortable: true
				    },
				    {
				        title: '销量',
				        key: 'sales',
				        minWidth: 100,
						sortable: true
				    },
				    {
				        title: '客单价',
				        key: 'unit_price',
				        minWidth: 100,
						sortable: true
				    }
				],
				spinShow: false,
				options: timeOptions,
				formValidate: {
				  store_id: '',
				  data: '',
				},
				timeVal: [],
				storeList: [],
				headerData: [],
				optionData: {},
				optionData2: {},
				style: { height: "400px" },
				rankList: []
			}
	    },
		computed: {
		  ...mapState('admin/layout', [
		  	'isMobile'
		  ]),
		  labelWidth() {
		    return this.isMobile ? undefined : 80;
		  },
		  labelPosition() {
		    return this.isMobile ? "top" : "right";
		  },
		},
		mounted () {
			this.allStore();
			this.regionHeader();
			this.regionOrder();
			this.regionStore();
		},
		methods: {
			allStore(){
				staffListInfo().then(res=>{
					this.storeList = res.data;
				}).catch(err=>{
					this.$Message.error(res.msg);
				})
			},
			regionHeader(){
				getRegionHeader(this.formValidate).then(res=>{
					this.headerData = res.data;
				}).catch(err=>{
					this.$Message.error(res.msg);
				})
			},
			onchangeTime(e) {
			  this.timeVal = e;
			  this.formValidate.data = this.timeVal[0] ? this.timeVal.join("-") : "";
			  this.agentSearchs();
			},
			agentSearchs() {
				this.regionHeader();
				this.regionOrder();
				this.regionStore();
			},
			regionStore(){
				getRegionStore(this.formValidate).then(res=>{
					this.rankList = res.data.ranking;
					let data = res.data.chart;
					this.optionData2 = {
						tooltip: {
						    trigger: 'item',
							formatter: '{a} <br/>{b} : {c} ({d}%)'
						},
						legend: {
							icon: "circle", 
						    top: '5%',
						    left: 'right',
							fontSize: '12',
							data: data.bing_xdata || []
						},
						series: [
						    {
						        name: '门店贡献率',
						        type: 'pie',
						        radius: ['35%', '60%'],
						        avoidLabelOverlap: false,
						        label: {
						            show: true,
									formatter: '{d}%',
						            position: 'outer',
									fontSize: '12',
						        },
						        emphasis: {
						            label: {
						                show: true,
						                fontSize: '15',
						                fontWeight: 'bold'
						            },
						        },
						        labelLine: {
						            show: true
						        },
						       data: data.bing_data || [],
						    }
						]
					}
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			// 统计图
			regionOrder() {
			  this.spinShow = true;
			  getRegionOrder(this.formValidate)
			    .then(async (res) => {
			      let legend = res.data.legend;
			      let xAxis = res.data.xAxis;
			      // let col = ["#3469FF", "#4BCAD5"];
			      // res.data.series.map((item, index) => {
			      //   item.itemStyle = {
			      //     normal: {
			      //       color: col[index],
			      //     },
			      //   };
			      // });
			      this.optionData = {
			        tooltip: {
			          trigger: "axis",
			          axisPointer: {
			            type: "cross",
			            label: {
			              backgroundColor: "#6a7985",
			            },
			          },
			        },
			        legend: {
			          x: "center",
			          data: legend,
			        },
			        grid: {
			          left: "3%",
			          right: "4%",
			          bottom: "3%",
			          containLabel: true,
			        },
			        toolbox: {
			          show: true,
			          right: "1.5%",
			          feature: {
			            saveAsImage: {
							name: '销售额_'+formatDate(new Date(Number(new Date().getTime())), 'yyyyMMddhhmmss')
						},
			          },
			        },
			        xAxis: {
			          type: "category",
			          boundaryGap: true,
			          axisLabel: {
			            interval: 0,
			            rotate: 40,
			            textStyle: {
			              color: "#000000",
			            },
			          },
			          data: xAxis,
			        },
			        yAxis: [
			          {
			            type: "value",
			            name: "金额",
			            axisLine: {
			              show: false,
			            },
			            axisTick: {
			              show: false,
			            },
			            axisLabel: {
			              textStyle: {
			                color: "#7F8B9C",
			              },
			            },
			            splitLine: {
			              show: true,
			              lineStyle: {
			                color: "#F5F7F9",
			              },
			            },
			          },
			          {
			            type: "value",
			            name: "数量",
			            axisLine: {
			              show: false,
			            },
			            axisTick: {
			              show: false,
			            },
			            axisLabel: {
			              textStyle: {
			                color: "#7F8B9C",
			              },
			            },
			            splitLine: {
			              show: true,
			              lineStyle: {
			                color: "#F5F7F9",
			              },
			            },
			          },
			        ],
			        series: res.data.series,
			      };
			      this.spinShow = false;
			    })
			    .catch((err) => {
			      this.$Message.error(err.msg);
			      this.spinShow = false;
			    });
			}
		}
	}
</script>
<style lang="less" scoped>
	/deep/.ivu-table-header thead tr th{
		padding-top: 2px;
		padding-bottom: 2px;
	}
	.ivu-mt /deep/.ivu-card-body{
		padding: 20px 16px 0 16px;
	}
	.input-add {
		width: 250px;
		margin-right: 15px;
	}
	.ivu-form-item {
		margin-bottom: 20px !important;
	}
	/deep/.ivu-divider{
		color: #F0F1F5;
	}
	.ivu-mb{
		padding: 0 6px !important;
		margin-bottom: 12px !important;
	}
	.title{
		font-family: PingFang SC, PingFang SC;
		font-weight: 500;
		font-size: 16px;
		color: #333333;
	}
</style>
