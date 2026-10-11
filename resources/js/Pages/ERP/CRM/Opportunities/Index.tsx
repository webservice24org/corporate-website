import { Head, Link, router } from "@inertiajs/react";
import { Eye, FilePenLine, Plus, Search } from "lucide-react";
import { useState } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Opportunity, OpportunityStage } from "@/types/opportunity";

type Props={
 opportunities:{data:Opportunity[];links:{url:string|null;label:string;active:boolean}[];total:number};
 companies:{id:number;name:string}[];
 stages:OpportunityStage[];
 salespeople:{id:number;name:string;email:string}[];
 filters:{search?:string;company_id?:number|string;stage_id?:number|string;salesperson_user_id?:number|string;party_type?:string};
};

function partyLabel(o:Opportunity){return o.party?.business_name||o.party?.name||`#${o.party_id}`}
function money(v:string|number,c:string){return `${Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} ${c}`}

export default function Index({opportunities,companies,stages,salespeople,filters}:Props){
 const {can}=useAuthorization();
 const [search,setSearch]=useState(filters.search??"");
 const [company,setCompany]=useState(filters.company_id?String(filters.company_id):"");
 const [stage,setStage]=useState(filters.stage_id?String(filters.stage_id):"");
 const [salesperson,setSalesperson]=useState(filters.salesperson_user_id?String(filters.salesperson_user_id):"");
 const [partyType,setPartyType]=useState(filters.party_type??"");

 const apply=()=>router.get("/erp/crm/opportunities",{search:search||undefined,company_id:company||undefined,stage_id:stage||undefined,salesperson_user_id:salesperson||undefined,party_type:partyType||undefined},{preserveState:true,preserveScroll:true,replace:true});

 return <ERPLayout><Head title="Opportunities"/><div className="space-y-6">
  <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"><div><h1 className="text-2xl font-semibold">Opportunities</h1><p className="mt-1 text-sm text-slate-500">Manage pipeline value, probability, close dates and sales ownership.</p></div>{can("erp.opportunities.create")&&<Link href="/erp/crm/opportunities/create" className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"><Plus className="h-4 w-4"/>New Opportunity</Link>}</div>
  <div className="grid gap-3 rounded-xl border p-4 md:grid-cols-2 xl:grid-cols-5">
   <div className="relative"><Search className="absolute left-3 top-3 h-4 w-4 text-slate-400"/><input className="h-10 w-full rounded-md border pl-9 pr-3 text-sm dark:bg-slate-950" value={search} onChange={e=>setSearch(e.target.value)} placeholder="Opportunity..."/></div>
   <select className="h-10 rounded-md border px-3 text-sm dark:bg-slate-950" value={company} onChange={e=>setCompany(e.target.value)}><option value="">All Companies</option>{companies.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select>
   <select className="h-10 rounded-md border px-3 text-sm dark:bg-slate-950" value={stage} onChange={e=>setStage(e.target.value)}><option value="">All Stages</option>{stages.filter(x=>!company||String(x.company_id)===company).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select>
   <select className="h-10 rounded-md border px-3 text-sm dark:bg-slate-950" value={salesperson} onChange={e=>setSalesperson(e.target.value)}><option value="">All Salespeople</option>{salespeople.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select>
   <div className="flex gap-2"><select className="h-10 min-w-0 flex-1 rounded-md border px-3 text-sm dark:bg-slate-950" value={partyType} onChange={e=>setPartyType(e.target.value)}><option value="">All Parties</option><option value="customer">Customer</option><option value="dealer">Dealer</option><option value="seller">Seller</option></select><button onClick={apply} className="h-10 rounded-md bg-slate-900 px-4 text-sm text-white dark:bg-white dark:text-slate-950">Filter</button></div>
  </div>
  <div className="overflow-hidden rounded-xl border bg-white dark:bg-slate-950"><div className="overflow-x-auto"><table className="w-full min-w-[1100px] text-left text-sm"><thead className="border-b bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900/50"><tr><th className="px-4 py-3">Opportunity</th><th className="px-4 py-3">Party</th><th className="px-4 py-3">Stage</th><th className="px-4 py-3">Value</th><th className="px-4 py-3">Probability</th><th className="px-4 py-3">Expected Close</th><th className="px-4 py-3">Salesperson</th><th className="px-4 py-3 text-right">Actions</th></tr></thead>
   <tbody className="divide-y">{opportunities.data.map(o=><tr key={o.id}><td className="px-4 py-4"><Link href={`/erp/crm/opportunities/${o.id}`} className="font-medium hover:underline">{o.title}</Link><div className="mt-1 text-xs text-slate-500">{o.opportunity_number??`Opportunity #${o.id}`}{o.lead?` · ${o.lead.lead_number}`:""}</div></td><td className="px-4 py-4">{partyLabel(o)}</td><td className="px-4 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${o.stage?.is_won?"bg-emerald-50 text-emerald-700":o.stage?.is_lost?"bg-red-50 text-red-700":"bg-blue-50 text-blue-700"}`}>{o.stage?.name??"—"}</span></td><td className="px-4 py-4 font-medium">{money(o.expected_value,o.currency)}</td><td className="px-4 py-4">{o.probability}%</td><td className="px-4 py-4">{o.expected_close_date?new Date(o.expected_close_date).toLocaleDateString():"—"}</td><td className="px-4 py-4">{o.salesperson?.name??"Unassigned"}</td><td className="px-4 py-4"><div className="flex justify-end gap-2">{can("erp.opportunities.view")&&<Link href={`/erp/crm/opportunities/${o.id}`} className="inline-flex h-9 w-9 items-center justify-center rounded-md border"><Eye className="h-4 w-4"/></Link>}{can("erp.opportunities.edit")&&<Link href={`/erp/crm/opportunities/${o.id}/edit`} className="inline-flex h-9 w-9 items-center justify-center rounded-md border"><FilePenLine className="h-4 w-4"/></Link>}</div></td></tr>)}
   {opportunities.data.length===0&&<tr><td colSpan={8} className="px-4 py-16 text-center text-slate-500">No opportunities found.</td></tr>}</tbody></table></div>
   {opportunities.links.length>3&&<div className="flex flex-wrap gap-1 border-t p-4">{opportunities.links.map((link,i)=><button key={i} disabled={!link.url} onClick={()=>link.url&&router.visit(link.url,{preserveScroll:true})} dangerouslySetInnerHTML={{__html:link.label}} className={`min-w-9 rounded-md px-3 py-2 text-sm ${link.active?"bg-slate-900 text-white":"border"} disabled:opacity-40`}/>)}</div>}
  </div>
 </div></ERPLayout>
}
