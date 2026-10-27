import { accountingLinks } from "@/lib/accounting-navigation";
import { Link, usePage, router } from "@inertiajs/react";
import { useState } from "react";
import {
    BarChart3,
    BookCheck,
    Boxes,
    UserRoundCog,
    UserRound,
    Building2,
    Calculator,
    ChevronDown,
    ClipboardCheck,
    ContactRound,
    FileBarChart,
    PackageSearch,
    Package,
    LayoutDashboard,
    LogOut,
    PanelsTopLeft,
    ShoppingBag,
    ShoppingCart,
    UsersRound,
    ClockFading,
    Scale,
    Tag,
    Globe2,
    ListFilter,
    Sparkles,
    ClipboardList,
    ListTodo,
    ChartNoAxesCombined,
    MapPin,
    Map,
    Network,
    BadgeCheck,
    RotateCwFadingClock,
    UserSearch,
    CalendarCheck,
    CalendarDays,
    CalendarOff,
    FileSpreadsheet,
    Layers,
    X,
    LucideIcon,
    BanknoteCheck,
    WalletCards,
    Settings,
    Warehouse, FileText, Landmark, Store, BookOpen, ArrowLeftRight, AlertTriangle,
} from "lucide-react";

import { sidebarLinkIsActive, sidebarPath } from "@/lib/erp-sidebar-active";

import { useAuthorization } from "@/hooks/useAuthorization";

type SidebarProps = {
    mobileOpen?: boolean;
    onMobileClose?: () => void;
};

