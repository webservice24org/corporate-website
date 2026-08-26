import * as React from "react";

import {
    type ColumnDef,
    type ColumnFiltersState,
    type SortingState,
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useReactTable,
} from "@tanstack/react-table";

import { Search, X } from "lucide-react";

import { Button } from "@/Components/ui/button";

interface DataTableProps<TData, TValue> {
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
}

export function DataTable<TData, TValue>({
    columns,
    data,
}: DataTableProps<TData, TValue>) {
    const [sorting, setSorting] =
        React.useState<SortingState>([]);

    const [columnFilters, setColumnFilters] =
        React.useState<ColumnFiltersState>([]);

    const table = useReactTable({
        data,
        columns,

        state: {
            sorting,
            columnFilters,
        },

        onSortingChange: setSorting,
        onColumnFiltersChange: setColumnFilters,

        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        getPaginationRowModel: getPaginationRowModel(),

        initialState: {
            pagination: {
                pageSize: 10,
            },
        },
    });

    const titleFilter =
        (table
            .getColumn("title")
            ?.getFilterValue() as string) ?? "";

    const statusFilter =
        (table
            .getColumn("status")
            ?.getFilterValue() as string) ?? "";

    const resetFilters = () => {
        table.resetColumnFilters();
    };

    const hasFilters =
        titleFilter.length > 0 ||
        statusFilter.length > 0;

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex flex-1 flex-col gap-2 sm:flex-row">
                    <div className="relative w-full max-w-sm">
                        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />

                        <input
                            type="text"
                            placeholder="Search pages..."
                            value={titleFilter}
                            onChange={(event) =>
                                table
                                    .getColumn("title")
                                    ?.setFilterValue(
                                        event.target.value,
                                    )
                            }
                            className="h-9 w-full rounded-lg border border-input bg-background pl-9 pr-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-3 focus:ring-ring/20"
                        />
                    </div>

                    <select
                        value={statusFilter}
                        onChange={(event) =>
                            table
                                .getColumn("status")
                                ?.setFilterValue(
                                    event.target.value ||
                                        undefined,
                                )
                        }
                        className="h-9 rounded-lg border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                    >
                        <option value="">
                            All Statuses
                        </option>

                        <option value="published">
                            Published
                        </option>

                        <option value="draft">
                            Draft
                        </option>
                    </select>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onPress={resetFilters}
                        >
                            <X />
                            Reset
                        </Button>
                    )}
                </div>

                <div className="text-sm text-muted-foreground">
                    {table.getFilteredRowModel().rows.length}{" "}
                    page(s)
                </div>
            </div>

            <div className="overflow-hidden rounded-lg border border-border bg-background">
                <div className="overflow-x-auto">
                    <table className="w-full caption-bottom text-sm">
                        <thead className="bg-muted/40 [&_tr]:border-b">
                            {table
                                .getHeaderGroups()
                                .map((headerGroup) => (
                                    <tr key={headerGroup.id}>
                                        {headerGroup.headers.map(
                                            (header) => (
                                                <th
                                                    key={header.id}
                                                    className="h-11 px-3 text-left align-middle font-medium whitespace-nowrap text-foreground"
                                                >
                                                    {header.isPlaceholder
                                                        ? null
                                                        : flexRender(
                                                              header
                                                                  .column
                                                                  .columnDef
                                                                  .header,
                                                              header.getContext(),
                                                          )}
                                                </th>
                                            ),
                                        )}
                                    </tr>
                                ))}
                        </thead>

                        <tbody>
                            {table.getRowModel().rows.length ? (
                                table
                                    .getRowModel()
                                    .rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b transition-colors last:border-0 hover:bg-muted/40"
                                        >
                                            {row
                                                .getVisibleCells()
                                                .map((cell) => (
                                                    <td
                                                        key={cell.id}
                                                        className="p-3 align-middle whitespace-nowrap"
                                                    >
                                                        {flexRender(
                                                            cell
                                                                .column
                                                                .columnDef
                                                                .cell,
                                                            cell.getContext(),
                                                        )}
                                                    </td>
                                                ))}
                                        </tr>
                                    ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={columns.length}
                                        className="h-32 text-center text-muted-foreground"
                                    >
                                        No pages found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2">
                    <span className="text-sm text-muted-foreground">
                        Rows per page
                    </span>

                    <select
                        value={
                            table.getState().pagination.pageSize
                        }
                        onChange={(event) =>
                            table.setPageSize(
                                Number(event.target.value),
                            )
                        }
                        className="h-8 rounded-lg border border-input bg-background px-2 text-sm outline-none"
                    >
                        {[10, 20, 50, 100].map(
                            (pageSize) => (
                                <option
                                    key={pageSize}
                                    value={pageSize}
                                >
                                    {pageSize}
                                </option>
                            ),
                        )}
                    </select>
                </div>

                <div className="flex items-center gap-4">
                    <div className="text-sm text-muted-foreground">
                        Page{" "}
                        {table.getState().pagination
                            .pageIndex + 1}{" "}
                        of{" "}
                        {Math.max(
                            table.getPageCount(),
                            1,
                        )}
                    </div>

                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onPress={() =>
                                table.previousPage()
                            }
                            isDisabled={
                                !table.getCanPreviousPage()
                            }
                        >
                            Previous
                        </Button>

                        <Button
                            variant="outline"
                            size="sm"
                            onPress={() =>
                                table.nextPage()
                            }
                            isDisabled={
                                !table.getCanNextPage()
                            }
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}