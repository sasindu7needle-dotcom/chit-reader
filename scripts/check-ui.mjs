import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
await mkdir('artifacts',{recursive:true});
const browser=await chromium.launch({executablePath:'C:/Users/RS COMPUTERS/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe'});
const page=await browser.newPage({viewport:{width:1440,height:1100},deviceScaleFactor:1});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
for(const screen of ['dashboard','upload','queue','review','students','templates','settings']){const response=await page.goto('http://127.0.0.1:8000/?page='+screen);if(response.status()!==200)throw Error(screen+' returned '+response.status());await page.screenshot({path:'artifacts/'+screen+'.png',fullPage:true});}
await page.goto('http://127.0.0.1:8000/');await page.getByTitle('Notifications').click();if(!await page.locator('#notifications').isVisible())throw Error('Notification panel did not open');
await page.setViewportSize({width:390,height:844});await page.goto('http://127.0.0.1:8000/');await page.screenshot({path:'artifacts/mobile.png',fullPage:true});
if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth))throw Error('Mobile page overflows viewport');
if(errors.length)throw Error(errors.join('\n'));console.log('Seven screens, notifications, and mobile layout passed.');await browser.close();
