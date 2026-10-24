import {copyFileSync} from 'node:fs';
for (const file of ['invoice-generator.php','auth.php','auth-policy.php','config-loader.php','api.php','integrations.php','integrations-lib.php','integrations-admin.php']) copyFileSync('server/'+file,'dist/'+file);
console.log('Included api.php. Deploy dist contents; configure private MySQL credentials and storage separately.');
