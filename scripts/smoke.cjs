// Authenticated browser test. Use only a disposable database and a Super Admin test account.
const {chromium}=require('playwright');
const {readFileSync}=require('node:fs');
(async()=>{
 if(!process.env.API_TEST_CREDENTIALS_FILE)throw Error('Set API_TEST_CREDENTIALS_FILE to a private test-account JSON file.');
 const credentials=JSON.parse(readFileSync(process.env.API_TEST_CREDENTIALS_FILE,'utf8'));
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||undefined,headless:true,args:['--no-sandbox']});
 try{
 const page=await browser.newPage({viewport:{width:1440,height:1050}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>localStorage.setItem('khushrang-demo',JSON.stringify({events:[{name:'Stale browser fixture'}]})));
 await page.goto(process.env.TEST_BASE_URL||'http://127.0.0.1:8080');
 await page.getByRole('heading',{name:'Sign in to your workspace',exact:true}).waitFor();await page.locator('#login-email:enabled').waitFor();
 if(await page.locator('.sidebar,.main,.overlay').count())throw Error('Portal markup is mounted before authentication');
 if(await page.getByText('Explore demo',{exact:true}).count())throw Error('Public demo entry exists');
 if(await page.evaluate(()=>getComputedStyle(document.querySelector('.auth-page')).backgroundColor)!=='rgb(255, 255, 255)')throw Error('Login background is not white');
 await page.getByLabel('Email address',{exact:true}).fill(credentials.email);await page.getByLabel('Password',{exact:true}).fill(credentials.password);await page.getByRole('button',{name:'Sign in',exact:true}).click();
 await page.getByRole('heading',{name:'A little overview. A bigger picture.',exact:true}).waitFor();
 const name='Browser fixture '+Date.now();await page.getByRole('button',{name:'Create event',exact:true}).click();await page.locator('input[name=name]').fill(name);await page.locator('input[name=type]').fill('Wedding');await page.locator('input[name=date]').fill('2099-12-10');await page.locator('input[name=client]').fill('Fixture client');await page.locator('input[name=amount]').fill('123456');await page.getByRole('button',{name:'Save record',exact:true}).click();
 await page.getByRole('button',{name:'Events & orders',exact:true}).click();await page.getByPlaceholder('Search events…').fill(name);await page.getByText(name,{exact:true}).waitFor();await page.reload();await page.getByRole('heading',{name:'A little overview. A bigger picture.',exact:true}).waitFor();
 await page.getByRole('button',{name:'Events & orders',exact:true}).click();await page.getByPlaceholder('Search events…').fill(name);await page.getByText(name,{exact:true}).click();await page.locator('input[name=name]').fill(name+' edited');await page.getByRole('button',{name:'Save record',exact:true}).click();await page.getByPlaceholder('Search events…').fill(name+' edited');await page.getByText(name+' edited',{exact:true}).waitFor();
 const download=page.waitForEvent('download');await page.getByRole('button',{name:'Export',exact:true}).click();if(!(await download).suggestedFilename().endsWith('.csv'))throw Error('CSV not downloaded');
 await page.getByText(name+' edited',{exact:true}).click();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Delete',exact:true}).click();await page.getByText('No records found',{exact:true}).waitFor();
 await page.setViewportSize({width:1280,height:720});for(const label of ['Clients','Vendors','Invoices','Finances','Media library','Tasks & checklist','Integrations','Users & roles','Settings'])await page.getByRole('button',{name:new RegExp('^'+label)}).click();
 await page.getByRole('button',{name:'Overview',exact:true}).click();await page.setViewportSize({width:390,height:844});if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('Mobile horizontal overflow');
 await page.getByRole('button',{name:'Toggle navigation',exact:true}).click();await page.getByRole('button',{name:'Close menu',exact:true}).click();await page.getByRole('button',{name:'Toggle navigation',exact:true}).click();await page.keyboard.press('Escape');await page.getByRole('button',{name:'Toggle navigation',exact:true}).click();await page.getByRole('button',{name:'Close navigation',exact:true}).click({position:{x:350,y:400}});
 await page.getByRole('button',{name:'Toggle navigation',exact:true}).click();await page.getByRole('button',{name:'Clients',exact:true}).click();await page.setViewportSize({width:1440,height:1050});await page.getByTitle('Sign out',{exact:true}).click();await page.getByRole('heading',{name:'Sign in to your workspace',exact:true}).waitFor();if(await page.locator('.sidebar,.main').count())throw Error('Portal remains mounted after logout');
 if(errors.length)throw Error(errors.join('\n'));console.log('PASS: white standalone login, no demo/dashboard before auth, authenticated CRUD/persistence/search/CSV, all navigation, mobile controls, logout removes portal.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
