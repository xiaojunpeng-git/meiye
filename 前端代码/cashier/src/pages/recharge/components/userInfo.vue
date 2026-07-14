<template>
  <div class="user-info">
    <div class="section">
      <div class="section-head">用户信息</div>
      <div class="section-body">
        <div class="item">
          <div class="name">用户ID：</div>
          <div class="value bg-w111-F9F9F9">{{ userInfo.uid }}</div>
        </div>
        <div class="item">
          <div class="name">真实姓名：</div>
		  <Input v-model="userInfo.real_name" placeholder="请输入真实姓名" class="value p-0"></Input>
        </div>
        <div class="item">
          <div class="name">昵称：</div>
          <Input v-model="userInfo.nickname" placeholder="请输入昵称" class="value p-0"></Input>
        </div>
        <div class="item">
          <div class="name">手机号码：</div>
		   <Input v-model="userInfo.phone" placeholder="请输入手机号码" class="value p-0"></Input>
        </div>
        <div class="item">
          <div class="name">生日：</div>
		  <DatePicker :transfer='true' v-model="userInfo.birthday" type="date" placeholder="选择生日" @on-change='dataTap' class="value p-0"/>
        </div>
        <div class="item">
          <div class="name">性别：</div>
		  <Select :transfer='true' v-model="userInfo.sex" placeholder="请选择" class="value p-0" clearable>
		  	<Option :value ="item.value" v-for="(item,index) in sexList">{{item.label}}</Option>
		  </Select>
        </div>
        <div class="item">
          <div class="name">身份证号：</div>
		  <Input v-model="userInfo.card_id" placeholder="请输入身份证号" class="value p-0"></Input>
        </div>
        <div class="item">
          <div class="name">用户地址：</div>
		  <Input v-model="userInfo.addres" placeholder="请输入用户地址" class="value p-0"></Input>
        </div>
      </div>
    </div>
    <div class="section">
      <div class="section-head">用户概况</div>
      <div class="section-body">
        <div class="item">
          <div class="name">推广资格：</div>
          <div class="value bg-w111-F9F9F9">{{ userInfo.spread_open ? '启用' : '禁用' }}</div>
        </div>
        <div class="item">
          <div class="name">用户状态：</div>
          <div class="value bg-w111-F9F9F9">{{ userInfo.status ? '开启' : '锁定' }}</div>
        </div>
        <div class="item">
          <div class="name">用户等级：</div>
          <div class="value bg-w111-F9F9F9">{{ userInfo.vip_name || '-' }}</div>
        </div>
        <div class="item">
          <div class="name">用户标签：</div>
          <div class="value bg-w111-F9F9F9">{{ userInfo.label_list || '-' }}</div>
        </div>
        <div class="item">
          <div class="name">用户分组：</div>
          <div class="value bg-w111-F9F9F9">{{ userInfo.group ? userInfo.group.group_name : '无' }}</div>
        </div>
        <div class="item">
          <div class="name">推广人：</div>
          <div class="value bg-w111-F9F9F9">{{ userInfo.spread_uid_nickname || '无' }}</div>
        </div>
        <div class="item">
          <div class="name">注册时间：</div>
          <div class="value bg-w111-F9F9F9">
            {{ $moment(userInfo.add_time * 1000).format('YYYY-MM-DD H:mm:ss') }}
          </div>
        </div>
        <div class="item">
          <div class="name">登录时间：</div>
          <div class="value bg-w111-F9F9F9">
            {{
              $moment(userInfo.last_time * 1000).format('YYYY-MM-DD H:mm:ss')
            }}
          </div>
        </div>
      </div>
    </div>
    <div class="section">
      <div class="section-head">用户备注</div>
      <div class="section-body">
        <div class="item">
          <div class="name">备注：</div>
		  <Input v-model="userInfo.mark" placeholder="请输入备注信息" class="value p-0"></Input>
        </div>
      </div>
    </div>
	<div class="w-120 h-44 rd-30px bg-w111-1890FF text-wlll-FFFFFF fs-18 acea-row row-center-wrapper auto pointer" @click="userUpdate">保存</div>
  </div>
</template>

<script>
import {
  postUserUpdate
} from '@api/user';
export default {
  name: 'userInfo',
  props: {
    userInfo: {
      type: Object,
      default() {
        return {};
      },
    },
  },
  data() {
	return {
		sexList:[
			{value:1,label:'男'},
			{value:2,label:'女'},
			{value:3,label:'保密'}
		]
	}
  },
  mounted() {},
  methods:{
	userUpdate(){
		let info = this.userInfo;
		let data = {
			real_name:info.real_name,
			nickname:info.nickname,
			phone:info.phone,
			birthday:info.birthday,
			sex:info.sex,
			card_id:info.card_id,
			addres:info.addres,
			mark:info.mark
		}
		if(info.phone && !/^1(3|4|5|7|8|9|6)\d{9}$/.test(info.phone)){
			return this.$Message.error('请输入正确的手机号');
		}
		postUserUpdate(info.uid,data).then(res=>{
			this.$Message.success(res.msg);
		}).catch(err=>{
			this.$Message.error(err.msg);
		})
	},
	dataTap(e){
	   this.userInfo.birthday = e;
	}
  }
};
</script>

<style lang="stylus" scoped>
/deep/.ivu-input{
	border: 0;
	padding: 11px 14px;
	height: 45px;
	border-radius: 6px;
}
/deep/.ivu-input-suffix{
	padding-top: 5px;
}
/deep/.ivu-select-single .ivu-select-selection{
	height: 45px;
	border: 0;
	border-radius: 6px;
	padding-left: 5px;
}
/deep/.ivu-select-single .ivu-select-selection .ivu-select-placeholder,
/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value{
	height: 45px;
	line-height: 45px;
}
.user-info {
  .section {
    padding: 30px 0;

    +.section {
      border-top: 1px dashed #EEEEEE;
    }
  }

  .section-head {
    padding-left: 10px;
    border-left: 3px solid #1890FF;
    font-weight: 500;
    font-size: 14px;
    line-height: 15px;
    color: #303133;
  }

  .section-body {
    display: flex;
    flex-wrap: wrap;
    margin-top: 28px;
    font-size: 14px;

    .item {
      flex: 0 0 calc(((100% - 48px) / 3));
      display: flex;
      align-items: center;
      margin: 0 0 24px 0;

      &:nth-child(3n+3) {
        margin: 0 0 24px 0;
      }
    }

    .name {
      width: 100px;
      text-align: right;
      color: rgba(102,102,102,0.85);
    }

    .value {
      flex: 1;
      padding: 11px 14px;
      border: 1px solid #DDDDDD;
      border-radius: 6px;
    }
  }
}
</style>
