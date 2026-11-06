// Local component harness imports canonical source; never a second business implementation.
import {createServer} from '../../../前端代码/cashier-v3/node_modules/vite/dist/node/index.js';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('.',import.meta.url));
const server=await createServer({configFile:fileURLToPath(new URL('../../../前端代码/cashier-v3/vite.config.js',import.meta.url)),root,base:'/',resolve:{alias:[{find:'vue',replacement:fileURLToPath(new URL('../../../前端代码/cashier-v3/node_modules/vue/dist/vue.esm-bundler.js',import.meta.url))},{find:'@lucide/vue',replacement:fileURLToPath(new URL('../../../前端代码/cashier-v3/node_modules/@lucide/vue',import.meta.url))}]},server:{host:'127.0.0.1',port:18119,strictPort:true,fs:{allow:[fileURLToPath(new URL('../../../',import.meta.url))]}}});await server.listen();console.log('R6 local component harness http://127.0.0.1:18119');
