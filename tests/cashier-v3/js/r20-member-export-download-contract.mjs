import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const source = await readFile(new URL('../../../前端代码/cashier-v3/src/views/MemberListView.vue', import.meta.url), 'utf8')

assert.match(source, /import \{ readStoreV3SessionToken \} from '@\/services\/storeV3SessionToken'/)
assert.match(source, /:on-download-export="downloadMemberExport"/)
assert.match(source, /endpoint\.origin !== window\.location\.origin/)
assert.match(source, /pageCode !== MEMBER_QUERY_PAGE_CODE/)
assert.match(source, /endpoint\.searchParams\.set\('pageCode', pageCode\)/)
assert.match(source, /Authorization: `Bearer \$\{token\}`/)
assert.match(source, /'Authori-zation': `Bearer \$\{token\}`/)
assert.match(source, /!response\.ok \|\| type\.includes\('json'\)/)
assert.match(source, /header\[0\] !== 0x50/)
assert.match(source, /header\[1\] !== 0x4b/)
assert.match(source, /header\[2\] !== 0x03/)
assert.match(source, /header\[3\] !== 0x04/)
assert.match(source, /memberExportDownloadFileName\(fileName\)/)
assert.match(source, /URL\.createObjectURL\(blob\)/)

console.log('R20 member export authenticated download contract passed.')
