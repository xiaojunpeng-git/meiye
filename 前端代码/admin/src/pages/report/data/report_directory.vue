<template>
  <section class="report-directory">
    <h2>{{ $route.meta.title }}</h2>
    <ul v-if="entries.length">
      <li v-for="entry in entries" :key="entry.id">
        <router-link :to="entry.path">{{ entry.title }}</router-link>
      </li>
    </ul>
    <div v-else class="report-directory__empty">暂无已配置且已授权的报表</div>
  </section>
</template>

<script>
export default {
  name: 'ReportDirectory',
  computed: {
    // Directories only enumerate server-authorized navigation. Never infer child
    // grants from the directory grant or redirect to an unrelated default report.
    entries () {
      const normalize = path => String(path || '').replace(/^\/admin(?=\/)/, '').replace(/\/$/, '');
      const target = normalize(this.$route.path);
      const find = nodes => {
        for (const node of nodes || []) {
          if (normalize(node.path) === target) return node;
          const match = find(node.children);
          if (match) return match;
        }
        return null;
      };
      const directory = find(this.$store.state.admin.menus.menusName);
      return ((directory && directory.children) || [])
        .filter(entry => !(entry.auth || []).includes('hidden'))
        .map(entry => ({ ...entry, path: `${this.$route.path.split('/report/')[0]}${normalize(entry.path)}` }));
    }
  }
};
</script>

<style scoped>
.report-directory { padding: 24px; background: #fff; }
.report-directory h2 { margin: 0 0 20px; font-size: 20px; }
.report-directory ul { margin: 0; padding: 0; list-style: none; }
.report-directory li { padding: 12px 0; border-bottom: 1px solid #e8eaec; }
.report-directory__empty { padding: 40px 0; text-align: center; color: #808695; }
</style>
