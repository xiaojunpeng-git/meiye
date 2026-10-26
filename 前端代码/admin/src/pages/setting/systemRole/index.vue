<template>
<!-- 角色管理 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <div class="new_card_pd">
        <Form
          ref="formValidate"
          :model="formValidate"
          inline
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
        >
          <FormItem label="状态：" label-for="status">
            <Select
              v-model="formValidate.status"
              placeholder="请选择"
              clearable
              class="input-add"
			  @on-change="userSearchs"
            >
              <Option value="1">显示</Option>
              <Option value="0">隐藏</Option>
            </Select>
          </FormItem>
          <FormItem label="角色昵称：" label-for="role_name">
            <Input
              placeholder="请输入角色昵称"
              v-model="formValidate.role_name"
              class="input-add mr14"
            />
            <Button type="primary" @click="userSearchs()">查询</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Button
        v-auth="['setting-system_role-add']"
        type="primary"
        @click="add('添加')"
        >添加角色</Button
      >
      <Table
        :columns="columns1"
        :data="tableList"
        ref="table"
        class="mt25"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
	    <template slot-scope="{ row, index }" slot="type">
			<div>{{row.type == 0?'后台权限':'移动端权限'}}</div>
	    </template>
        <template slot-scope="{ row, index }" slot="is_shows">
          <i-switch
            v-model="row.status"
            :value="row.status"
            :true-value="1"
            :false-value="0"
            @on-change="onchangeIsShow(row)"
            size="large"
          >
            <span slot="open">显示</span>
            <span slot="close">隐藏</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row, '编辑')">编辑</a>
          <Divider type="vertical" />
          <a @click="del(row, '删除', index)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="formValidate.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="formValidate.limit"
        />
      </div>
    </Card>
    <!-- 新增编辑-->
    <Modal
      v-model="modals"
      @on-cancel="onCancel"
      scrollable
      footer-hide
      closable
      :title="`${modelTit}角色`"
      :mask-closable="false"
      width="600"
    >
      <Form
        ref="formInline"
        :model="formInline"
        :rules="ruleValidate"
        :label-width="100"
        :label-position="labelPosition2"
        @submit.native.prevent
      >
        <FormItem label="角色名称：" label-for="role_name" prop="role_name">
          <Input placeholder="请输入角色昵称" v-model="formInline.role_name" />
        </FormItem>
        <FormItem label="是否开启：" prop="status">
          <RadioGroup v-model="formInline.status">
            <Radio :label="1">开启</Radio>
            <Radio :label="0">关闭</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem label="权限：">
		  <div class="acea-row row-between-wrapper pr-10 fs-14">
			  <Checkbox v-model="allMenus" :indeterminate="indeterminate" @on-change="handleCheckAll"> 全选</Checkbox>
			  <div class="text-wlll-2d8cf0 cup" @click="changeMenus">展开/折叠</div>
		  </div>
          <div class="trees-coadd">
            <div class="scollhide">
              <div class="iconlist">
                <Tree :data="menusList" show-checkbox ref="tree" @on-check-change='checkChange'></Tree>
              </div>
            </div>
          </div>
        </FormItem>
        <Spin size="large" fix v-if="spinShow"></Spin>
        <Button
          type="primary"
          size="large"
          long
          @click="handleSubmit('formInline')"
          >提交</Button
        >
      </Form>
    </Modal>
  </div>
