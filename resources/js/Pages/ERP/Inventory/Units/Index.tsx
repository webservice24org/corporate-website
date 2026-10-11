import { Head, router, useForm } from "@inertiajs/react";
import { Pencil, Plus, Search, Trash2, X } from "lucide-react";
import { useState } from "react";
import type { FormEvent, ReactNode } from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";

type Company = { id: number; name: string };
type Unit = { id:number; company_id:number; name:string; short_name:string; decimal_places:number; is_active:boolean; company?:{id:number;name:string}|null };
type PaginationLink = { url:string|null; label:string; active:boolean };
type UnitFormData = { company_id:number|""; name:string; short_name:string; decimal_places:number; is_active:boolean };

interface Props {
  units:{ data:Unit[]; links:PaginationLink[]; total?:number };
  companies:Company[];
  filters?:{ search?:string; company_id?:string|number; status?:string };
}

export default function Index({ units, companies, filters }: Props) {
  const { can } = useAuthorization();
  const [editingUnit,setEditingUnit]=useState<Unit|null>(null);
  const [deletingUnit,setDeletingUnit]=useState<Unit|null>(null);
  const [isDeleting,setIsDeleting]=useState(false);
  const [search,setSearch]=useState(filters?.search ?? "");
  const [companyFilter,setCompanyFilter]=useState(filters?.company_id != null ? String(filters.company_id) : "");
  const [statusFilter,setStatusFilter]=useState(filters?.status ?? "");
  const form=useForm<UnitFormData>({ company_id:"", name:"", short_name:"", decimal_places:2, is_active:true });

  const resetForm=()=>{ setEditingUnit(null); form.reset(); form.clearErrors(); };
  const startEdit=(unit:Unit)=>{ setEditingUnit(unit); form.clearErrors(); form.setData({ company_id:unit.company_id, name:unit.name, short_name:unit.short_name, decimal_places:unit.decimal_places, is_active:unit.is_active }); };
  const submit=(e:FormEvent<HTMLFormElement>)=>{ e.preventDefault(); if(editingUnit){ form.put(`/erp/inventory/units/${editingUnit.id}`,{preserveScroll:true,onSuccess:resetForm}); return; } form.post('/erp/inventory/units',{preserveScroll:true,onSuccess:resetForm}); };
  const confirmDelete=()=>{ if(!deletingUnit)return; setIsDeleting(true); router.delete(`/erp/inventory/units/${deletingUnit.id}`,{preserveScroll:true,onFinish:()=>{setIsDeleting(false);setDeletingUnit(null);}}); };
  const applyFilters=()=>router.get('/erp/inventory/units',{search:search||undefined,company_id:companyFilter||undefined,status:statusFilter||undefined},{preserveState:true,preserveScroll:true,replace:true});
  const resetFilters=()=>{ setSearch(''); setCompanyFilter(''); setStatusFilter(''); router.get('/erp/inventory/units',{}, {preserveState:true,preserveScroll:true,replace:true}); };

  return <ERPLayout>
    <Head title="Units" />
    <div className="space-y-6">
      <div><h1 className="text-2xl font-semibold">Units</h1><p className="mt-1 text-sm text-slate-500">Manage product measurement units by company.</p></div>
      <div className="grid min-w-0 gap-6 2xl:grid-cols-[minmax(0,1fr)_380px]">
        <section className="min-w-0 space-y-4">
          <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
            <div className="grid gap-3 md:grid-cols-2 2xl:grid-cols-[1fr_220px_160px_auto_auto]">
              <div className="relative"><Search className="absolute left-3 top-3 h-4 w-4 text-slate-400"/><input value={search} onChange={e=>setSearch(e.target.value)} onKeyDown={e=>e.key==='Enter'&&applyFilters()} placeholder="Search unit name or short name" className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-800 dark:bg-slate-950"/></div>
              <select value={companyFilter} onChange={e=>setCompanyFilter(e.target.value)} className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"><option value="">All Companies</option>{companies.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select>
              <select value={statusFilter} onChange={e=>setStatusFilter(e.target.value)} className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"><option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
              <button type="button" onClick={applyFilters} className="h-10 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950">Apply</button>
              <button type="button" onClick={resetFilters} className="h-10 rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800">Reset</button>
            </div>
          </div>
          <div className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950"><div className="w-full overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50"><tr><th className="px-5 py-3">Unit</th><th className="px-5 py-3">Short Name</th><th className="px-5 py-3">Decimal Places</th><th className="px-5 py-3">Company</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{units.data.map(unit=><tr key={unit.id}><td className="px-5 py-4 font-medium">{unit.name}</td><td className="px-5 py-4">{unit.short_name}</td><td className="px-5 py-4">{unit.decimal_places}</td><td className="px-5 py-4">{unit.company?.name ?? '—'}</td><td className="px-5 py-4">{unit.is_active?'Active':'Inactive'}</td><td className="px-5 py-4"><div className="flex justify-end gap-2">{can('erp.units.edit')&&<button type="button" title="Edit Unit" onClick={()=>startEdit(unit)} className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200"><Pencil className="h-4 w-4"/></button>}{can('erp.units.delete')&&<button type="button" title="Delete Unit" onClick={()=>setDeletingUnit(unit)} className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600"><Trash2 className="h-4 w-4"/></button>}</div></td></tr>)}{units.data.length===0&&<tr><td colSpan={6} className="px-5 py-12 text-center text-slate-500">No units found.</td></tr>}</tbody></table></div></div>
          {units.links?.length>3&&<div className="flex flex-wrap gap-2">{units.links.map((link,i)=><button key={`${link.label}-${i}`} type="button" disabled={!link.url} onClick={()=>link.url&&router.visit(link.url,{preserveScroll:true})} className={`h-9 rounded-md border px-3 text-sm ${link.active?'bg-slate-900 text-white dark:bg-white dark:text-slate-950':'border-slate-200 dark:border-slate-800'} disabled:opacity-40`} dangerouslySetInnerHTML={{__html:link.label}} />)}</div>}
        </section>
        <section className="min-w-0 h-fit rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950 2xl:sticky 2xl:top-6">
          <form onSubmit={submit}><div className="flex items-center justify-between gap-4"><div className="flex items-center gap-2"><Plus className="h-4 w-4"/><h2 className="font-semibold">{editingUnit?'Edit Unit':'Add Unit'}</h2></div>{editingUnit&&<button type="button" onClick={resetForm} title="Cancel Edit" className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"><X className="h-4 w-4"/></button>}</div>
          <div className="mt-5 space-y-4"><FieldError error={form.errors.company_id}><select value={form.data.company_id} onChange={e=>form.setData('company_id',e.target.value?Number(e.target.value):'')} className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"><option value="">Select Company</option>{companies.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></FieldError>
          <FieldError error={form.errors.name}><input value={form.data.name} onChange={e=>form.setData('name',e.target.value)} placeholder="Unit Name, e.g. Kilogram" className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"/></FieldError>
          <FieldError error={form.errors.short_name}><input value={form.data.short_name} onChange={e=>form.setData('short_name',e.target.value)} placeholder="Short Name, e.g. kg" className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"/></FieldError>
          <FieldError error={form.errors.decimal_places}><div><label className="mb-1.5 block text-sm font-medium">Decimal Places</label><input type="number" min="0" max="6" value={form.data.decimal_places} onChange={e=>form.setData('decimal_places',Number(e.target.value))} className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"/></div></FieldError>
          <label className="flex items-center gap-2 text-sm font-medium"><input type="checkbox" checked={form.data.is_active} onChange={e=>form.setData('is_active',e.target.checked)} className="h-4 w-4 rounded border-slate-300"/>Active</label>
          {(editingUnit?can('erp.units.edit'):can('erp.units.create'))&&<button type="submit" disabled={form.processing||!form.data.company_id||!form.data.name||!form.data.short_name} className="w-full rounded-md bg-slate-900 py-2.5 text-sm font-medium text-white disabled:opacity-50 dark:bg-slate-50 dark:text-slate-900">{form.processing?'Saving...':editingUnit?'Update Unit':'Create Unit'}</button>}</div></form>
        </section>
      </div>
    </div>
    <DeleteConfirmDialog open={!!deletingUnit} onOpenChange={open=>{if(!open&&!isDeleting)setDeletingUnit(null)}} onConfirm={confirmDelete} title="Delete Unit?" itemName={deletingUnit?.name} processing={isDeleting}/>
  </ERPLayout>;
}

function FieldError({error,children}:{error?:string;children:ReactNode}){return <div className="space-y-1">{children}{error&&<p className="text-xs text-red-500">{error}</p>}</div>}
