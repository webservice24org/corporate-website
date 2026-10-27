import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { sidebarLinkIsActive } from '../../resources/js/lib/erp-sidebar-active.ts';

const accounting = [
    ['/erp/accounting', true],
    ['/erp/accounting/settings', false],
    ['/erp/accounting/accounts', false],
    ['/erp/accounting/mappings', false],
    ['/erp/accounting/setup-readiness', false],
    ['/erp/accounting/calendar', false],
    ['/erp/accounting/journals', false],
    ['/erp/accounting/ledger', false],
    ['/erp/accounting/cutover', false],
    ['/erp/accounting/openings', false],
    ['/erp/accounting/manifest', false],
];
for (const [url, expected] of [
    ['/erp/accounting', [0]],
    ['/erp/accounting?company_id=1', [0]],
    ['/erp/accounting/?company_id=1#setup', [0]],
    ['/erp/accounting/settings', [1]],
    ['/erp/accounting/settings?company_id=2', [1]],
    ['/erp/accounting/settings/?company_id=2#configuration', [1]],
    ['/erp/accounting/accounts', [2]],
    ['/erp/accounting/accounts/create?company_id=1', [2]],
    ['/erp/accounting/accounts/3/edit', [2]],
    ['/erp/accounting/accounts/3', [2]],
    ['/erp/accounting/mappings', [3]],
    ['/erp/accounting/mappings?company_id=2', [3]],
    ['/erp/accounting/mappings/12', [3]],
    ['/erp/accounting/mappings-extra', []],
    ['/erp/accounting/setup-readiness', [4]],
    ['/erp/accounting/setup-readiness?company_id=3&date=2026-10-15', [4]],
    ['/erp/accounting/setup-readiness-extra', []],
    ['/erp/accounting/calendar', [5]],
    ['/erp/accounting/calendar/create?company_id=2', [5]],
    ['/erp/accounting/calendar/7/edit', [5]],
    ['/erp/accounting/calendar/7', [5]],
    ['/erp/accounting/calendar-extra', []],
    ['/erp/accounting/journals', [6]],
    ['/erp/accounting/journals?company_id=1', [6]],
    ['/erp/accounting/journals/create', [6]],
    ['/erp/accounting/journals/1/edit', [6]],
    ['/erp/accounting/journals-extra', []],
    ['/erp/accounting/journals/1/print', [6]],
    ['/erp/accounting/ledger', [7]],
    ['/erp/accounting/ledger?company_id=1&account_id=2', [7]],
    ['/erp/accounting/ledger-extra', []],
    ['/erp/accounting/cutover', [8]],
    ['/erp/accounting/cutover?company_id=1', [8]],
    ['/erp/accounting/cutover-extra', []],
    ['/erp/accounting/openings', [9]],
    ['/erp/accounting/openings?cutover_id=1', [9]],
    ['/erp/accounting/openings-extra', []],
    ['/erp/accounting/manifest', [10]],
    ['/erp/accounting/manifest?cutover_id=1', [10]],
    ['/erp/accounting/manifest-extra', []],
    ['/erp/accounting/settings-extra', []],
]) {
    test(`Accounting active child: ${url}`, () => {
        assert.deepEqual(accounting.flatMap(([href, exact], i) => sidebarLinkIsActive(url, href, exact) ? [i] : []), expected);
    });
}
const sales = [
    ['/erp/sales/reports/dashboard', undefined],
    ['/erp/sales/reports', '/erp/sales/reports/dashboard'],
    ['/erp/sales/receipts', '/erp/sales/receipts/statement'],
    ['/erp/sales/receipts/statement', undefined],
];
for (const [url, expected] of [
    ['/erp/sales/reports/dashboard?company_id=1', 0],
    ['/erp/sales/reports?type=invoices', 1],
    ['/erp/sales/receipts/42/edit', 2],
    ['/erp/sales/receipts/statement?customer_id=2', 3],
]) {
    test(`Sales remains single-active: ${url}`, () => {
        assert.deepEqual(sales.flatMap(([href, exclude], i) => sidebarLinkIsActive(url, href, false, exclude) ? [i] : []), [expected]);
    });
}
test('sidebar gates Accounting and wires exact Overview plus Setup', () => {
    const source = readFileSync(new URL('../../resources/js/Components/ERP/ERPSidebar.tsx', import.meta.url), 'utf8');
    assert.match(source, /const canViewAccounting = can\("erp.accounting-settings.view"\)/);
    assert.match(source, /\{canViewAccounting &&/);
    assert.match(source, /aria-controls="accounting-submenu"/);
    assert.match(source, /href="\/erp\/accounting" label="Accounting Overview"[^>]* exact /);
    assert.match(source, /href="\/erp\/accounting\/settings" label="Accounting Setup"/);
    assert.match(source, /label="Sales Reports"[^>]*excludePrefix="\/erp\/sales\/reports\/dashboard"/);
    assert.match(source, /label="Customer Receipts"[^>]*excludePrefix="\/erp\/sales\/receipts\/statement"/);
    assert.doesNotMatch(source, /FutureSidebarItem label="Accounting"/);
});

test('Chart of Accounts is linked and permission gated', () => {
 const source = readFileSync(new URL('../../resources/js/Components/ERP/ERPSidebar.tsx', import.meta.url), 'utf8');
 assert.match(source, /can\("erp.accounts.view"\)/);
 assert.match(source, /href="\/erp\/accounting\/accounts" label="Chart of Accounts"/);
});

test('Posting Profiles link is permission gated', () => {
    const source = readFileSync(new URL('../../resources/js/Components/ERP/ERPSidebar.tsx', import.meta.url), 'utf8');
    assert.match(source, /can\("erp.accounting-mappings.view"\)/);
    assert.match(source, /href="\/erp\/accounting\/mappings" label="Posting Profiles"/);
});

test('Readiness link uses the account view permission', () => {
    const source = readFileSync(new URL('../../resources/js/Components/ERP/ERPSidebar.tsx', import.meta.url), 'utf8');
    assert.match(source, /\{can\("erp.accounts.view"\) && <SidebarLink href="\/erp\/accounting\/setup-readiness" label="Account Setup Readiness"/);
});

test('Fiscal Calendar link is permission gated', () => {
    const source = readFileSync(new URL('../../resources/js/Components/ERP/ERPSidebar.tsx', import.meta.url), 'utf8');
    assert.match(source, /\{can\("erp.fiscal-years.view"\) && <SidebarLink href="\/erp\/accounting\/calendar" label="Fiscal Calendar"/);
});
