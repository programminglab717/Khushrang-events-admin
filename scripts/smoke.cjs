const {chromium}=require('playwright');
(async()=>{
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || undefined,headless:true,args:['--no-sandbox']});
const page=await browser.newPage({viewport:{width:1440,height:1100}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.goto(process.env.TEST_BASE_URL || 'http://127.0.0.1:5173');await page.getByRole('heading',{name:'A little overview. A bigger picture.'}).waitFor();
await page.screenshot({path:'/tmp/khushrang-desktop.png',fullPage:true});
await page.getByRole('button',{name:'Create event'}).click();
await page.locator('input[name=name]').fill('Smoke test wedding');await page.locator('input[name=type]').fill('Wedding');await page.locator('input[name=date]').fill('2026-12-10');await page.locator('input[name=client]').fill('Test client');await page.locator('input[name=amount]').fill('123456');await page.getByRole('button',{name:'Save record'}).click();
await page.getByRole('button',{name:'Events & orders'}).click();await page.getByPlaceholder('Search events…').fill('Smoke test');await page.getByText('Smoke test wedding',{exact:true}).waitFor();
await page.reload();await page.getByRole('button',{name:'Events & orders'}).click();await page.getByPlaceholder('Search events…').fill('Smoke test');await page.getByText('Smoke test wedding',{exact:true}).click();await page.locator('input[name=name]').fill('Edited wedding');await page.getByRole('button',{name:'Save record'}).click();await page.getByPlaceholder('Search events…').fill('Edited wedding');await page.getByText('Edited wedding',{exact:true}).waitFor();
const download=page.waitForEvent('download');await page.getByRole('button',{name:'Export',exact:true}).click();const d=await download;if(!d.suggestedFilename().endsWith('.csv'))throw Error('CSV not downloaded');
await page.getByText('Edited wedding',{exact:true}).click();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Delete',exact:true}).click();await page.getByText('No records found',{exact:true}).waitFor();
await page.setViewportSize({width:1280,height:720});
for(const label of ['Clients','Vendors','Invoices','Finances','Media library','Tasks & checklist','Integrations','Users & roles','Settings']){await page.getByRole('button',{name:new RegExp('^'+label)}).click();}
await page.getByRole('button',{name:'Integrations',exact:true}).click();await page.getByRole('heading',{name:'Receive client data',exact:true}).waitFor();if(await page.getByRole('button',{name:'Queue delivery',exact:true}).isEnabled())throw Error('Demo mode permits external delivery');
await page.getByRole('button',{name:'Overview',exact:true}).click();await page.getByRole('button',{name:'Complete Finalize the floral moodboard'}).click();await page.locator('.check.checked').waitFor();
await page.setViewportSize({width:390,height:844});await page.screenshot({path:'/tmp/khushrang-mobile.png',fullPage:true});if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth))throw Error('Mobile horizontal overflow');
await page.locator('.mobile-menu').click();await page.getByRole('button',{name:'Clients',exact:true}).click();await page.getByRole('heading',{name:'Clients',exact:true}).waitFor();
if(errors.length)throw Error(errors.join('\n'));console.log('PASS: dashboard, create/edit/delete, local persistence, search, CSV download, all navigation, task completion, mobile layout/navigation; no browser errors.');await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
