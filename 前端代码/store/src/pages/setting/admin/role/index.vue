<template>
  <div>
    <Card :bordered="false" dis-hover class="ive-mt">
      <div class="btnbox">
        <Button type="primary" @click="add">添加角色</Button>
      </div>
      <div class="table">
        <Table
          :columns="columns"
          :data="roleList"
          ref="table"
          class="mt25"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
        >
          <template slot-scope="{ row, index }" slot="status">
            <i-switch
              v-model="row.status"
              :value="row.status"
              :true-value="1"
              :false-value="0"
              @on-change="changeSwitch(row)"
              size="large"
            >
              <span slot="open">开启</span>
              <span slot="close">关闭</span>
            </i-switch>
          </template>
          <template slot-scope="{ row, index }" slot="action">
            <a @click="edit(row.id)">编辑</a>
            <Divider type="vertical"/>
            <a @click="del(row.id, '删除该店员', index)">删除</a>
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
      </div>
    </Card>
  </div>
</template>

<script>
import { mapState } from "vuex";
import Setting from "@/setting";
import {systemRole,systemRoleStatus} from "@/api/setting.js";
export default {
  name: "clerkList",
  data() {
    return {
		routePre:Setting.routePre,
		roleList: [],
		loading: false,
		formValidate: {
		  page: 1,
		  limit: 15
		},
		total: 0,
		columns: [
		  {
		    title: "ID",
		    key: "id",
		    width: 60,
		  },
		  {
		    title: "角色昵称",
		    key: "role_name",
		    minWidth: 120,
		  },
		  {
		    title: "状态",
		    slot: "status",
		    minWidth: 120,
		  },
		  {
		    title: "创建时间",
		    key: "add_time",
		    minWidth: 150,
		  },
		  {
		    title: "操作",
		    slot: "action",
		    fixed: "right",
		    width: 130,
		  }
		]
    };
  },
  computed: {
    ...mapState("store/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? "top" : "left";
    },
  },
  mounted() {
    this.roleInfo();
  },
  methods: {
	roleInfo() {
		systemRole().then(res => {
			this.total = res.data.count;
			this.roleList = res.data.list;
			this.loading = false;
		}).catch(err => {
			this.$Message.error(err.msg);
			this.loading = false;
		})
	},
	//添加
	add() {
		this.$router.push({ path: this.routePre + "/admin/system_role/add/" + 0 });
	},
	//编辑
	edit(id) {
		this.$router.push({ path: this.routePre + "/admin/system_role/add/" + id });
	},
	//删除
	del(id, tit, num) {
	  let delfromData = {
	    title: tit,
	    num: num,
	    url: `system/role/${id}`,
	    method: "DELETE",
	    ids: "",
	  };
	  this.$modalSure(delfromData)
	    .then((res) => {
	      this.$Message.success(res.msg);
	      this.roleList.splice(num, 1);
	      if (!this.roleList.length) {
	        this.formValidate.page =
	            this.formValidate.page == 1 ? 1 : this.formValidate.page - 1;
	      }
	      this.roleInfo();
	    })
	    .catch((res) => {
	      this.$Message.error(res.msg);
	    });
	},
	//状态
	changeSwitch(row) {
	  systemRoleStatus(row.id, row.status)
	    .then((res) => {
	      this.$Message.success(res.msg);
	    })
	    .catch((err) => {
	      this.$Message.error(err.msg);
	    });
	},
	//分页
	pageChange(status) {
	  this.formValidate.page = status;
	  this.roleInfo();
	}
  }
};
</script>

<style scoped lang="less"></style>
