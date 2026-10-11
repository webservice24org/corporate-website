import { Head, router, useForm } from "@inertiajs/react";
import { Plus, Save, Trash2 } from "lucide-react";
import { useState } from "react";
import type { FormEvent, ReactNode } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

type Company={id:number;name:string};
type Stage={
 id:number;company_id:number;name:string;code:string;probability:number;sort_order:number;
 is_won:boolean;is_lost:boolean;is_active:boolean;company?:Company|null;
};

type FormData={
 company_id:number|null;name:string;code:string;probability:number;sort_order:number;
 is_won:boolean;is_lost:boolean;is_active:boolean;
};

export default function Index({stages,companies}:{stages:Stage[];companies:Company[]}){
 const {can}=useAuthorization();
 const [editing,setEditing]=useState<Stage|null>(null);
 const form=useForm<FormData>({
  company_id:companies[0]?.id??null,name:"",code:"",probability:0,sort_order:0,
  is_won:false,is_lost:false,is_active:true,
 });

 const reset=()=>{setEditing(null);form.reset();form.setData("company_id",companies[0]?.id??null)};
 const edit=(s:Stage)=>{setEditing(s);form.setData({company_id:s.company_id,name:s.name,code:s.code,probability:s.probability,sort_order:s.sort_order,is_won:s.is_won,is_lost:s.is_lost,is_active:s.is_active})};
 const submit=(e:FormEvent)=>{e.preventDefault();if(editing){form.put(`/erp/crm/opportunity-stages/${editing.id}`,{onSuccess:reset})}else{form.post("/erp/crm/opportunity-stages",{onSuccess:reset})}};
 const remove=(s:Stage)=>{if(window.confirm(`Delete stage "${s.name}"?`))router.delete(`/erp/crm/opportunity-stages/${s.id}`)};

 return <ERPLayout><Head title="Opportunity Stages"/><div className="space-y-6">
  <div><h1 className="text-2xl font-semibold">Opportunity Stages</h1><p className="mt-1 text-sm text-slate-500">Configure pipeline stages, default probability and Won/Lost behavior.</p></div>

  {(can("erp.opportunity-stages.create")||editing)&&<form onSubmit={submit} className="rounded-xl border bg-white p-6 dark:bg-slate-950">
   <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
    <Field label="Company"><select className={input} value={form.data.company_id??""} onChange={e=>form.setData("company_id",Number(e.target.value))}>{companies.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
    <Field label="Name"><input className={input} required value={form.data.name} onChange={e=>form.setData("name",e.target.value)}/></Field>
    <Field label="Code"><input className={input} required value={form.data.code} onChange={e=>form.setData("code",e.target.value.toLowerCase().replace(/\s+/g,"_"))}/></Field>
    <Field label="Probability %"><input type="number" min="0" max="100" className={input} value={form.data.probability} onChange={e=>form.setData("probability",Number(e.target.value))}/></Field>
    <Field label="Sort Order"><input type="number" min="0" className={input} value={form.data.sort_order} onChange={e=>form.setData("sort_order",Number(e.target.value))}/></Field>
    <label className="flex items-center gap-2 pt-7 text-sm"><input type="checkbox" checked={form.data.is_won} onChange={e=>form.setData("is_won",e.target.checked)}/> Won stage</label>
    <label className="flex items-center gap-2 pt-7 text-sm"><input type="checkbox" checked={form.data.is_lost} onChange={e=>form.setData("is_lost",e.target.checked)}/> Lost stage</label>
    <label className="flex items-center gap-2 pt-7 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={e=>form.setData("is_active",e.target.checked)}/> Active</label>
   </div>
   <div className="mt-5 flex gap-2"><button disabled={form.processing} className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950">{editing?<Save className="h-4 w-4"/>:<Plus className="h-4 w-4"/>}{editing?"Save Stage":"Add Stage"}</button>{editing&&<button type="button" onClick={reset} className="h-10 rounded-md border px-4 text-sm">Cancel</button>}</div>
  </form>}

  <div className="overflow-hidden rounded-xl border bg-white dark:bg-slate-950"><table className="w-full text-left text-sm"><thead className="border-b bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900/50"><tr><th className="px-4 py-3">Stage</th><th className="px-4 py-3">Company</th><th className="px-4 py-3">Probability</th><th className="px-4 py-3">Type</th><th className="px-4 py-3">Active</th><th className="px-4 py-3 text-right">Actions</th></tr></thead><tbody className="divide-y">
   {stages.map(s=><tr key={s.id}><td className="px-4 py-4"><div className="font-medium">{s.name}</div><div className="text-xs text-slate-500">{s.code} · order {s.sort_order}</div></td><td className="px-4 py-4">{s.company?.name??"—"}</td><td className="px-4 py-4">{s.probability}%</td><td className="px-4 py-4">{s.is_won?"Won":s.is_lost?"Lost":"Open"}</td><td className="px-4 py-4">{s.is_active?"Yes":"No"}</td><td className="px-4 py-4"><div className="flex justify-end gap-2">{can("erp.opportunity-stages.edit")&&<button onClick={()=>edit(s)} className="h-9 rounded-md border px-3 text-sm">Edit</button>}{can("erp.opportunity-stages.delete")&&<button onClick={()=>remove(s)} className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600"><Trash2 className="h-4 w-4"/></button>}</div></td></tr>)}
   {stages.length===0&&<tr><td colSpan={6} className="px-4 py-12 text-center text-slate-500">No stages configured.</td></tr>}
  </tbody></table></div>
 </div></ERPLayout>
}

const input="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950";
function Field({label,children}:{label:string;children:ReactNode}){return <label className="block"><span className="mb-1.5 block text-sm font-medium">{label}</span>{children}</label>}