export default function ERPSidebar({
    mobileOpen = false,
    onMobileClose,
}: SidebarProps) {
    const { url: pageUrl, props } = usePage();
    const url = sidebarPath(pageUrl);
    const { can } = useAuthorization();

    const user = props.auth?.user;

    const closeMobile = () => {
        onMobileClose?.();
    };

    const handleLogout = () => {
        router.post("/logout");
    };

    // --- Organization ---
    const canViewOrganization =
        can("erp.companies.view") ||
        can("erp.branches.view") ||
        can("erp.departments.view") ||
        can("erp.designations.view");

    const organizationActive =
        url.startsWith("/erp/companies") ||
        url.startsWith("/erp/branches") ||
        url.startsWith("/erp/departments") ||
        url.startsWith("/erp/designations");

    const [organizationExpanded, setOrganizationExpanded] = useState(false);
    const organizationOpen = organizationActive || organizationExpanded;

    // --- HRM ---
    const canViewHRM =
        can("erp.employees.view") ||
        can("erp.employee-shifts.view") ||
        can("erp.shifts.view") ||
        can("erp.attendance-devices.view") ||
        can("erp.attendance-device-users.view") ||
        can("erp.attendance-raw-punches.view") ||
        can("erp.attendance-settings.view") ||
        can("erp.attendance-records.view") ||
        can("erp.attendance-finalization.view") ||
        can("erp.attendance-reports.view") ||
        can("erp.attendance-holidays.view");

    const hrmActive =
        url.startsWith("/erp/employees") ||
        url.startsWith("/erp/attendance/shifts") ||
        url.startsWith("/erp/attendance/settings") ||
        url.startsWith("/erp/attendance/employee-shifts") ||
        url.startsWith("/erp/attendance/devices") ||
        url.startsWith("/erp/attendance/device-users") ||
        url.startsWith("/erp/attendance/raw-punches") ||
        url.startsWith("/erp/attendance/records") ||
        url.startsWith("/erp/attendance/finalization") ||
        url.startsWith("/erp/reports/attendance") ||
        url.startsWith("/erp/attendance/holidays");

    const [hrmExpanded, setHrmExpanded] = useState(false);
    const hrmOpen = hrmActive || hrmExpanded;

    // --- Leave Management ---
    const canViewLeaveManagement =
        can("erp.leave-requests.view") || can("erp.leave-types.view") || can("erp.leave-balances.view");

    const leaveActive =
        url.startsWith("/erp/leave/requests") ||
        url.startsWith("/erp/leave/types") || 
        url.startsWith("/erp/leave/balances");

    const [leaveExpanded, setLeaveExpanded] = useState(false);
    const leaveOpen = leaveActive || leaveExpanded;

    // --- Payroll ---
    const canViewPayroll = 
        can("erp.salary-components.view") ||
        can("erp.salary-structures.view") ||
        can("erp.payroll-settings.view") ||
        can("erp.payroll-periods.view");

    const payrollActive = 
        url.startsWith("/erp/payroll/salary-components") ||
        url.startsWith("/erp/payroll/salary-structures") ||
        url.startsWith("/erp/payroll/settings") || 
        url.startsWith("/erp/payroll/periods"); 

    const [payrollExpanded, setPayrollExpanded] = useState(false);
    const payrollOpen = payrollActive || payrollExpanded;


    // --- CRM State & Checks ---
    const canViewLeadsSub =
        can("erp.leads.view") ||
        can("erp.lead-sources.view") ||
        can("erp.lead-statuses.view") ||
        can("erp.opportunity-stages.view") ||
        can("erp.opportunities.view");

    const canViewDistributionSub =
        can("erp.depots.view") ||
        can("erp.depot-applications.view") ||
        can("erp.dealers.view") ||
        can("erp.dealer-applications.view") ||
        can("erp.sellers.view") ||
        can("erp.customers.view");

    const canViewCRM =
        canViewLeadsSub ||
        can("erp.crm-activities.view") ||
        can("erp.crm-analytics.view") ||
        canViewDistributionSub;

    // Submenu Active States
    const leadsActive =
        url.startsWith("/erp/crm/leads") ||
        url.startsWith("/erp/crm/lead-sources") ||
        url.startsWith("/erp/crm/lead-statuses") ||
        url.startsWith("/erp/crm/opportunity-stages") ||
        url.startsWith("/erp/crm/opportunities");

    const distributionActive =
        url.startsWith("/erp/crm/depots") ||
        url.startsWith("/erp/crm/depot-applications") ||
        url.startsWith("/erp/crm/dealers") ||
        url.startsWith("/erp/crm/dealer-applications") ||
        url.startsWith("/erp/crm/sellers") ||
        url.startsWith("/erp/crm/customers");

    const crmActive =
        leadsActive ||
        url.startsWith("/erp/crm/activities") ||
        url.startsWith("/erp/crm/analytics") ||
        distributionActive;

    // Toggle Expansion States
    const [crmExpanded, setCrmExpanded] = useState(false);
    const crmOpen = crmActive || crmExpanded;

    const [leadsExpanded, setLeadsExpanded] = useState(false);
    const leadsOpen = leadsActive || leadsExpanded;

    const [distributionExpanded, setDistributionExpanded] = useState(false);
    const distributionOpen = distributionActive || distributionExpanded;


    // --- Inventory / Product Catalog ---
    const canViewInventory =
        can("erp.products.view") ||
        can("erp.product-categories.view") ||
        can("erp.brands.view") ||
        can("erp.units.view") ||
        can("erp.warehouses.view") ||
        can("erp.inventory-stock.view") ||
        can("erp.stock-movements.view") ||
        can("erp.opening-stocks.view") ||
        can("erp.stock-transfers.view") ||
        can("erp.low-stock-reports.view");

    const inventoryActive =
        url.startsWith("/erp/inventory/products") ||
        url.startsWith("/erp/inventory/product-categories") ||
        url.startsWith("/erp/inventory/brands") ||
        url.startsWith("/erp/inventory/units") ||
        url.startsWith("/erp/inventory/warehouses") ||
        url.startsWith("/erp/inventory/stock-movements") ||
        url.startsWith("/erp/inventory/stock") || 
        url.startsWith("/erp/inventory/opening-stocks") ||
        url.startsWith("/erp/inventory/stock-transfers") ||
        url.startsWith("/erp/inventory/low-stock");

    const [inventoryExpanded, setInventoryExpanded] = useState(false);
    const inventoryOpen = inventoryActive || inventoryExpanded;


    // --- Purchase ---
    const canViewPurchase =
        can("erp.purchase-reports.view") ||
        can("erp.suppliers.view") ||
        can("erp.purchase-requisitions.view") ||
        can("erp.purchase-rfqs.view") ||
        can("erp.purchase-orders.view") ||
        can("erp.goods-receipts.view") ||
        can("erp.purchase-returns.view") ||
        can("erp.supplier-bills.view") ||
        can("erp.supplier-payables.view");

    const purchaseActive = url.startsWith("/erp/purchase/");
    const [purchaseExpanded, setPurchaseExpanded] = useState(false);
    const purchaseOpen = purchaseActive || purchaseExpanded;

    // --- Sales foundation: operational links arrive with their completed modules. ---
    const canViewSales =
        can("erp.sales-customers.view") ||
        can("erp.sales-quotations.view") ||
        can("erp.sales-orders.view") ||
        can("erp.sales-reservations.view") ||
        can("erp.sales-deliveries.view") ||
        can("erp.sales-invoices.view") ||
        can("erp.customer-receipts.view") ||
        can("erp.sales-returns.view") ||
        can("erp.sales-credit-notes.view") ||
        can("erp.customer-receivables.view") ||
        can("erp.sales-reports.view");

    const salesActive = url === "/erp/sales" || url.startsWith("/erp/sales/");
    const [salesExpanded, setSalesExpanded] = useState(false);
    const salesOpen = salesActive || salesExpanded;

    const accountingEntry = accountingLinks(can)[0];

    // --- ERP Settings State & Checks ---
    const canViewGeographySub =
        can("erp.divisions.view") ||
        can("erp.districts.view") ||
        can("erp.upazilas.view");

    const canViewErpSettings = canViewGeographySub; // Add other ERP Settings permission checks here as needed

    // Submenu Active States
    const geographyActive =
        url.startsWith("/erp/geography/divisions") ||
        url.startsWith("/erp/geography/districts") ||
        url.startsWith("/erp/geography/upazilas");

    const erpSettingsActive = geographyActive;

    // Toggle Expansion States
    const [erpSettingsExpanded, setErpSettingsExpanded] = useState(false);
    const erpSettingsOpen = erpSettingsActive || erpSettingsExpanded;

    const [geographyExpanded, setGeographyExpanded] = useState(false);
    const geographyOpen = geographyActive || geographyExpanded;

    return (
        <>
            {/* Mobile backdrop */}
            {mobileOpen && (
                <button
                    type="button"
                    aria-label="Close sidebar"
                    onClick={closeMobile}
                    className="fixed inset-0 z-40 bg-black/50 lg:hidden"
                />
            )}

            <aside
                className={`
                    fixed inset-y-0 left-0 z-50
                    flex w-72 flex-col
                    border-r border-slate-800
                    bg-slate-950 text-slate-300
                    transition-transform duration-300
                    lg:static lg:translate-x-0
                    ${
                        mobileOpen
                            ? "translate-x-0"
                            : "-translate-x-full"
                    }
                `}
            >
                {/* Brand */}
                <div className="flex h-16 items-center justify-between border-b border-slate-800 px-5">
                    <Link
                        href="/erp/dashboard"
                        onClick={closeMobile}
                        className="flex items-center gap-3"
                    >
                        <div className="flex size-9 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-lg shadow-indigo-600/20">
                            <PanelsTopLeft className="size-5" />
                        </div>

                        <div>
                            <div className="font-bold leading-none text-white">
                                Corporate
                            </div>

                            <div className="mt-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                                ERP System
                            </div>
                        </div>
                    </Link>

                    <button
                        type="button"
                        aria-label="Close sidebar"
                        onClick={closeMobile}
                        className="rounded-md p-2 text-slate-400 transition hover:bg-slate-800 hover:text-white lg:hidden"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                {/* Navigation */}
                <div className="flex-1 overflow-y-auto px-3 py-5 space-y-4">
                    <div>
                        <div className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-600">
                            Main
                        </div>

                        <nav className="space-y-1">
                            {can("erp.dashboard.view") && (
                                <SidebarLink
                                    href="/erp/dashboard"
                                    label="Dashboard"
                                    icon={LayoutDashboard}
                                    currentUrl={url}
                                    exact
                                    onClick={closeMobile}
                                />
                            )}
                        </nav>
                    </div>

                    {/* Organization */}
                    {canViewOrganization && (
                        <div className="space-y-1">
                            <button
                                type="button"
                                onClick={() =>
                                    setOrganizationExpanded((open) => !open)
                                }
                                className={`
                                    flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                    ${
                                        organizationActive
                                            ? "bg-white/10 text-white"
                                            : "text-slate-300 hover:bg-white/5 hover:text-white"
                                    }
                                `}
                            >
                                <Building2 className="size-4 shrink-0" />
                                <span className="flex-1">Organization</span>
                                <ChevronDown
                                    className={`size-4 shrink-0 transition-transform duration-200 ${
                                        organizationOpen ? "rotate-180" : ""
                                    }`}
                                />
                            </button>

                            {organizationOpen && (
                                <div className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                    {can("erp.companies.view") && (
                                        <SidebarLink
                                            href="/erp/companies"
                                            label="Companies"
                                            icon={Building2}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.branches.view") && (
                                        <SidebarLink
                                            href="/erp/branches"
                                            label="Branches"
                                            icon={MapPin}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.departments.view") && (
                                        <SidebarLink
                                            href="/erp/departments"
                                            label="Departments"
                                            icon={Network}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.designations.view") && (
                                        <SidebarLink
                                            href="/erp/designations"
                                            label="Designations"
                                            icon={BadgeCheck}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {/* HRM */}
                    {canViewHRM && (
                        <div className="space-y-1">
                            <button
                                type="button"
                                onClick={() => setHrmExpanded((open) => !open)}
                                className={`
                                    flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                    ${
                                        hrmActive
                                            ? "bg-white/10 text-white"
                                            : "text-slate-300 hover:bg-white/5 hover:text-white"
                                    }
                                `}
                            >
                                <UsersRound className="size-4 shrink-0" />
                                <span className="flex-1">HRM</span>
                                <ChevronDown
                                    className={`size-4 shrink-0 transition-transform duration-200 ${
                                        hrmOpen ? "rotate-180" : ""
                                    }`}
                                />
                            </button>

                            {hrmOpen && (
                                <div className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                    {can("erp.employees.view") && (
                                        <SidebarLink
                                            href="/erp/employees"
                                            label="Employees"
                                            icon={UserRoundCog}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.attendance-records.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/records"
                                            label="Attendance Records"
                                            icon={ClipboardCheck}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.attendance-finalization.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/finalization"
                                            label="Day Finalization"
                                            icon={CalendarCheck}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.attendance-holidays.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/holidays"
                                            label="Holiday Calendar"
                                            icon={CalendarDays}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.employee-shifts.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/employee-shifts"
                                            label="Employee Shifts"
                                            icon={UserSearch}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.shifts.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/shifts"
                                            label="Shifts"
                                            icon={RotateCwFadingClock}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.attendance-devices.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/devices"
                                            label="Devices"
                                            icon={UserSearch}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.attendance-device-users.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/device-users"
                                            label="Device Employee Mapping"
                                            icon={UserSearch}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.attendance-raw-punches.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/raw-punches"
                                            label="Raw Punch Logs"
                                            icon={UserSearch}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.attendance-settings.view") && (
                                        <SidebarLink
                                            href="/erp/attendance/settings"
                                            label="Attendance Settings"
                                            icon={BookCheck}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    {can("erp.attendance-reports.view") && (
                                        <SidebarLink
                                            href="/erp/reports/attendance"
                                            label="Attendance Reports"
                                            icon={CalendarDays}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Leave Management */}
                    {canViewLeaveManagement && (
                        <div className="space-y-1">
                            <button
                                type="button"
                                onClick={() => setLeaveExpanded((open) => !open)}
                                className={`
                                    flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                    ${
                                        leaveActive
                                            ? "bg-white/10 text-white"
                                            : "text-slate-300 hover:bg-white/5 hover:text-white"
                                    }
                                `}
                            >
                                <CalendarOff className="size-4 shrink-0" />
                                <span className="flex-1">Leave Management</span>
                                <ChevronDown
                                    className={`size-4 shrink-0 transition-transform duration-200 ${
                                        leaveOpen ? "rotate-180" : ""
                                    }`}
                                />
                            </button>

                            {leaveOpen && (
                                <div className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                    {can("erp.leave-requests.view") && (
                                        <SidebarLink
                                            href="/erp/leave/requests"
                                            label="Leave Requests"
                                            icon={FileSpreadsheet}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.leave-types.view") && (
                                        <SidebarLink
                                            href="/erp/leave/types"
                                            label="Leave Types"
                                            icon={Layers}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    {can("erp.leave-balances.view") && (
                                        <SidebarLink
                                            href="/erp/leave/balances"
                                            label="Leave Balances"
                                            icon={Scale}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                </div>
                            )}
                        </div>
                    )}

                    {/* Payroll */}
                    {canViewPayroll && (
                        <div className="space-y-1">
                            <button
                                type="button"
                                onClick={() => setPayrollExpanded((open) => !open)}
                                className={`
                                    flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                    ${
                                        payrollActive
                                            ? "bg-white/10 text-white"
                                            : "text-slate-300 hover:bg-white/5 hover:text-white"
                                    }
                                `}
                            >
                                <WalletCards className="size-4 shrink-0" />
                                <span className="flex-1">Payroll</span>
                                <ChevronDown
                                    className={`size-4 shrink-0 transition-transform duration-200 ${
                                        payrollOpen ? "rotate-180" : ""
                                    }`}
                                />
                            </button>

                            {payrollOpen && (
                                <div className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                    {can("erp.salary-components.view") && (
                                        <SidebarLink
                                            href="/erp/payroll/salary-components"
                                            label="Salary Components"
                                            icon={Layers}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    {can("erp.salary-structures.view") && (
                                        <SidebarLink
                                            href="/erp/payroll/salary-structures"
                                            label="Salary Structures"
                                            icon={BanknoteCheck}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    {can("erp.payroll-settings.view") && (
                                        <SidebarLink
                                            href="/erp/payroll/settings"
                                            label="Payroll Settings"
                                            icon={Settings}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    {can("erp.payroll-periods.view") && (
                                        <SidebarLink
                                            href="/erp/payroll/periods"
                                            label="Payroll Periods"
                                            icon={ClockFading}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Commercial */}
                    <div>
                        <div className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-600">
                            Commercial
                        </div>

                        {canViewCRM && (
                            <div className="space-y-1">
                                {/* Main CRM Header */}
                                <button
                                    type="button"
                                    onClick={() => setCrmExpanded((open) => !open)}
                                    className={`
                                        flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                        ${
                                            crmActive
                                                ? "bg-white/10 text-white"
                                                : "text-slate-300 hover:bg-white/5 hover:text-white"
                                        }
                                    `}
                                >
                                    <ContactRound className="size-4 shrink-0" />
                                    <span className="flex-1">CRM</span>
                                    <ChevronDown
                                        className={`size-4 shrink-0 transition-transform duration-200 ${
                                            crmOpen ? "rotate-180" : ""
                                        }`}
                                    />
                                </button>

                                {crmOpen && (
                                    <div className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                        
                                        {/* --- 1. LEADS SUB-SUBMENU --- */}
                                        {canViewLeadsSub && (
                                            <div className="space-y-1">
                                                <button
                                                    type="button"
                                                    onClick={() => setLeadsExpanded((open) => !open)}
                                                    className={`
                                                        flex w-full items-center gap-3 rounded-lg px-3 py-1.5 text-left text-sm font-medium transition
                                                        ${
                                                            leadsActive
                                                                ? "text-white"
                                                                : "text-slate-300 hover:bg-white/5 hover:text-white"
                                                        }
                                                    `}
                                                >
                                                    <UsersRound className="size-4 shrink-0" />
                                                    <span className="flex-1">Leads</span>
                                                    <ChevronDown
                                                        className={`size-3.5 shrink-0 transition-transform duration-200 ${
                                                            leadsOpen ? "rotate-180" : ""
                                                        }`}
                                                    />
                                                </button>

                                                {leadsOpen && (
                                                    <div className="ml-3 space-y-1 border-l border-white/10 pl-3">
                                                        {can("erp.leads.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/leads"
                                                                label="Leads"
                                                                icon={UsersRound}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.lead-sources.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/lead-sources"
                                                                label="Lead Sources"
                                                                icon={Tag}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.lead-statuses.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/lead-statuses"
                                                                label="Lead Statuses"
                                                                icon={ListFilter}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.opportunities.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/opportunities"
                                                                label="Opportunities"
                                                                icon={Sparkles}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.opportunity-stages.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/opportunity-stages"
                                                                label="Opportunity-Stages"
                                                                icon={ClipboardList}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {/* --- 2. ACTIVITIES (SINGLE ITEM) --- */}
                                        {can("erp.crm-activities.view") && (
                                            <SidebarLink
                                                href="/erp/crm/activities"
                                                label="Activities"
                                                icon={ListTodo}
                                                currentUrl={url}
                                                onClick={closeMobile}
                                            />
                                        )}

                                        {/* --- 3. DISTRIBUTION SUB-SUBMENU --- */}
                                        {canViewDistributionSub && (
                                            <div className="space-y-1">
                                                <button
                                                    type="button"
                                                    onClick={() => setDistributionExpanded((open) => !open)}
                                                    className={`
                                                        flex w-full items-center gap-3 rounded-lg px-3 py-1.5 text-left text-sm font-medium transition
                                                        ${
                                                            distributionActive
                                                                ? "text-white"
                                                                : "text-slate-300 hover:bg-white/5 hover:text-white"
                                                        }
                                                    `}
                                                >
                                                    <Network className="size-4 shrink-0" />
                                                    <span className="flex-1">Distribution</span>
                                                    <ChevronDown
                                                        className={`size-3.5 shrink-0 transition-transform duration-200 ${
                                                            distributionOpen ? "rotate-180" : ""
                                                        }`}
                                                    />
                                                </button>

                                                {distributionOpen && (
                                                    <div className="ml-3 space-y-1 border-l border-white/10 pl-3">
                                                        {can("erp.depots.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/depots"
                                                                label="Depots"
                                                                icon={Warehouse}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.depot-applications.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/depot-applications"
                                                                label="Depot Applications"
                                                                icon={FileText}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.dealers.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/dealers"
                                                                label="Dealers"
                                                                icon={FileText}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.dealer-applications.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/dealer-applications"
                                                                label="Dealer Applications"
                                                                icon={FileText}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.sellers.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/sellers"
                                                                label="Sellers"
                                                                icon={Store}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}
                                                        {can("erp.customers.view") && (
                                                            <SidebarLink
                                                                href="/erp/crm/customers"
                                                                label="Customers"
                                                                icon={UserRound}
                                                                currentUrl={url}
                                                                onClick={closeMobile}
                                                            />
                                                        )}

                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {can("erp.crm-analytics.view") && (
                                            <SidebarLink
                                                href="/erp/crm/analytics"
                                                label="CRM Analytics"
                                                icon={ChartNoAxesCombined}
                                                currentUrl={url}
                                                onClick={closeMobile}
                                            />
                                        )}

                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {canViewErpSettings && (
                        <div className="space-y-1">
                            {/* Main ERP Settings Header */}
                            <button
                                type="button"
                                onClick={() => setErpSettingsExpanded((open) => !open)}
                                className={`
                                    flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                    ${
                                        erpSettingsActive
                                            ? "bg-white/10 text-white"
                                            : "text-slate-300 hover:bg-white/5 hover:text-white"
                                    }
                                `}
                            >
                                <Settings className="size-4 shrink-0" />
                                <span className="flex-1">ERP Settings</span>
                                <ChevronDown
                                    className={`size-4 shrink-0 transition-transform duration-200 ${
                                        erpSettingsOpen ? "rotate-180" : ""
                                    }`}
                                />
                            </button>

                            {erpSettingsOpen && (
                                <div className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                    
                                    {/* --- GEOGRAPHY SUB-SUBMENU --- */}
                                    {canViewGeographySub && (
                                        <div className="space-y-1">
                                            <button
                                                type="button"
                                                onClick={() => setGeographyExpanded((open) => !open)}
                                                className={`
                                                    flex w-full items-center gap-3 rounded-lg px-3 py-1.5 text-left text-sm font-medium transition
                                                    ${
                                                        geographyActive
                                                            ? "text-white"
                                                            : "text-slate-300 hover:bg-white/5 hover:text-white"
                                                    }
                                                `}
                                            >
                                                <MapPin className="size-4 shrink-0" />
                                                <span className="flex-1">Geography</span>
                                                <ChevronDown
                                                    className={`size-3.5 shrink-0 transition-transform duration-200 ${
                                                        geographyOpen ? "rotate-180" : ""
                                                    }`}
                                                />
                                            </button>

                                            {geographyOpen && (
                                                <div className="ml-3 space-y-1 border-l border-white/10 pl-3">
                                                    {can("erp.divisions.view") && (
                                                        <SidebarLink
                                                            href="/erp/geography/divisions"
                                                            label="Divisions"
                                                            icon={Map}
                                                            currentUrl={url}
                                                            onClick={closeMobile}
                                                        />
                                                    )}
                                                    {can("erp.districts.view") && (
                                                        <SidebarLink
                                                            href="/erp/geography/districts"
                                                            label="Districts"
                                                            icon={Building2}
                                                            currentUrl={url}
                                                            onClick={closeMobile}
                                                        />
                                                    )}
                                                    {can("erp.upazilas.view") && (
                                                        <SidebarLink
                                                            href="/erp/geography/upazilas"
                                                            label="Upazilas"
                                                            icon={Landmark}
                                                            currentUrl={url}
                                                            onClick={closeMobile}
                                                        />
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                </div>
                            )}
                        </div>
                    )}

                    {canViewPurchase && (
                        <div className="space-y-1">
                            <button
                                type="button"
                                aria-expanded={purchaseOpen}
                                aria-controls="erp-purchase-submenu"
                                onClick={() => setPurchaseExpanded((open) => !open)}
                                className={`
                                    flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                    ${purchaseActive ? "bg-white/10 text-white" : "text-slate-300 hover:bg-white/5 hover:text-white"}
                                `}
                            >
                                <ShoppingCart className="size-4 shrink-0" />
                                <span className="flex-1">Purchase</span>
                                <ChevronDown className={`size-4 shrink-0 transition-transform duration-200 ${purchaseOpen ? "rotate-180" : ""}`} />
                            </button>

                            {purchaseOpen && (
                                <div id="erp-purchase-submenu" className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                    {can("erp.purchase-reports.view") && (
                                        <SidebarLink href="/erp/purchase/dashboard" label="Dashboard" icon={LayoutDashboard} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.suppliers.view") && (
                                        <SidebarLink href="/erp/purchase/suppliers" label="Suppliers" icon={ContactRound} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.purchase-requisitions.view") && (
                                        <SidebarLink href="/erp/purchase/requisitions" label="Purchase Requisitions" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.purchase-rfqs.view") && (
                                        <SidebarLink href="/erp/purchase/rfqs" label="RFQs & Supplier Quotations" icon={ShoppingCart} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.purchase-orders.view") && (
                                        <SidebarLink href="/erp/purchase/orders" label="Purchase Orders" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.goods-receipts.view") && (
                                        <SidebarLink href="/erp/purchase/receipts" label="Goods Receiving (GRN)" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.purchase-returns.view") && (
                                        <SidebarLink href="/erp/purchase/returns" label="Purchase Returns" icon={ArrowLeftRight} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.supplier-bills.view") && (
                                        <SidebarLink href="/erp/purchase/bills" label="Supplier Bills" icon={FileText} currentUrl={url} excludePrefix="/erp/purchase/bills/payables" onClick={closeMobile} />
                                    )}
                                    {can("erp.supplier-payables.view") && (
                                        <SidebarLink href="/erp/purchase/bills/payables" label="Supplier Payables" icon={BanknoteCheck} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.purchase-reports.view") && (
                                        <SidebarLink href="/erp/purchase/reports" label="Reports" icon={FileBarChart} currentUrl={url} onClick={closeMobile} />
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                    {canViewSales && (
                        <div className="space-y-1">
                            <button
                                type="button"
                                aria-expanded={salesOpen}
                                aria-controls="erp-sales-submenu"
                                onClick={() => setSalesExpanded((open) => !open)}
                                className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition ${
                                    salesActive
                                        ? "bg-white/10 text-white"
                                        : "text-slate-300 hover:bg-white/5 hover:text-white"
                                }`}
                            >
                                <ShoppingBag className="size-4 shrink-0" />
                                <span className="flex-1">Sales</span>
                                <ChevronDown
                                    aria-hidden="true"
                                    className={`size-4 shrink-0 transition-transform duration-200 ${salesOpen ? "rotate-180" : ""}`}
                                />
                            </button>
                            {salesOpen && (
                                <div id="erp-sales-submenu" className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                    {can("erp.sales-invoices.view") && (
                                        <SidebarLink href="/erp/sales/invoices" label="Sales Invoices" icon={FileText} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.sales-reports.view") && <><SidebarLink href="/erp/sales/reports/dashboard" label="Sales Dashboard" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} /><SidebarLink href="/erp/sales/reports" label="Sales Reports" icon={FileText} currentUrl={url} excludePrefix="/erp/sales/reports/dashboard" onClick={closeMobile} /></>}
                                    {can("erp.sales-credit-notes.view") && <SidebarLink href="/erp/sales/credit-notes" label="Sales Credit Notes" icon={FileText} currentUrl={url} onClick={closeMobile} />}
                                    {can("erp.customer-receivables.view") && <SidebarLink href="/erp/sales/customer-credits" label="Customer Credits" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />}
                                    {can("erp.sales-returns.view") && <SidebarLink href="/erp/sales/returns" label="Sales Returns" icon={FileText} currentUrl={url} onClick={closeMobile} />}
                                    {can("erp.customer-receipts.view") && <SidebarLink href="/erp/sales/receipts" label="Customer Receipts" icon={FileText} currentUrl={url} excludePrefix="/erp/sales/receipts/statement" onClick={closeMobile} />}
                                    {can("erp.customer-receivables.view") && <SidebarLink href="/erp/sales/receipts/statement" label="Customer Statements" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />}
                                    {can("erp.customer-receivables.view") && (
                                        <SidebarLink href="/erp/sales/receivables" label="Customer Receivables" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.sales-deliveries.view") && (
                                        <SidebarLink href="/erp/sales/deliveries" label="Delivery Notes" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.sales-reservations.view") && (
                                        <SidebarLink href="/erp/sales/reservations" label="Stock Reservations" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.sales-orders.view") && (
                                        <SidebarLink href="/erp/sales/orders" label="Sales Orders" icon={ClipboardCheck} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.sales-quotations.view") && (
                                        <SidebarLink href="/erp/sales/quotations" label="Quotations" icon={FileText} currentUrl={url} onClick={closeMobile} />
                                    )}
                                    {can("erp.sales-customers.view") ? (
                                        <SidebarLink href="/erp/sales/customers" label="Customers" icon={ContactRound} currentUrl={url} onClick={closeMobile} />
                                    ) : !can("erp.sales-quotations.view") && !can("erp.sales-orders.view") && !can("erp.sales-reservations.view") && !can("erp.sales-deliveries.view") && !can("erp.sales-invoices.view") && !can("erp.customer-receivables.view") && !can("erp.customer-receipts.view") && !can("erp.sales-returns.view") && !can("erp.sales-credit-notes.view") && !can("erp.sales-reports.view") ? (
                                        <p className="px-3 py-2 text-xs leading-relaxed text-slate-400">
                                            Sales features will appear here as they become available.
                                        </p>
                                    ) : null}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Operations */}
                    <SidebarSectionLabel label="Operations" />
                    {canViewInventory && (
                        <div className="space-y-1">
                            <button
                                type="button"
                                onClick={() => setInventoryExpanded((open) => !open)}
                                className={`
                                    flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition
                                    ${
                                        inventoryActive
                                            ? "bg-white/10 text-white"
                                            : "text-slate-300 hover:bg-white/5 hover:text-white"
                                    }
                                `}
                            >
                                <Boxes className="size-4 shrink-0" />
                                <span className="flex-1">Inventory</span>
                                <ChevronDown
                                    className={`size-4 shrink-0 transition-transform duration-200 ${
                                        inventoryOpen ? "rotate-180" : ""
                                    }`}
                                />
                            </button>

                            {inventoryOpen && (
                                <div className="ml-4 space-y-1 border-l border-white/10 pl-3">
                                    {can("erp.products.view") && (
                                        <SidebarLink
                                            href="/erp/inventory/products"
                                            label="Products"
                                            icon={Package}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    {can("erp.product-categories.view") && (
                                        <SidebarLink
                                            href="/erp/inventory/product-categories"
                                            label="Product Categories"
                                            icon={Layers}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    {can("erp.brands.view") && (
                                        <SidebarLink
                                            href="/erp/inventory/brands"
                                            label="Brands"
                                            icon={Tag}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    {can("erp.units.view") && (
                                        <SidebarLink
                                            href="/erp/inventory/units"
                                            label="Units"
                                            icon={Scale}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                    
                                    {can("erp.warehouses.view") && (
                                        <SidebarLink
                                            href="/erp/inventory/warehouses"
                                            label="Warehouses"
                                            icon={Warehouse}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can(
                                        "erp.inventory-stock.view"
                                    ) && (
                                        <SidebarLink
                                            href="/erp/inventory/stock"
                                            label="Warehouse Stock"
                                            icon={Boxes}
                                            currentUrl={url}
                                            exact
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can(
                                        "erp.stock-movements.view"
                                    ) && (
                                        <SidebarLink
                                            href="/erp/inventory/stock-movements"
                                            label="Stock Movements"
                                            icon={BookOpen}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.opening-stocks.view") && (
                                        <SidebarLink
                                            href="/erp/inventory/opening-stocks"
                                            label="Opening Stock"
                                            icon={ClipboardList}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {/* Low-Stock Report: reorder settings are edited inside this page. */}
                                    {can("erp.low-stock-reports.view") && (
                                        <SidebarLink
                                            href="/erp/inventory/low-stock"
                                            label="Low-Stock Report"
                                            icon={AlertTriangle}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}

                                    {can("erp.stock-transfers.view") && (
                                        <SidebarLink
                                            href="/erp/inventory/stock-transfers"
                                            label="Stock Transfers"
                                            icon={ArrowLeftRight}
                                            currentUrl={url}
                                            onClick={closeMobile}
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                    <FutureSidebarItem label="Approvals" icon={ClipboardCheck} />

                    {accountingEntry && (
                        <div>
                            <SidebarSectionLabel label="Finance" />
                            <SidebarLink href={accountingEntry.href} label="Accounting" icon={Calculator} currentUrl={url} onClick={closeMobile} />
                        </div>
                    )}

                    {/* Intelligence */}
                    <SidebarSectionLabel label="Intelligence" />
                    <FutureSidebarItem label="Reports" icon={FileBarChart} />
                    <FutureSidebarItem label="Analytics" icon={BarChart3} />
                </div>

                {/* Footer / User & Logout */}
                <div className="border-t border-slate-800 p-3">
                     <Link
                        href="/admin/dashboard"
                        onClick={
                            closeMobile
                        }
                        className="
                            mb-1 flex w-full
                            items-center gap-3
                            rounded-lg px-3 py-2.5
                            text-left text-sm
                            font-medium text-slate-400
                            transition
                            hover:bg-slate-900
                            hover:text-white
                        "
                    >
                        <PackageSearch className="size-5" />
                        <span>CMS Administration</span>

                    </Link>
                    <div className="flex items-center justify-between rounded-lg bg-slate-900/60 p-2">
                        <div className="flex items-center gap-3 overflow-hidden">
                            <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-slate-800 font-semibold text-slate-200">
                                {user?.name?.charAt(0) || "U"}
                            </div>
                            <div className="truncate">
                                <div className="truncate text-xs font-medium text-white">
                                    {user?.name || "User"}
                                </div>
                                <div className="truncate text-[10px] text-slate-500">
                                    {user?.email || "user@example.com"}
                                </div>
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={handleLogout}
                            title="Log Out"
                            className="rounded-lg p-2 text-slate-400 transition hover:bg-slate-800 hover:text-white"
                        >
                            <LogOut className="size-4" />
                        </button>
                    </div>
                </div>

                
                
            </aside>
        </>
    );
}

/* Helper Components */

type SidebarLinkProps = {
    href: string;
    label: string;
    icon: LucideIcon;
    currentUrl: string;
    exact?: boolean;
    excludePrefix?: string;
    onClick?: () => void;
};

function SidebarLink({
    href,
    label,
    icon: Icon,
    currentUrl,
    exact = false,
    excludePrefix,
    onClick,
}: SidebarLinkProps) {
    const isActive = sidebarLinkIsActive(currentUrl, href, exact, excludePrefix);

    return (
        <Link
            href={href}
            aria-current={isActive ? "page" : undefined}
            onClick={onClick}
            className={`
                flex items-center gap-3 rounded-lg px-3 py-2 text-xs font-medium transition
                ${
                    isActive
                        ? "bg-indigo-600 text-white shadow-md shadow-indigo-600/20"
                        : "text-slate-400 hover:bg-white/5 hover:text-white"
                }
            `}
        >
            <Icon className="size-4 shrink-0" />
            <span>{label}</span>
        </Link>
    );
}

type SidebarSectionLabelProps = {
    label: string;
};

function SidebarSectionLabel({ label }: SidebarSectionLabelProps) {
    return (
        <div className="pt-2">
            <div className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-600">
                {label}
            </div>
        </div>
    );
}

type FutureSidebarItemProps = {
    label: string;
    icon: LucideIcon;
};

function FutureSidebarItem({ label, icon: Icon }: FutureSidebarItemProps) {
    return (
        <div className="flex items-center justify-between rounded-lg px-3 py-2 text-xs font-medium text-slate-500 opacity-60">
            <div className="flex items-center gap-3">
                <Icon className="size-4 shrink-0" />
                <span>{label}</span>
            </div>
            <span className="rounded border border-slate-800 bg-slate-900 px-1.5 py-0.5 text-[9px] font-semibold text-slate-500">
                Soon
            </span>
        </div>
    );
}