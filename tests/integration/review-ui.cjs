const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PIXCENSUS_PLAYWRIGHT_MODULE || 'playwright');
const artifacts = process.env.PIXCENSUS_QA_ARTIFACTS || require('node:os').tmpdir();
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.PIXCENSUS_CHROMIUM_PATH});
 const page=await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8925/fixture-login.php');await page.waitForLoadState('networkidle');
 console.log('UI controls:',await page.locator('#pixcensus-run-scan').innerText(), 'rows:', await page.locator('.pixcensus-row').count());
 assert(await page.getByText('Size / dimensions',{exact:true}).count());
 // A network failure must re-enable the control and preserve the displayed snapshot.
 await page.route('**/admin-ajax.php', route=>route.abort('failed'), {times:1});
 await page.locator('#pixcensus-run-scan').click({noWaitAfter:true});
 await page.waitForFunction(()=>!document.querySelector('#pixcensus-run-scan').disabled);
 assert(await page.locator('.notice-error').count());
 await page.reload();await page.waitForLoadState('networkidle');
 // Only the server owns checkpoint cursors; these forged fields must be ignored.
 const partial=await page.evaluate(async()=>{
 const r=await fetch(PixCensusAdmin.ajax_url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'pixcensus_run_scan',nonce:PixCensusAdmin.nonces.run_scan,stage:'999',page:'999'})});return r.json();});
 assert.equal(partial.data.done,false);assert.equal(partial.data.stage,0);assert.equal(partial.data.page,2);
 await page.reload();await page.waitForLoadState('networkidle');
 assert((await page.locator('#pixcensus-scan-progress').innerText()).includes('resumed'));

 const completed = page.waitForNavigation({waitUntil:'networkidle',timeout:90000});
 await page.locator('#pixcensus-run-scan').click({noWaitAfter:true});
 await completed;
 await page.waitForLoadState('networkidle');
 await page.locator('[name="pixcensus_sort"]').selectOption('weight');
 await page.locator('[name="pixcensus_min_width"]').fill('2');
 await page.getByRole('button',{name:'Apply',exact:true}).click();
 await page.waitForLoadState('networkidle');
 assert.equal(await page.locator('.pixcensus-row').count(),0);
 await page.locator('[name="pixcensus_min_width"]').fill('0');
 await page.getByRole('button',{name:'Apply',exact:true}).click();await page.waitForLoadState('networkidle');
 const selects=page.locator('.pixcensus-row').filter({hasText:'shared.png'}).locator('.pixcensus-select');assert(await selects.count()>1);
 await selects.nth(0).check();await selects.nth(1).check();
 const total=await page.locator('#pixcensus-selected-bytes').innerText();assert.equal(total,'68 B');
 await page.locator('#pixcensus-quick-filter').fill('not-present-qa-filter');
 assert.equal(await page.locator('.pixcensus-select:checked').count(),0);
 await page.locator('#pixcensus-quick-filter').fill('');
 await page.getByText('Why this classification?',{exact:true}).first().click();
 await page.screenshot({path:path.join(artifacts,'review-desktop.png'),fullPage:true});
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(artifacts,'review-mobile.png'),fullPage:true});
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({result:'pass',checks:['AJAX failure recovery and durable resume','server-owned cursors','AJAX full scan','filters across tab','size selection','hidden selection cleared','evidence details','desktop/mobile render','no JS errors','shared-file selection deduplicated to 68 B']}));
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
