import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { setImmediate } from 'node:timers/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');

function browser(responses, { token = 'test-fragment-secret', lang = 'en' } = {}) {
  const elements = new Map();
  for (const name of ['message', 'confirmation', 'confirm', 'identity', 'warning', 'title']) {
    elements.set(`[data-exchange-${name}]`, {
      textContent: '', hidden: name === 'confirmation', disabled: false,
      events: {}, addEventListener(name, fn) { this.events[name] = fn; },
      focus() { this.focused = true; },
      set innerHTML(_) { throw new Error('Secret-bearing values must use textContent.'); },
    });
  }
  const exchange = {
    dataset: { selector: 'ml_test', csrf: 'test-csrf', endpoint: '/api/v1/exchange' },
    querySelector: (selector) => elements.get(selector),
  };
  const calls = [], order = [], redirects = [], timers = [], events = {};
  const location = { hash: token ? `#token=${token}` : '', pathname: '/auth/check', search: '?id=ml_test', replace: (url) => redirects.push(url), reload: () => order.push(['reload']) };
  vm.runInNewContext(source, {
    document: { documentElement: { lang }, querySelector: (selector) => selector === '[data-exchange]' ? exchange : null },
    window: { addEventListener: (name, fn) => { events[name] = fn; } },
    location,
    history: { replaceState: (_, __, url) => order.push(['clean-url', url]) },
    URLSearchParams,
    fetch: async (url, options) => {
      order.push(['fetch', url]);
      calls.push({ url, ...options, body: JSON.parse(options.body) });
      const payload = responses.shift();
      if (payload instanceof Error) throw payload;
      assert.ok(payload, 'Unexpected automatic request');
      return { ok: payload.ok, json: async () => payload };
    },
    setTimeout: (fn) => timers.push(fn),
  });
  return { calls, order, exchange, elements, redirects, timers, events, location,
    click: () => elements.get('[data-exchange-confirm]').events.click(),
  };
}

const preview = (accountSwitch = false) => ({ ok: true, code: 'magic_link.confirmation_required', data: {
  email: 'actual@example.com', account_switch: accountSwitch,
  confirmation: 'server-issued-challenge', message: 'Check your identity.',
} });
const verified = { ok: true, code: 'magic_link.verified', data: { message: 'Signed in.', redirect: '/account' } };

test('foreign link waits for a deliberate click before sending the server challenge', async () => {
  const page = browser([preview(), verified]);
  await setImmediate();
  assert.equal(page.calls.length, 1);
  assert.equal(page.calls[0].body.confirmation, '');
  assert.equal(page.order[0][0], 'clean-url');
  assert.equal(page.elements.get('[data-exchange-confirmation]').hidden, false);
  assert.equal(page.elements.get('[data-exchange-identity]').textContent, 'actual@example.com');
  assert.equal(page.redirects.length, 0);
  assert.equal(page.timers.length, 0);
  page.click();
  page.click(); // A second click while the first request is pending is ignored.
  await setImmediate();
  assert.equal(page.calls.length, 2);
  assert.equal(page.calls[1].body.confirmation, 'server-issued-challenge');
  assert.equal(page.calls[1].headers['X-CSRF-Token'], 'test-csrf');
  assert.equal(page.calls[1].credentials, 'same-origin');
  assert.equal(page.exchange.dataset.confirmed, 'true');
  page.timers[0]();
  assert.deepEqual(page.redirects, ['/account']);
});

for (const lang of ['en', 'de']) {
  test(`account-switch warning is explicit in ${lang}`, async () => {
    const page = browser([preview(true)], { lang });
    await setImmediate();
    assert.match(page.elements.get('[data-exchange-warning]').textContent, lang === 'de' ? /anderen Konto/ : /switching accounts/);
    assert.equal(page.calls.length, 1);
    assert.equal(page.exchange.dataset.confirmed, undefined);
  });
}

test('same-browser success retains the single automatic request', async () => {
  const page = browser([verified]);
  await setImmediate();
  assert.equal(page.calls.length, 1);
  assert.equal(page.exchange.dataset.confirmed, 'true');
});

test('missing fragment cannot send a request', async () => {
  const page = browser([], { token: '' });
  await setImmediate();
  assert.equal(page.calls.length, 0);
  assert.equal(page.exchange.dataset.failed, 'true');
});

test('reopening a link in the same tab restarts fragment validation', async () => {
  const page = browser([], { token: '' });
  page.location.hash = '#token=new-fragment';
  page.events.hashchange();
  assert.deepEqual(page.order.at(-1), ['reload']);
});

test('failed confirmation stops without redirect or automatic retry', async () => {
  const page = browser([preview(), { ok: false, error: { message: 'Confirmation expired.' } }]);
  await setImmediate();
  page.click();
  await setImmediate();
  assert.equal(page.calls.length, 2);
  assert.equal(page.exchange.dataset.failed, 'true');
  assert.equal(page.elements.get('[data-exchange-confirmation]').hidden, true);
  assert.equal(page.timers.length, 0);
});
