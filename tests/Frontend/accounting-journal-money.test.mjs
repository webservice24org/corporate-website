import assert from 'node:assert/strict';
import test from 'node:test';
import { cents, money } from '../../resources/js/Pages/ERP/Accounting/Journals/types.ts';
test('exact cents avoid binary rounding', () => { assert.equal(money(cents('0.10') + cents('0.20')), '0.30'); });
test('largest supported amount stays exact', () => { assert.equal(money(cents('999999999999999999.99')), '999999999999999999.99'); });
test('fractional normalization', () => { assert.equal(money(cents('12.3')), '12.30'); assert.equal(money(cents('0')), '0.00'); });
for (const value of ['', '-1', '+1', '1e2', '1,000', ' 1', '1.001', '1.', '.5', '1000000000000000000']) {
    test(`invalid amount: ${JSON.stringify(value)}`, () => assert.equal(cents(value), null));
}
