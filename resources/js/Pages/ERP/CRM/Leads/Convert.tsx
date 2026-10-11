import { Head, Link, useForm } from "@inertiajs/react";
import { ArrowLeft, ArrowRightLeft } from "lucide-react";
import type { FormEvent, ReactNode } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import type { LeadConversionSummary } from "@/types/lead";
type Company={id:number;name:string};
type Depot={id:number;company_id:number;district_id:number;name:string;depot_code?:string|null;district?:{id:number;division_id:number;name:string}|null};
type Dealer={id:number;company_id:number;depot_id:number;business_name:string;dealer_code?:string|null;depot?:{id:number;name:string}|null};
type Division={id:number;name:string};
type District={id:number;division_id:number;name:string};
type Upazila={id:number;district_id:number;name:string};
type Manager={id:number;name:string;email?:string|null};

type Props={
 lead:LeadConversionSummary;companies:Company[];depots:Depot[];dealers:Dealer[];
 divisions:Division[];districts:District[];upazilas:Upazila[];managers:Manager[];
};

type Data={
 target_type:"customer"|"dealer"|"seller";
 company_id:number|null; customer_type:"individual"|"company"; name:string; contact_person:string;
 depot_id:number|null; upazila_id:number|null; dealer_id:number|null; seller_type:"retailer"|"general_seller";
 business_name:string; proprietor_name:string; phone:string; alternate_phone:string; email:string;
 trade_license_number:string; nid_number:string; tin_number:string; vat_number:string;
 credit_limit:number; payment_terms_days:number; manager_user_id:number|null;
 division_id:number|null; district_id:number|null; address_type:"billing"|"shipping"|"other";
 address:string; postal_code:string; notes:string; conversion_notes:string; conversion?: string;
};

const input="h-10 w-full rounded-md border border-neutral-200 bg-white px-3 text-sm dark:border-neutral-800 dark:bg-neutral-950";
const textarea="min-h-24 w-full rounded-md border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-950";

