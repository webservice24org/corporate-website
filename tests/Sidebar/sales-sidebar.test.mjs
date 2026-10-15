import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { sidebarLinkIsActive, sidebarPath } from '../../resources/js/lib/erp-sidebar-active.ts';

const menus = [
    ['/erp/sales/reports/dashboard', undefined],
    ['/erp/sales/reports', '/erp/sales/reports/dashboard'],
    ['/erp/sales/receipts', '/erp/sales/receipts/statement'],
    ['/erp/sales/receipts/statement', undefined],
];
for (const [url, expected] of [
    ['/erp/sales/reports/dashboard', 0],
    ['/erp/sales/reports/dashboard?company_id=1', 0],
    ['/erp/sales/reports/dashboard/?from=2026-10-01#totals', 0],
    ['/erp/sales/reports', 1],
    ['/erp/sales/reports?type=invoices&page=2', 1],
    ['/erp/sales/reports/export?type=receipts', 1],
    ['/erp/sales/receipts', 2],
    ['/erp/sales/receipts/create?company_id=1', 2],
    ['/erp/sales/receipts/27/edit', 2],
    ['/erp/sales/receipts/27/print', 2],
    ['/erp/sales/receipts/statement', 3],
    ['/erp/sales/receipts/statement/?customer_id=2&print=1', 3],
]) {
    test(`one active Sales menu: ${url}`, () => {
        assert.deepEqual(menus.flatMap(([href, exclude], index) =>
            sidebarLinkIsActive(url, href, false, exclude) ? [index] : []), [expected]);
    });
}
test('path boundaries prevent similar names from matching', () => {
    assert.equal(sidebarLinkIsActive('/erp/sales/receipts-extra', '/erp/sales/receipts'), false);
    assert.equal(sidebarLinkIsActive('/erp/inventory/stock-transfers', '/erp/inventory/stock'), false);
    assert.equal(sidebarLinkIsActive('/erp/sales/reports/dashboard-extra', '/erp/sales/reports', false, '/erp/sales/reports/dashboard'), true);
});
test('exact links accept filters but exclude descendants', () => {
    assert.equal(sidebarLinkIsActive('/erp/dashboard?company_id=1#top', '/erp/dashboard', true), true);
    assert.equal(sidebarLinkIsActive('/erp/dashboard/other', '/erp/dashboard', true), false);
    assert.equal(sidebarPath('/erp/sales/?page=2'), '/erp/sales');
});
test('sidebar uses the tested matcher and both nested exclusions', () => {
    const source = readFileSync(new URL('../../resources/js/Components/ERP/ERPSidebar.tsx', import.meta.url), 'utf8');
    assert.match(source, /const isActive = sidebarLinkIsActive\(currentUrl, href, exact, excludePrefix\)/);
    assert.match(source, /label="Sales Reports"[^>]*excludePrefix="\/erp\/sales\/reports\/dashboard"/);
    assert.match(source, /label="Customer Receipts"[^>]*excludePrefix="\/erp\/sales\/receipts\/statement"/);
    assert.match(source, /aria-current=\{isActive \? "page" : undefined\}/);
});
