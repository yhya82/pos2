const { chromium } = require('playwright-core');

(async () => {
    const browser = await chromium.launch({
        executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        headless: true,
    });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [];
    page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
    page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));

    await page.goto('http://127.0.0.1:8765/login', { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'login.png' });

    await page.fill('#email', 'admin@example.com');
    await page.fill('#password', 'dev-password-123');
    await page.click('button:has-text("Log in")');
    await page.waitForURL('**/dashboard', { timeout: 10000 });
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'dashboard.png', fullPage: true });

    const sidebarText = await page.locator('aside').innerText();
    const headerText = await page.locator('header').innerText();

    console.log('--- SIDEBAR ITEMS ---');
    console.log(sidebarText);
    console.log('--- HEADER ---');
    console.log(headerText);
    console.log('--- CONSOLE/PAGE ERRORS ---');
    console.log(errors.length ? errors.join('\n') : '(none)');

    await browser.close();
})().catch((e) => { console.error('SCRIPT FAILED:', e); process.exit(1); });
