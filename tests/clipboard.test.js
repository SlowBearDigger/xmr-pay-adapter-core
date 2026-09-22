// Verify the payment card never reports a failed clipboard write as copied.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const card = fs.readFileSync(__dirname + '/../views/pay_card.php', 'utf8');
const code = card.match(/<script>\s*(\(function\(\)\{\s*var btn=document[\s\S]*?)<\/script>/)[1];
(async () => {
    for (const [clipboard, legacy, expected] of [
        [{ writeText: () => Promise.resolve() }, false, 'Copied'],
        [{ writeText: () => Promise.reject(new Error('denied')) }, true, 'Copied'],
        [null, false, 'Copy failed'],
        [{ writeText() { throw new Error('denied'); } }, false, 'Copy failed']
    ]) {
        let click;
        const button = { textContent: 'Copy', addEventListener(event, cb) { click = cb; } };
        const value = { textContent: '78test-address' };
        const selection = { removeAllRanges() {}, addRange() {} };
        vm.runInNewContext(code, {
            navigator: clipboard ? { clipboard } : {},
            window: { getSelection() { return selection; } },
            document: {
                getElementById(id) { return id === 'xmrpay-copy' ? button : value; },
                createRange() { return { selectNode() {} }; },
                execCommand() { return legacy; }
            },
            setTimeout() {}, Promise
        });
        click();
        await Promise.resolve();
        await Promise.resolve();
        assert.ok(button.textContent.startsWith(expected), button.textContent);
        assert.equal(value.textContent, '78test-address');
    }
    console.log('Payment card: 4 clipboard scenarios passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
