/* The backend release must leave both RH frontend entries and their referenced bytes unchanged. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const https = require('node:https');
const get = url => new Promise((resolve, reject) => {
    const request = https.get(url, response => {
        const chunks = [];
        response.on('data', chunk => chunks.push(chunk));
        response.on('error', reject);
        response.on('end', () => resolve({status: response.statusCode, body: Buffer.concat(chunks)}));
    });
    request.setTimeout(20000, () => request.destroy(Error('HTTP timeout')));
    request.on('error', reject);
});
const site = process.argv[2];
if (!['rh', '008'].includes(site)) throw Error('Explicit instance required');
const directory = path.resolve(__dirname, '../tests/cashier-v3/evidence/m010-custom-card-2026-10-22');
const baseline = JSON.parse(fs.readFileSync(path.join(directory, site + '-preflight.json')));
const origin = site === 'rh' ? 'https://rh.cc3798.com' : 'https://008.cc3798.com';
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
(async () => {
    const results = [];
    for (const [name, entry] of Object.entries(baseline.entries)) {
        const prefix = name === 'preview' ? '/preview-8080' : '';
        const url = origin + prefix + '/view_cashier_v3/';
        const response = await get(url);
        const html = response.body.toString('utf8');
        if (response.status !== 200) throw Error('Entry failed: ' + url);
        const assets = [];
        for (const [relative, expected] of Object.entries(entry.assets)) {
            const ref = prefix + relative;
            if (!html.includes(ref)) throw Error('Referenced frontend changed: ' + ref);
            const asset = await get(origin + ref);
            const actual = sha(asset.body);
            if (asset.status !== 200 || actual !== expected) throw Error('Asset fingerprint mismatch: ' + ref);
            assets.push({path: ref, status: asset.status, sha256: actual});
        }
        results.push({name, url, status: response.status, frontendUnchanged: true, assets});
    }
    const result = {site, at: new Date().toISOString(), result: 'PASS', entries: results};
    fs.writeFileSync(path.join(directory, site + '-public-verification.json'), JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
})().catch(error => { console.error(error); process.exitCode = 1; });
