import { Head, Link, useForm } from "@inertiajs/react";
import { ArrowLeft } from "lucide-react";
import type { FormEvent } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import OpportunityForm from "./OpportunityForm";
import type {
    OpportunityFormData,
    OpportunityStage,
} from "@/types/opportunity";

type Company={id:number;name:string};
type Lead={id:number;company_id:number;lead_number:string;contact_name:string;company_name:string|null;expected_value:string|number;currency:string;converted_to_type?:string|null;converted_to_id?:number|null};
type Party={id:number;company_id:number;name?:string;business_name?:string|null;customer_code?:string|null;dealer_code?:string|null;seller_code?:string|null};
type User={id:number;name:string;email:string};

type Props={
 companies:Company[];leads:Lead[];customers:Party[];dealers:Party[];sellers:Party[];
 stages:OpportunityStage[];salespeople:User[];selectedLeadId?:number|null;
};

export default function Create(props:Props){
 const selectedLead=props.leads.find(x=>x.id===props.selectedLeadId);
 const convertedType=selectedLead?.converted_to_type??"";
 const inferredPartyType:OpportunityFormData["party_type"]=
  convertedType.endsWith("\\Customer")?"customer":
  convertedType.endsWith("\\Dealer")?"dealer":
  convertedType.endsWith("\\Seller")?"seller":"customer";

 const form=useForm<OpportunityFormData>({
  company_id:selectedLead?.company_id??props.companies[0]?.id??null,
  lead_id:selectedLead?.id??null,
  party_type:inferredPartyType,
  party_id:selectedLead?.converted_to_id??null,
  opportunity_stage_id:null,
  salesperson_user_id:null,
  title:selectedLead?(selectedLead.company_name||selectedLead.contact_name):"",
  description:"",
  expected_value:Number(selectedLead?.expected_value??0),
  currency:selectedLead?.currency??"BDT",
  probability:null,
  expected_close_date:"",
  lost_reason:"",
  is_active:true,
  notes:"",
 });

 const submit=(e:FormEvent<HTMLFormElement>)=>{e.preventDefault();form.post("/erp/crm/opportunities")};

 return <ERPLayout><Head title="Create Opportunity"/><div className="mx-auto max-w-7xl space-y-6">
  <div><Link href="/erp/crm/opportunities" className="inline-flex items-center gap-2 text-sm text-slate-500"><ArrowLeft className="h-4 w-4"/>Opportunities</Link><h1 className="mt-3 text-2xl font-semibold">Create Opportunity</h1><p className="mt-1 text-sm text-slate-500">Create a sales opportunity linked to a Customer, Dealer, or Seller.</p></div>
  <OpportunityForm {...props} data={form.data} setData={form.setData} errors={form.errors} processing={form.processing} submitLabel="Create Opportunity" onSubmit={submit}/>
 </div></ERPLayout>
}
