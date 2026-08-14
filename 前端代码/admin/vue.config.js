const Setting = require("./src/setting.env");

// 引入打包分析文件
const { BundleAnalyzerPlugin } = require("webpack-bundle-analyzer");

// 引入Gzip压缩文件
const CompressionPlugin = require("compression-webpack-plugin");

// 引入js打包工具
const UglifyJsPlugin = require("uglifyjs-webpack-plugin");



// 拼接路径
const resolve = (dir) => require("path").join(__dirname, dir);

// 增加环境变量
process.env.VUE_APP_VERSION = require("./package.json").version;
// 可复算构建：勿写入实时时钟，否则 RH 二次重建 SHA 门禁必失败
process.env.VUE_APP_BUILD_TIME = process.env.VUE_APP_BUILD_TIME || require("./package.json").version;

module.exports = {
  indexPath: process.env.NODE_ENV === 'development' ? 'index.html' : 'system.html',
  publicPath: Setting.publicPath,
  lintOnSave: Setting.lintOnSave,
  outputDir: Setting.outputDir,
  assetsDir: Setting.assetsDir,
  runtimeCompiler: true,
  productionSourceMap: false, //关闭生产环境下的SourceMap映射文件
  devServer: {
    publicPath: Setting.publicPath,
    // 直接访问 18081/view_inventory_v3/ 时，也转发到库存 Vue 3 热更新服务。
    // 平台页面内嵌库存时仍由 InventoryV3Bridge 直接指向 18086；这里覆盖
    // 用户手动打开旧路径的场景，避免回退到 Admin 的空壳 index.html。
    proxy: {
      '^/view_inventory_v3(?:/|$)': {
        target: process.env.VUE_APP_INVENTORY_V3_DEV_PROXY_TARGET || 'http://host.docker.internal:18086',
        changeOrigin: false,
        ws: true
      }
    }

  },

  // 打包优化
  configureWebpack: (config) => {
    // 仅 ANALYZE=true 时启动分析器；生产发布必须 ANALYZE=false，否则门禁失败
    const pluginsPro = process.env.ANALYZE === "true" ? [
      new BundleAnalyzerPlugin()
    ] : [];

     pluginsPro.push(
        new CompressionPlugin({
          algorithm: "gzip",
          test: /\.js$|\.html$|\.css$/, // 匹配文件名
          minRatio: 0.8, // 压缩率小于1才会压缩
          threshold: 10240, // 对超过10k的数据压缩
          deleteOriginalAssets: false // 是否删除未压缩的源文件，谨慎设置，如果希望提供非gzip的资源，可不设置或者设置为false（比如删除打包后的gz后还可以加载到原始资源文件）
        })
      );

    pluginsPro.push(
        // js文件压缩
        new UglifyJsPlugin({
          uglifyOptions: {
            compress: {
              drop_debugger: true,
              drop_console: true, //生产环境自动删除console
              pure_funcs: ["console.log"] //移除console
            },
          },
          sourceMap: false,
          parallel: true //使用多进程并行运行来提高构建速度。默认并发运行数：os.cpus().length - 1。
        })
      );

    if (process.env.NODE_ENV === "production") {
      config.plugins = [...config.plugins, ...pluginsPro];

    }
  },

  css: {
    sourceMap: false, // css sourceMap 配置
    loaderOptions: {
      less: {},
    },
  },

  transpileDependencies: ["view-design", "iview", "vuedraggable"],
  // 默认设置: https://github.com/vuejs/vue-cli/tree/dev/packages/@vue/cli-service/lib/config/base.js

  chainWebpack: (config) => {
    /**
     * 删除懒加载模块的 prefetch preload，降低带宽压力
     * https://cli.vuejs.org/zh/guide/html-and-static-assets.html#prefetch
     * https://cli.vuejs.org/zh/guide/html-and-static-assets.html#preload
     * 而且预渲染时生成的 prefetch 标签是 modern 版本的，低版本浏览器是不需要的
     */
    config.plugins.delete("prefetch").delete("preload");
    // 解决 cli3 热更新失效 https://github.com/vuejs/vue-cli/issues/1559
    config.resolve.symlinks(true);
    config
      // 开发环境
      .when(
        process.env.NODE_ENV === "development",
        // sourcemap不包含列信息
        (config) => config.devtool("cheap-source-map")
      )
      // 非开发环境
      .when(process.env.NODE_ENV !== "development", (config) => {});
    // 不编译 iView Pro
    config.module
      .rule("js")
      .test(/\.jsx?$/)
      .exclude.add(resolve("src/libs/iview-pro"))
      .end();
    // 使用 iView Loader
    config.module
      .rule("vue")
      .test(/\.vue$/)
      .use("iview-loader")
      .loader("iview-loader")
      .tap(() => {
        return Setting.iviewLoaderOptions;
      })
      .end();
    // markdown
    config.module
      .rule("md")
      .test(/\.md$/)
      .use("text-loader")
      .loader("text-loader")
      .end();
    // i18n
    config.module
      .rule("i18n")
      .resourceQuery(/blockType=i18n/)
      .use("i18n")
      .loader("@kazupon/vue-i18n-loader")
      .end();
    // image exclude
    const imagesRule = config.module.rule("images");
    imagesRule
      .test(/\.(png|jpe?g|gif|webp|svg)(\?.*)?$/)
      .exclude.add(resolve("src/assets/svg"))
      .end();
    // 重新设置 alias
    config.resolve.alias.set("@api", resolve("src/api"));
    // node
    config.node.set("__dirname", true).set("__filename", true);
    // 判断是否需要加入模拟数据
    const entry = config.entry("app");
    if (Setting.isMock) {
      entry.add("@/mock").end();
    }
  },
};
