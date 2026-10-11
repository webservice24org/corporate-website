import { Head, Link, useForm } from "@inertiajs/react";
import { ArrowLeft } from "lucide-react";
import type { FormEvent } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import OpportunityForm from "./OpportunityForm";
import type {
 Opportunity,OpportunityFormData,OpportunityStage
} from "@/types/opportunity";

type Company={id:number;name:string};
type Lead={id:number;company_id:number;lead_number:string;contact_name:string;company_name:string|null;expected_value:string|number;currency:string;converted_to_type?:string|null;converted_to_id?:number|null};
type Party={id:number;company_id:number;name?:string;business_name?:string|null;customer_code?:string|null;dealer_code?:string|null;seller_code?:string|null};
type User={id:number;name:string;email:string};

type Props={opportunity:Opportunity;companies:Company[];leads:Lead[];customers:Party[];dealers:Party[];sellers:Party[];stages:OpportunityStage[];salespeople:User[]};

function partyType(type:string):OpportunityFormData["party_type"]{
 if(type.endsWith("\\Dealer")) return "dealer";
 if(type.endsWith("\\Seller")) return "seller";
 return "customer";
}

export default function Edit(props:Props){
 const o=props.opportunity;
 const form=useForm<OpportunityFormData>({
  company_id:o.company_id,lead_id:o.lead_id,party_type:partyType(o.party_type),party_id:o.party_id,
  opportunity_stage_id:o.opportunity_stage_id,salesperson_user_id:o.salesperson_user_id,title:o.title,
  description:o.description??"",expected_value:Number(o.expected_value),currency:o.currency,probability:o.probability,
  expected_close_date:o.expected_close_date?.slice(0,10)??"",lost_reason:o.lost_reason??"",is_active:o.is_active,notes:o.notes??"",
 });
 const submit=(e:FormEvent<HTMLFormElement>)=>{e.preventDefault();form.put(`/erp/crm/opportunities/${o.id}`)};
 return <ERPLayout><Head title="Edit Opportunity"/><div className="mx-auto max-w-7xl space-y-6">
  <div><Link href={`/erp/crm/opportunities/${o.id}`} className="inline-flex items-center gap-2 text-sm text-slate-500"><ArrowLeft className="h-4 w-4"/>Back</Link><h1 className="mt-3 text-2xl font-semibold">Edit Opportunity</h1><p className="mt-1 text-sm text-slate-500">{o.title}</p></div>
  <OpportunityForm {...props} data={form.data} setData={form.setData} errors={form.errors} processing={form.processing} submitLabel="Save Changes" onSubmit={submit}/>
 </div></ERPLayout>
}
