import type { FormEvent, ReactNode } from "react";
import type {
    OpportunityFormData,
    OpportunityStage,
} from "@/types/opportunity";

type Company={id:number;name:string};
type Lead={id:number;company_id:number;lead_number:string;contact_name:string;company_name:string|null;expected_value:string|number;currency:string;converted_to_type?:string|null;converted_to_id?:number|null};
type Party={id:number;company_id:number;name?:string;business_name?:string|null;customer_code?:string|null;dealer_code?:string|null;seller_code?:string|null};
type User={id:number;name:string;email:string};

type Props={
 data:OpportunityFormData;
 setData:<K extends keyof OpportunityFormData>(key:K,value:OpportunityFormData[K])=>void;
 companies:Company[];
 leads:Lead[];
 customers:Party[];
 dealers:Party[];
 sellers:Party[];
 stages:OpportunityStage[];
 salespeople:User[];
 errors:Partial<Record<keyof OpportunityFormData,string>>;
 processing:boolean;
 submitLabel:string;
 onSubmit:(event:FormEvent<HTMLFormElement>)=>void;
};

const input="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950";
const textarea="min-h-24 w-full rounded-md border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-950";

export default function OpportunityForm({data,setData,companies,leads,customers,dealers,sellers,stages,salespeople,errors,processing,submitLabel,onSubmit}:Props){
 const companyStages=stages.filter(x=>!data.company_id||x.company_id===data.company_id);
 const companyLeads=leads.filter(x=>!data.company_id||x.company_id===data.company_id);
 const parties=(data.party_type==="customer"?customers:data.party_type==="dealer"?dealers:sellers).filter(x=>!data.company_id||x.company_id===data.company_id);

 const setStage=(value:string)=>{
  const id=value?Number(value):null;
  setData("opportunity_stage_id",id);
  const stage=stages.find(x=>x.id===id);
  if(stage){setData("probability",stage.probability);}
 };

 const setLead=(value:string)=>{
  const id=value?Number(value):null;
  setData("lead_id",id);
  const lead=leads.find(x=>x.id===id);
  if(lead){
   if(!data.company_id)setData("company_id",lead.company_id);
   if(!data.title)setData("title",lead.company_name||lead.contact_name);
   if(!data.expected_value)setData("expected_value",Number(lead.expected_value||0));
   if(data.currency==="BDT"&&lead.currency)setData("currency",lead.currency);
  }
 };

 return <form onSubmit={onSubmit} className="space-y-6">
  <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
   <h2 className="mb-5 font-semibold">Opportunity Information</h2>
   <div className="grid gap-4 md:grid-cols-2">
    <Field label="Company" error={errors.company_id}><select className={input} value={data.company_id??""} onChange={e=>{setData("company_id",e.target.value?Number(e.target.value):null);setData("party_id",null);setData("opportunity_stage_id",null)}} required><option value="">Select Company</option>{companies.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
    <Field label="Source Lead" error={errors.lead_id}><select className={input} value={data.lead_id??""} onChange={e=>setLead(e.target.value)}><option value="">No source lead</option>{companyLeads.map(x=><option key={x.id} value={x.id}>{x.lead_number} · {x.company_name||x.contact_name}</option>)}</select></Field>
    <Field label="Title" error={errors.title}><input className={input} value={data.title} onChange={e=>setData("title",e.target.value)} required/></Field>
    <Field label="Stage" error={errors.opportunity_stage_id}><select className={input} value={data.opportunity_stage_id??""} onChange={e=>setStage(e.target.value)} required><option value="">Select Stage</option>{companyStages.map(x=><option key={x.id} value={x.id}>{x.name} · {x.probability}%</option>)}</select></Field>
    <Field label="Salesperson" error={errors.salesperson_user_id}><select className={input} value={data.salesperson_user_id??""} onChange={e=>setData("salesperson_user_id",e.target.value?Number(e.target.value):null)}><option value="">Unassigned</option>{salespeople.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
    <Field label="Expected Close Date" error={errors.expected_close_date}><input type="date" className={input} value={data.expected_close_date} onChange={e=>setData("expected_close_date",e.target.value)}/></Field>
   </div>
   <div className="mt-4"><Field label="Description" error={errors.description}><textarea className={textarea} value={data.description} onChange={e=>setData("description",e.target.value)}/></Field></div>
  </section>

  <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
   <h2 className="mb-5 font-semibold">Commercial Party</h2>
   <div className="grid gap-4 md:grid-cols-2">
    <Field label="Party Type" error={errors.party_type}><select className={input} value={data.party_type} onChange={e=>{setData("party_type",e.target.value as OpportunityFormData["party_type"]);setData("party_id",null)}}><option value="customer">General Customer</option><option value="dealer">Dealer</option><option value="seller">Seller / Retailer</option></select></Field>
    <Field label="Party" error={errors.party_id}><select className={input} value={data.party_id??""} onChange={e=>setData("party_id",e.target.value?Number(e.target.value):null)} required><option value="">Select Party</option>{parties.map(x=><option key={x.id} value={x.id}>{x.business_name||x.name||`#${x.id}`}</option>)}</select></Field>
   </div>
  </section>

  <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
   <h2 className="mb-5 font-semibold">Value & Probability</h2>
   <div className="grid gap-4 md:grid-cols-3">
    <Field label="Expected Value" error={errors.expected_value}><input type="number" min="0" step="0.01" className={input} value={data.expected_value} onChange={e=>setData("expected_value",Number(e.target.value))}/></Field>
    <Field label="Currency" error={errors.currency}><input className={input} value={data.currency} onChange={e=>setData("currency",e.target.value.toUpperCase())}/></Field>
    <Field label="Probability %" error={errors.probability}><input type="number" min="0" max="100" className={input} value={data.probability??""} onChange={e=>setData("probability",e.target.value===""?null:Number(e.target.value))}/></Field>
   </div>
   <div className="mt-4"><Field label="Lost Reason" error={errors.lost_reason}><textarea className={textarea} value={data.lost_reason} onChange={e=>setData("lost_reason",e.target.value)} placeholder="Used when moved to a Lost stage."/></Field></div>
   <div className="mt-4"><Field label="Notes" error={errors.notes}><textarea className={textarea} value={data.notes} onChange={e=>setData("notes",e.target.value)}/></Field></div>
   <label className="mt-4 flex items-center gap-2 text-sm"><input type="checkbox" checked={data.is_active} onChange={e=>setData("is_active",e.target.checked)}/> Active</label>
  </section>

  <div className="flex justify-end"><button type="submit" disabled={processing} className="h-10 rounded-md bg-slate-900 px-5 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-950">{processing?"Saving...":submitLabel}</button></div>
 </form>
}

function Field({label,error,children}:{label:string;error?:string;children:ReactNode}){
 return <label className="block"><span className="mb-1.5 block text-sm font-medium">{label}</span>{children}{error&&<span className="mt-1 block text-xs text-red-600">{error}</span>}</label>
}
