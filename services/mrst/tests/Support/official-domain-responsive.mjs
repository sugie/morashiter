import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';

// Use an existing Playwright runtime; no project dependency or browser download.
// Requires the isolated browser fixtures (alpha.example/beta.example) on mrst_test.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE);
const base = process.env.BROWSER_BASE_URL ?? 'http://localhost:6558';
assert.equal(new URL(base).hostname, 'localhost');
const output = process.env.BROWSER_OUTPUT_DIR;
await fs.mkdir(output, {recursive: true});
const browser = await chromium.launch({channel: 'msedge', headless: true});
try {
    const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/admin/login');
    await page.locator('input[name=email]').fill(process.env.BROWSER_EMAIL);
    await page.locator('input[name=password]').fill(process.env.BROWSER_PASSWORD);
    await Promise.all([page.waitForURL('**/admin'), page.getByRole('button', {name: 'ログイン', exact: true}).click()]);
    await page.goto(base + '/admin/link-candidates');

    async function checkLayout(width, state) {
        await page.setViewportSize({width, height: 900});
        const metrics = await page.evaluate(() => {
            const button = document.querySelector('header button');
            const range = document.createRange();
            range.selectNodeContents(button);
            const outside = [...document.querySelectorAll('table, input:not([type=hidden]), select, textarea, button')]
                .filter(e => e.getClientRects().length)
                .filter(e => { const r = e.getBoundingClientRect(); return r.left < -1 || r.right > innerWidth + 1; })
                .map(e => e.tagName + ':' + (e.name || e.textContent.trim().slice(0, 20)));
            const wrongLabels = innerWidth > 640 ? [] : [...document.querySelectorAll('table')].flatMap(table => {
                const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
                return [...table.querySelectorAll('tbody tr')].flatMap(row => [...row.cells]
                    .filter(cell => cell.colSpan === 1)
                    .filter((cell, index) => getComputedStyle(cell, '::before').content !== '"' + headers[index] + '"')
                    .map(cell => cell.textContent.trim().slice(0, 30)));
            });
            return {viewport: innerWidth, scroll: document.documentElement.scrollWidth, logoutLines: range.getClientRects().length, outside, wrongLabels};
        });
        await page.screenshot({path: path.join(output, state + '-' + width + '.png'), fullPage: true});
        console.log(state, metrics);
        return [
            ...(metrics.scroll > width ? [width + 'px page overflows to ' + metrics.scroll] : []),
            ...(metrics.logoutLines !== 1 ? [width + 'px logout wraps to ' + metrics.logoutLines + ' lines'] : []),
            ...metrics.outside.map(e => width + 'px off-screen ' + e),
            ...metrics.wrongLabels.map(e => width + 'px incorrect mobile heading: ' + e),
        ];
    }

    const failures = [];
    for (const width of [390, 640, 1024, 1440]) {
        failures.push(...await checkLayout(width, 'before'));
    }
    assert.deepEqual(failures, [], 'No page overflow, hidden controls or wrapped logout text');

    if (!process.argv.includes('--layout-only')) {
    await page.setViewportSize({width: 390, height: 900});
    const alpha = page.getByRole('region', {name: 'alpha.example のAI調査結果', exact: true});
    await alpha.locator('input[name=company_name]').fill('株式会社アルファ（スマートフォン幅で確認済）');
    for (const name of ['evidence_checked', 'ownership_checked', 'name_checked', 'batch_checked']) {
        await alpha.locator('input[name=' + name + ']').check();
    }
    await alpha.locator('textarea[name=reason]').fill('390px幅で組織名と確認項目を操作して登録しました。');
    await Promise.all([page.waitForNavigation(), alpha.getByRole('button', {name: '確認結果を保存して登録', exact: true}).click()]);
    assert.match(await page.locator('body').innerText(), /株式会社アルファ（スマートフォン幅で確認済）/);

    const beta = page.getByRole('region', {name: 'beta.example のAI調査結果', exact: true});
    await beta.locator('textarea[name=reason]').fill('390px幅で保留操作を確認。親会社との関係を追加確認します。');
    await Promise.all([page.waitForNavigation(), beta.getByRole('button', {name: '理由を保存して保留', exact: true}).click()]);
    assert.match(await page.locator('body').innerText(), /前回の人の判断：保留/);
    for (const width of [390, 640, 1024, 1440]) {
        assert.deepEqual(await checkLayout(width, 'after'), []);
    }
    }
    assert.deepEqual(errors, []);
    console.log('PASS: responsive layout and registration/hold interactions');
} finally {
    await browser.close();
}