export default function Convert({lead,companies,depots,dealers,divisions,districts,upazilas,managers}:Props){
 const form=useForm<Data>({
  target_type:"customer",company_id:companies[0]?.id??null,customer_type:"individual",
  name:lead.name,contact_person:"",depot_id:null,upazila_id:null,dealer_id:null,seller_type:"retailer",
  business_name:lead.business_name,proprietor_name:lead.name,phone:lead.phone,alternate_phone:"",
  email:lead.email,trade_license_number:"",nid_number:"",tin_number:"",vat_number:"",
  credit_limit:0,payment_terms_days:0,manager_user_id:null,division_id:null,district_id:null,
  address_type:"shipping",address:lead.address,postal_code:"",notes:"",conversion_notes:"",
 });

 const selectedDepot=depots.find(x=>x.id===form.data.depot_id);
 const customerDistricts=districts.filter(x=>!form.data.division_id||x.division_id===form.data.division_id);
 const customerUpazilas=upazilas.filter(x=>!form.data.district_id||x.district_id===form.data.district_id);
 const dealerUpazilas=upazilas.filter(x=>!selectedDepot||x.district_id===selectedDepot.district_id);

 const submit=(e:FormEvent)=>{e.preventDefault();form.post(`/erp/crm/leads/${lead.id}/convert`,{preserveScroll:true})};

 return <ERPLayout>
  <Head title={`Convert Lead #${lead.id}`}/>
  <div className="space-y-6">
   <div>
    <Link href={`/erp/crm/leads/${lead.id}`} className="inline-flex items-center gap-2 text-sm text-neutral-500"><ArrowLeft className="h-4 w-4"/>Back to Lead</Link>
    <h1 className="mt-3 text-2xl font-semibold">Convert Lead</h1>
    <p className="mt-1 text-sm text-neutral-500">Create one approved commercial master from this lead. Conversion is one-time and traceable.</p>
   </div>

   <form onSubmit={submit} className="space-y-6">
    <Card title="Conversion Target">
     <div className="grid gap-4 md:grid-cols-3">
      {(["customer","dealer","seller"] as const).map(type=><button key={type} type="button" onClick={()=>form.setData("target_type",type)} className={`rounded-lg border p-4 text-left ${form.data.target_type===type?"border-neutral-950 ring-1 ring-neutral-950 dark:border-white dark:ring-white":"border-neutral-200 dark:border-neutral-800"}`}><div className="font-medium capitalize">{type==="customer"?"General Customer":type}</div><div className="mt-1 text-xs text-neutral-500">{type==="customer"?"Individual or company customer":type==="dealer"?"Approved Dealer under a Depot":"Retailer / General Seller under Dealer"}</div></button>)}
     </div>
    </Card>

    {form.data.target_type==="customer"&&<Card title="Customer Setup"><div className="grid gap-4 md:grid-cols-2">
     <Field label="Company"><select className={input} value={form.data.company_id??""} onChange={e=>form.setData("company_id",Number(e.target.value))}>{companies.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
     <Field label="Customer Type"><select className={input} value={form.data.customer_type} onChange={e=>form.setData("customer_type",e.target.value as Data["customer_type"])}><option value="individual">Individual</option><option value="company">Company</option></select></Field>
     <Field label="Customer Name"><input className={input} value={form.data.name} onChange={e=>form.setData("name",e.target.value)} required/></Field>
     <Field label="Business Name"><input className={input} value={form.data.business_name} onChange={e=>form.setData("business_name",e.target.value)}/></Field>
     <Field label="Contact Person"><input className={input} value={form.data.contact_person} onChange={e=>form.setData("contact_person",e.target.value)}/></Field>
     <Field label="Phone"><input className={input} value={form.data.phone} onChange={e=>form.setData("phone",e.target.value)} required/></Field>
     <Field label="Email"><input type="email" className={input} value={form.data.email} onChange={e=>form.setData("email",e.target.value)}/></Field>
     <Field label="Division"><select className={input} value={form.data.division_id??""} onChange={e=>{form.setData("division_id",e.target.value?Number(e.target.value):null);form.setData("district_id",null);form.setData("upazila_id",null)}}><option value="">Select</option>{divisions.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
     <Field label="District"><select className={input} value={form.data.district_id??""} onChange={e=>{form.setData("district_id",e.target.value?Number(e.target.value):null);form.setData("upazila_id",null)}}><option value="">Select</option>{customerDistricts.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
     <Field label="Upazila"><select className={input} value={form.data.upazila_id??""} onChange={e=>form.setData("upazila_id",e.target.value?Number(e.target.value):null)}><option value="">Select</option>{customerUpazilas.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
    </div></Card>}

    {form.data.target_type==="dealer"&&<Card title="Dealer Setup"><div className="grid gap-4 md:grid-cols-2">
     <Field label="Depot"><select required className={input} value={form.data.depot_id??""} onChange={e=>{form.setData("depot_id",Number(e.target.value));form.setData("upazila_id",null)}}><option value="">Select Depot</option>{depots.map(x=><option key={x.id} value={x.id}>{x.name}{x.depot_code?` · ${x.depot_code}`:""}</option>)}</select></Field>
     <Field label="Upazila"><select required className={input} value={form.data.upazila_id??""} onChange={e=>form.setData("upazila_id",Number(e.target.value))}><option value="">Select Upazila</option>{dealerUpazilas.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
     <Field label="Business Name"><input required className={input} value={form.data.business_name} onChange={e=>form.setData("business_name",e.target.value)}/></Field>
     <Field label="Proprietor"><input required className={input} value={form.data.proprietor_name} onChange={e=>form.setData("proprietor_name",e.target.value)}/></Field>
    </div></Card>}

    {form.data.target_type==="seller"&&<Card title="Seller / Retailer Setup"><div className="grid gap-4 md:grid-cols-2">
     <Field label="Dealer"><select required className={input} value={form.data.dealer_id??""} onChange={e=>form.setData("dealer_id",Number(e.target.value))}><option value="">Select Dealer</option>{dealers.map(x=><option key={x.id} value={x.id}>{x.business_name}{x.depot?.name?` · ${x.depot.name}`:""}</option>)}</select></Field>
     <Field label="Seller Type"><select className={input} value={form.data.seller_type} onChange={e=>form.setData("seller_type",e.target.value as Data["seller_type"])}><option value="retailer">Retailer</option><option value="general_seller">General Seller</option></select></Field>
     <Field label="Business Name"><input required className={input} value={form.data.business_name} onChange={e=>form.setData("business_name",e.target.value)}/></Field>
     <Field label="Proprietor"><input required className={input} value={form.data.proprietor_name} onChange={e=>form.setData("proprietor_name",e.target.value)}/></Field>
    </div></Card>}

    {(form.data.target_type==="dealer"||form.data.target_type==="seller")&&<Card title="Contact, Legal & Commercial"><div className="grid gap-4 md:grid-cols-2">
     <Field label="Phone"><input required className={input} value={form.data.phone} onChange={e=>form.setData("phone",e.target.value)}/></Field>
     <Field label="Alternate Phone"><input className={input} value={form.data.alternate_phone} onChange={e=>form.setData("alternate_phone",e.target.value)}/></Field>
     <Field label="Email"><input type="email" className={input} value={form.data.email} onChange={e=>form.setData("email",e.target.value)}/></Field>
     <Field label="Trade License"><input className={input} value={form.data.trade_license_number} onChange={e=>form.setData("trade_license_number",e.target.value)}/></Field>
     <Field label="NID"><input className={input} value={form.data.nid_number} onChange={e=>form.setData("nid_number",e.target.value)}/></Field>
     <Field label="TIN"><input className={input} value={form.data.tin_number} onChange={e=>form.setData("tin_number",e.target.value)}/></Field>
     <Field label="VAT / BIN"><input className={input} value={form.data.vat_number} onChange={e=>form.setData("vat_number",e.target.value)}/></Field>
     <Field label="Credit Limit"><input type="number" min="0" className={input} value={form.data.credit_limit} onChange={e=>form.setData("credit_limit",Number(e.target.value))}/></Field>
     <Field label="Payment Terms (days)"><input type="number" min="0" className={input} value={form.data.payment_terms_days} onChange={e=>form.setData("payment_terms_days",Number(e.target.value))}/></Field>
     <Field label="Manager"><select className={input} value={form.data.manager_user_id??""} onChange={e=>form.setData("manager_user_id",e.target.value?Number(e.target.value):null)}><option value="">None</option>{managers.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
    </div></Card>}

    <Card title="Address & Notes">
     <Field label="Address"><textarea className={textarea} value={form.data.address} onChange={e=>form.setData("address",e.target.value)} required={form.data.target_type!=="customer"}/></Field>
     <div className="mt-4 grid gap-4 md:grid-cols-2"><Field label="Postal Code"><input className={input} value={form.data.postal_code} onChange={e=>form.setData("postal_code",e.target.value)}/></Field></div>
     <div className="mt-4"><Field label="Master Notes"><textarea className={textarea} value={form.data.notes} onChange={e=>form.setData("notes",e.target.value)}/></Field></div>
     <div className="mt-4"><Field label="Conversion Notes"><textarea className={textarea} value={form.data.conversion_notes} onChange={e=>form.setData("conversion_notes",e.target.value)} placeholder="Why/how this lead was converted..."/></Field></div>
    </Card>

    {form.errors.conversion&&<p className="text-sm text-red-600">{form.errors.conversion}</p>}
    <div className="flex justify-end"><button disabled={form.processing} className="inline-flex h-10 items-center gap-2 rounded-md bg-neutral-950 px-5 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-neutral-950"><ArrowRightLeft className="h-4 w-4"/>{form.processing?"Converting...":"Convert Lead"}</button></div>
   </form>
  </div>
 </ERPLayout>
}

function Card({title,children}:{title:string;children:ReactNode}){return <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950"><h2 className="mb-5 font-semibold">{title}</h2>{children}</section>}
function Field({label,children}:{label:string;children:ReactNode}){return <label className="block"><span className="mb-1.5 block text-sm font-medium">{label}</span>{children}</label>}