</template>
<script>
import { mapState } from 'vuex'
import {
  roleListApi,
  roleSetStatusApi,
  menusListApi,
  roleCreatApi,
  roleInfoApi,
} from '@/api/setting'
import Setting from '@/setting';
export default {
  name: 'systemrRole',
  data() {
    return {
	  roterPre: Setting.roterPre,
	  allMenus: false,
	  indeterminate: false,
      spinShow: false,
      modals: false,
      total: 0,
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      loading: false,
      formValidate: {
        status: '',
        role_name: '',
        page: 1,
        limit: 20,
      },
      columns1: [
        {
          title: 'ID',
          key: 'id',
          width: 200,
        },
        {
          title: '角色昵称',
          key: 'role_name',
          minWidth: 200,
        },
		{
		  title: '权限类型',
		  slot: 'type',
		  minWidth: 200,
		},
        {
          title: '状态',
          slot: 'is_shows',
          minWidth: 200,
        },
        {
          title: '创建时间',
          key: 'add_time',
          minWidth: 200,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          minWidth: 120,
        },
      ],
      tableList: [],
      formInline: {
        role_name: '',
        status: 0,
        checked_menus: [],
        id: 0,
      },
      menusList: [],
      modelTit: '',
      ruleValidate: {
        role_name: [
          { required: true, message: '请输入角色昵称', trigger: 'blur' },
        ],
        status: [
          {
            required: true,
            type: 'number',
            message: '请选择是否开启',
            trigger: 'change',
          },
        ],
      },
	  expand:true
    }
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 96
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right'
    },
    labelPosition2() {
      return this.isMobile ? 'top' : 'right'
    },
  },
  created() {
    this.getList()
  },
  methods: {
	checkChange(e){
		if(e.length==this.getAllIds().length){
			this.indeterminate = false;
			this.allMenus = true;
		} else if(e.length>0){
			this.indeterminate = true;
			this.allMenus = true;
		}else{
			this.indeterminate = false;
			this.allMenus = false;
		}
		let checkedMenus = [];
		if(e.length){
			e.forEach(item=>{
				if(item.id){
					checkedMenus.push(item.id)
				}
			})
		}
		this.formInline.checked_menus = checkedMenus.length?checkedMenus:e;
	},
	changeMenus(){
		this.expand  = !this.expand
		this.tidyRes(this.menusList);
	},
	handleCheckAll(e){
		this.indeterminate = false;
		if(this.allMenus){
			this.formInline.checked_menus = this.getAllIds();
		}else{
			this.formInline.checked_menus = [];
		}
		this.tidyRes(this.menusList);
	},
    // 添加
    add(name) {
		this.$router.push({ path: this.roterPre + "/setting/system_role/add/" + 0 });
	  // this.expand = true;
	  // this.allMenus = false;
	  // this.indeterminate = false;
   //    this.formInline.id = 0
   //    this.modelTit = name
	  // this.modals = true
   //    this.getmenusList()
   
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `setting/role/${row.id}`,
        method: 'DELETE',
        ids: '',
      }
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg)
          this.tableList.splice(num, 1)
          if (!this.tableList.length) {
            this.formValidate.page =
                this.formValidate.page == 1 ? 1 : this.formValidate.page - 1;
          }
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },
    // 修改是否显示
    onchangeIsShow(row) {
      let data = {
        id: row.id,
        status: row.status,
      }
      roleSetStatusApi(data)
        .then(async (res) => {
          this.$Message.success(res.msg)
        })
        .catch((res) => {
          this.$Message.error(res.msg)
        })
    },
    // 列表
    getList() {
      this.loading = true
      this.formValidate.status = this.formValidate.status || ''
      roleListApi(this.formValidate)
        .then(async (res) => {
          let data = res.data
          this.tableList = data.list
          this.total = res.data.count
          this.loading = false
        })
        .catch((res) => {
          this.loading = false
          this.$Message.error(res.msg)
        })
    },
    pageChange(index) {
      this.formValidate.page = index
      this.getList()
    },
    // 表格搜索
    userSearchs() {
      this.formValidate.page = 1
      this.getList()
    },
    // 编辑
    edit(row, name) {
		this.$router.push({ path: this.roterPre + "/setting/system_role/add/" + row.id });
	  // this.expand = true;
      // this.modelTit = name
      // this.formInline.id = row.id
      // this.modals = true
      // this.rows = row
      // this.getIofo(row)
    },
    //默认添加打开时首页被选中
    checkedFun(data) {
	  let checkedMenus = this.formInline.checked_menus;
	  data.forEach(item=>{
		  if(item.id == 7){
			  let children = item.children[0];
			  children.checked = true;
			  checkedMenus.push(children.id);
			  children.children.forEach(j=>{
			  	j.checked = true;
				checkedMenus.push(j.id);
			   })
		  }
	  })
	  if(checkedMenus.length){
	  	 this.checkChange(checkedMenus);
	  }
    },
	// 获取菜单列表所有id；
	getAllIds(){
		let ids = [];
		this.menusList.forEach(item=>{
			ids.push(item.id);
			let getIds = function(item){
				if(item.children && item.children.length){
					item.children.forEach(j=>{
						ids.push(j.id);
						getIds(j)
					})
				}
			}
			getIds(item)
		})
		return ids;
	},
    // 菜单列表
    getmenusList() {
      this.spinShow = true
      menusListApi()
        .then(async (res) => {
          let data = res.data.menus
          this.menusList = data
          this.checkedFun(data)
          this.spinShow = false
        })
        .catch((res) => {
          this.spinShow = false
          this.$Message.error(res.msg)
        })
    },
    // 详情
    getIofo(row) {
      this.spinShow = true
      roleInfoApi(row.id)
        .then(async (res) => {
          let data = res.data
          this.formInline = data.role || this.formInline
          this.formInline.checked_menus = this.formInline.rules
		  let checkedMenus = this.formInline.checked_menus.split(',');
          this.tidyRes(data.menus,checkedMenus)
          this.spinShow = false
        })
        .catch((res) => {
          this.spinShow = false
          this.$Message.error(res.msg)
        })
    },
    tidyRes(menus,checkedMenus) {
      let data = []
      menus.map((menu) => {
        data.push(this.initMenu(menu))
      })
      this.$set(this, 'menusList', data)
	  if(checkedMenus && checkedMenus.length){
		  this.checkChange(checkedMenus);
	  }
    },
    initMenu(menu) {
      let data = {},
        checkMenus = ',' + this.formInline.checked_menus + ','
      data.title = menu.title
      data.id = menu.id
	  data.expand = this.expand
      if (menu.children && menu.children.length > 0) {
        data.children = []
        menu.children.map((child) => {
          data.children.push(this.initMenu(child))
        })
      } else {
		data.checked = checkMenus.indexOf(String(',' + data.id + ',')) !== -1
      }
      return data
    },
    // 提交
    handleSubmit(name) {
      this.$refs[name].validate((valid) => {
        if (valid) {
          this.formInline.checked_menus = []
          this.$refs.tree.getCheckedAndIndeterminateNodes().map((node) => {
            this.formInline.checked_menus.push(node.id)
          })
          if (this.formInline.checked_menus.length === 0){
			  return this.$Message.error('请至少选择一个权限')
		  }
          roleCreatApi(this.formInline)
            .then(async (res) => {
              this.$Message.success(res.msg)
              this.modals = false
              this.getList()
              this.$refs[name].resetFields()
              this.formInline.checked_menus = []
            })
            .catch((res) => {
              this.$Message.error(res.msg)
            })
        } else {
          return false
        }
      })
    },
    onCancel() {
      this.$refs['formInline'].resetFields()
      this.formInline.checked_menus = []
	  this.allMenus = false;
    },
  },
}
</script>

<style scoped lang="stylus">
.input-add {
 width: 250px;
}
.mr14 {
 margin-right: 14px;
}
.trees-coadd {
  width: 100%;
  height: 385px;

  .scollhide {
    width: 100%;
    height: 100%;
    overflow-x: hidden;
    overflow-y: scroll;
  }
}
/deep/::-webkit-scrollbar-thumb {
	-webkit-box-shadow: inset 0 0 6px #999;
}

/deep/::-webkit-scrollbar {
	width: 2px !important;
	/*对垂直流动条有效*/
}
/deep/::-webkit-scrollbar-track{
	background-color: #eee
}
</style>
