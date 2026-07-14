<template>
  <div>
    <Card :bordered="false" dis-hover>
      <Button class="mb15" type="primary" @click="addTicket">添加打印机</Button>
      <Table :columns="columns" :data="dataList" :loading="loading">
        <template slot-scope="{ row }" slot="plat_type">
          <div>{{ row.plat_type == 1 ? '易联云' : '飞鹅云' }}</div>
        </template>
        <template slot-scope="{ row }" slot="status">
          <i-switch
            v-model="row.status"
            :true-value="1"
            :false-value="0"
            size="large"
            @on-change="statusChange(row)"
          >
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
        <template slot-scope="{ row }" slot="print_event">
          <div>
            {{ row.print_event.indexOf('2') != -1 ? '支付后打印' : '' }}
            {{ row.print_event.indexOf('1') != -1 ? '下单后打印' : '' }}
          </div>
        </template>
        <template slot-scope="{ row }" slot="action">
          <a @click="onSetting(row.id)">设计</a>
          <Divider type="vertical" />
          <a @click="onEdit(row)">编辑</a>
          <Divider type="vertical" />
          <a @click="onDelete(row.id)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :page-size="limit"
          show-total
          @on-change="onChange"
        />
      </div>
    </Card>
    <addTicket ref="ticket" @printerList="printerList"></addTicket>
  </div>
</template>

<script>
import addTicket from './components/addTicket.vue';
import { getPrinterList, postPrinterStatus } from '@/api/setting';
import Setting from '@/setting';
export default {
  components: { addTicket },
  data() {
    return {
      columns: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
        },
        {
          title: '打印机名称',
          key: 'name',
          minWidth: 200,
        },
        {
          title: '平台',
          slot: 'plat_type',
          minWidth: 150,
        },
        {
          title: '打印联数',
          key: 'print_num',
          minWidth: 150,
        },
        {
          title: '打印时机',
          slot: 'print_event',
          minWidth: 150,
        },
        {
          title: '创建时间',
          key: 'add_time',
          minWidth: 150,
        },
        {
          title: '打印开关',
          slot: 'status',
          minWidth: 120,
        },
        {
          title: '操作',
          slot: 'action',
          align: 'center',
          width: 150,
        },
      ],
      dataList: [],
      total: 100,
      limit: 10,
      page: 1,
      loading: false,
    };
  },
  created() {
    this.printerList();
  },
  methods: {
    //获取打印机列表
    printerList() {
      this.loading = true;
      getPrinterList({
        page: this.page,
        limit: this.limit,
      })
        .then((res) => {
          let { list, count } = res.data;
          this.dataList = list;
          this.total = count;
          this.loading = false;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 分页
    onChange(page) {
      this.page = page;
      this.printerList();
    },
    // 打印机开关
    statusChange(row) {
      postPrinterStatus(row)
        .then((res) => {
          this.$Message.success(res.msg);
          this.printerList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 删除
    onDelete(id) {
      this.$modalSure({
        title: '删除打印机',
        url: `/system/printer/${id}`,
        method: 'delete',
        ids: '',
      }).then((res) => {
        this.$Message.success(res.msg);
        this.printerList();
      });
    },
    addTicket() {
      this.$refs.ticket.react();
      this.$refs.ticket.modals = true;
      this.$refs.ticket.id = 0;
    },
    // 编辑
    onEdit(row) {
      this.$refs.ticket.modals = true;
      this.$refs.ticket.id = row.id;
      this.$refs.ticket.getInfo();
    },
    // 设计
    onSetting(id) {
      this.$router.push({
        path: `${Setting.routePre}/set/hardware/content/${id}`,
      });
    },
  },
};
</script>