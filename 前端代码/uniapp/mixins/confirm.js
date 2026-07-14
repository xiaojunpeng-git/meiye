// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
const CACHE_CITY = {};
import dayjs from '@/plugin/dayjs/dayjs.min.js';
export default {
	data() {
		return {
			isShow:false,
			timerangesIndex:0,
			timerangesXindex:0,
			timeranges:[],
			addressInfoArea: [],
			cityShow: 2,
			display: false,
			confirm:[],
			confirmDefault:[]
		};
	},
	onShow(){
		  let current = (dayjs(new Date(Number(new Date().getTime()))).format('HH:mm')).split(':');
		  let currentArray = current;
		  currentArray.push(0);
		  let arrayNew = [];
		  [...currentArray,...current].forEach(item=>{
		  		 arrayNew.push(Number(item))
		  })
		  this.timeranges = arrayNew;
	},
	methods: {
		// 对象转数组
		objToArr(data) {
			let obj = Object.keys(data);
			let m = obj.map(key => data[key]);
			return m;
		},
		confrim(e){
		  this.isShow = false;
		  this.confirm[this.timerangesXindex][this.timerangesIndex].value = e.time;
		  let arrayNew = [];
		  e.val.forEach(item=>{
		    arrayNew.push(Number(item))
		  })
		  this.timeranges = arrayNew;
		},
		cancels(){
			this.isShow = false;
		},
		// 单选
		radioChange(e, index, item, xindex){
			this.confirm[xindex][index].value = item.wordsConfig.list[e.detail.value].val
		},
		// 多选
		checkboxChange(e, index, item, xindex){
			let obj = e.detail.value;
			  let val = '';
			  item.wordsConfig.list.forEach((j,jindex)=>{
				  obj.forEach(x=>{
					  if(jindex == x){
					  	 val = val +(val?',':'') + j.val;
					  }
				  })
			  })
			this.confirm[xindex][index].value = val
		},
		bindDateChange: function(e, index, xindex) {
			this.confirm[xindex][index].value = e.target.value
		},
		bindTimeChange: function(e, index, xindex) {
			this.confirm[xindex][index].value = e.target.value
		},
		bindSelectChange: function(e, index, item, xindex) {
			this.confirm[xindex][index].value = item.wordsConfig.list[e.detail.value].val
		},
		getTimeranges(index,xindex){
			this.isShow = true
			this.timerangesXindex = xindex;
			this.timerangesIndex = index
		},
		OnAreaAddress(address){
		  let addr = '';
		  if (address.length==4){
			  addr = address[0].label+'/'+address[1].label+'/'+address[2].label+'/'+address[3].label;
		  }else if (address.length==3){
			 addr = address[0].label+'/'+address[1].label+'/'+address[2].label;
		  }else if(address.length==2){
			 addr = address[0].label+'/'+address[1].label;
		  }else{
			 addr = address[0].label;
		  }
		  this.confirm[this.timerangesXindex][this.timerangesIndex].value = addr;
		  CACHE_CITY[this.timerangesIndex] = address;
		},
		changeRegion(index,xindex){
		   if(!this.confirm[xindex][index].value){
			 this.addressInfoArea = [];
		   }
		   this.timerangesIndex = index;
		   this.timerangesXindex = xindex;
		   this.cityShow = Number(this.confirm[xindex][index].valConfig.tabVal) + 1;
		   this.display = true;
		   if(CACHE_CITY[index] && this.confirm[xindex][index].value){
			 this.addressInfoArea = CACHE_CITY[index];
		   }
		},
		// 关闭地址弹窗；
		changeAddressClose: function() {
		   this.display = false;
		},
		/**
		 * 删除图片
		 *
		 */
		DelPic: function(index, indexs, xindex) {
			let that = this,
				pic = this.confirm[xindex][index].value;
			that.confirm[xindex][index].value.splice(indexs, 1);
			that.$set(that.confirm[xindex][index], 'value', that.confirm[xindex][index].value);
		},
		
		/**
		 * 上传文件
		 *
		 */
		uploadpic: function(index,xindex) {
			let that = this;
			this.$util.uploadImageOne('upload/image', function(res) {
				that.confirm[xindex][index].value.push(res.data.url);
			});
		},
		// 获取表单信息处理
		confirmInfo(data,cartNum){
			let confirm = this.objToArr(data);
			confirm.forEach((item, index, arr)=>{
				CACHE_CITY[index] = ''; //清空省市区
				if(item.name == 'texts'){
				  if(item.defaultValConfig.value){
					  item.value = item.defaultValConfig.value
				  }else{
					  item.value = ''
				  }
				}else if(item.name == 'radios'){
					item.value = item.wordsConfig.list[0].val
				}else if(item.name == 'uploadPicture'){
				  item.value = [];
				}else if(item.name == 'dateranges'){
					if(item.valConfig.tabVal==0){
					  if(item.valConfig.tabData==0){
						 let obj = dayjs(new Date(Number(new Date().getTime()))).format('YYYY-MM-DD');
						 item.value = [obj,obj]
					  }else{
						  let data1 = dayjs(new Date(Number(new Date(item.valConfig.specifyDate[0]).getTime()))).format('YYYY-MM-DD');
						  let data2 = dayjs(new Date(Number(new Date(item.valConfig.specifyDate[1]).getTime()))).format('YYYY-MM-DD');
					      item.value = [data1,data2];
					  }
					}else{
					  item.value = [];
					}
				}else{
					if(['times','dates','timeranges'].indexOf(item.name) != -1){
					  if(item.valConfig.tabVal==0){
						  if(item.valConfig.tabData==0){
							if(item.name == 'times'){
							  item.value = dayjs(new Date(Number(new Date().getTime()))).format('HH:mm');
							}else if(item.name == 'dates'){
							  item.value = dayjs(new Date(Number(new Date().getTime()))).format('YYYY-MM-DD');
							}else{
							  let current = dayjs(new Date(Number(new Date().getTime()))).format('HH:mm');
							  item.value = current+' - '+current;
							}
						  }else{
							if(item.name == 'times' || item.name == 'dates'){
							  item.value = item.valConfig.specifyDate;
							}else{
							  item.value = item.valConfig.specifyDate[0]+' - '+item.valConfig.specifyDate[1];
							}
						  }
					  }else{
						item.value = '';
					  }
					}else{
					  item.value = '';
					}
				}
			})
			function sortNumber(a, b) {
				return a.timestamp - b.timestamp;
			}
			confirm.sort(sortNumber);
			this.confirmDefault = JSON.parse(JSON.stringify(confirm));
			let confirmNum = [];
			for (let i = 0; i < cartNum; i++) {
			  confirmNum.push(JSON.parse(JSON.stringify(confirm)));
			}
			this.$set(this, 'confirm', confirmNum);
		},
		// 提交表单时验证
		verify(){
			for (var i = 0; i < this.confirm.length; i++) {
				for(var j = 0; j < this.confirm[i].length; j++){
					let data = this.confirm[i][j];
					if (['radios'].indexOf(data.name) == -1 && (data.titleShow.val || (['uploadPicture','dateranges'].indexOf(data.name) == -1 && data.value && data.value.trim()))) {
					  if ((data.name === 'texts' && data.valConfig.tabVal == 0) || ['dates','times','selects','citys','checkboxs'].indexOf(data.name) != -1) {
					    if (!data.value || (data.value && !data.value.trim())) {
					      return this.$util.Tips({
					        title: `请填写${data.titleConfig.value}`
					      });
					    }
					  }
					  if(data.name === 'timeranges'){
					  	if(!data.value){
					  		return this.$util.Tips({
					  		  title: `请选择${data.titleConfig.value}`
					  		});
					  	}
					  }
					  if (data.name === 'dateranges') {
					    if (!data.value.length) {
					      return this.$util.Tips({
					        title: `请选择${data.titleConfig.value}`
					      });
					    }
					  }
					  if (data.name === 'texts' && data.valConfig.tabVal == 4) {
					    if (data.value <= 0) {
					      return this.$util.Tips({
					        title: `请填写大于0的${data.titleConfig.value}`
					      });
					    }
					  }
					  if (data.name === 'texts' && data.valConfig.tabVal == 3) {
					    if (!/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/.test(data.value)) {
					      return this.$util.Tips({
					        title: `请填写正确的${data.titleConfig.value}`
					      });
					    }
					  }
					  if (data.name === 'texts' && data.valConfig.tabVal == 1) {
					    if (!/^1(3|4|5|7|8|9|6)\d{9}$/i.test(data.value)) {
					      return this.$util.Tips({
					        title: `请填写正确的${data.titleConfig.value}`
					      });
					    }
					  }
					
					  if (data.name === 'texts' && data.valConfig.tabVal == 2) {
					    if (!
					      /^[1-9]\d{7}((0\d)|(1[0-2]))(([0|1|2]\d)|3[0-1])\d{3}$|^[1-9]\d{5}[1-9]\d{3}((0\d)|(1[0-2]))(([0|1|2]\d)|3[0-1])\d{3}([0-9]|X)$/i
					      .test(data.value)) {
					      return this.$util.Tips({
					        title: `请填写正确的${data.titleConfig.value}`
					      });
					    }
					  }
					
					  if (data.name === 'uploadPicture') {
					    if (!data.value.length) {
					      return this.$util.Tips({
					        title: `请上传${data.titleConfig.value}`
					      });
					    }
					  }
					}
				}
			}
			return true
		}
	}
};
