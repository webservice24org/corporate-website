import { useForm } from "@inertiajs/react";
import type { FormEvent, ReactNode } from "react";
import type { CompanyOption, Customer, CustomerAddress, CustomerContact, DistrictOption, DivisionOption, UpazilaOption } from "@/types/customer";

type Props={
 customer?:Customer;
 companies:CompanyOption[];
 divisions:DivisionOption[];
 districts:DistrictOption[];
 upazilas:UpazilaOption[];
};

type FormData={
 company_id:number|null; customer_type:"individual"|"company"; name:string; business_name:string;
 contact_person:string; phone:string; alternate_phone:string; email:string; nid_number:string;
 tin_number:string; vat_number:string; credit_limit:string; payment_terms_days:string;
 is_active:boolean; notes:string; contacts:CustomerContact[]; addresses:CustomerAddress[];
 _method?:"put";
};

const input="h-10 w-full rounded-md border border-neutral-200 bg-white px-3 text-sm dark:border-neutral-800 dark:bg-neutral-950";
const label="mb-1.5 block text-sm font-medium";

export default function CustomerForm({customer,companies,divisions,districts,upazilas}:Props){
 const form=useForm<FormData>({
  company_id:customer?.company_id??companies[0]?.id??null,
  customer_type:customer?.customer_type??"individual", name:customer?.name??"",
  business_name:customer?.business_name??"", contact_person:customer?.contact_person??"",
  phone:customer?.phone??"", alternate_phone:customer?.alternate_phone??"", email:customer?.email??"",
  nid_number:customer?.nid_number??"", tin_number:customer?.tin_number??"", vat_number:customer?.vat_number??"",
  credit_limit:String(customer?.credit_limit??0), payment_terms_days:String(customer?.payment_terms_days??0),
  is_active:customer?.is_active??true, notes:customer?.notes??"",
  contacts:customer?.contacts?.map(x=>({...x,designation:x.designation??"",phone:x.phone??"",email:x.email??"",notes:x.notes??""}))??[],
  addresses:customer?.addresses?.map(x=>({...x,label:x.label??"",postal_code:x.postal_code??""}))??[],
 });

 const submit=(e:FormEvent)=>{
  e.preventDefault();
  if(customer){
   form.transform(data=>({...data,_method:"put"}));
   form.post(`/erp/crm/customers/${customer.id}`,{preserveScroll:true});
  }else form.post("/erp/crm/customers",{preserveScroll:true});
 };

 const addContact=()=>form.setData("contacts",[...form.data.contacts,{name:"",designation:"",phone:"",email:"",is_primary:false,notes:""}]);
 const addAddress=()=>form.setData("addresses",[...form.data.addresses,{address_type:"shipping",label:"",division_id:null,district_id:null,upazila_id:null,address:"",postal_code:"",is_primary:false}]);

 return <form onSubmit={submit} className="space-y-6">
  <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950">
   <h2 className="mb-5 font-semibold">Customer Information</h2>
   <div className="grid gap-5 md:grid-cols-2">
    <Field label="Company"><select className={input} value={form.data.company_id??""} onChange={e=>form.setData("company_id",Number(e.target.value))}>{companies.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
    <Field label="Customer Type"><select className={input} value={form.data.customer_type} onChange={e=>form.setData("customer_type",e.target.value as FormData["customer_type"])}><option value="individual">Individual</option><option value="company">Company</option></select></Field>
    <Field label={form.data.customer_type==="company"?"Primary Name":"Customer Name"}><input className={input} value={form.data.name} onChange={e=>form.setData("name",e.target.value)} required/></Field>
    <Field label="Business Name"><input className={input} value={form.data.business_name} onChange={e=>form.setData("business_name",e.target.value)}/></Field>
    <Field label="Contact Person"><input className={input} value={form.data.contact_person} onChange={e=>form.setData("contact_person",e.target.value)}/></Field>
    <Field label="Phone"><input className={input} value={form.data.phone} onChange={e=>form.setData("phone",e.target.value)} required/></Field>
    <Field label="Alternate Phone"><input className={input} value={form.data.alternate_phone} onChange={e=>form.setData("alternate_phone",e.target.value)}/></Field>
    <Field label="Email"><input type="email" className={input} value={form.data.email} onChange={e=>form.setData("email",e.target.value)}/></Field>
   </div>
  </section>

  <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950">
   <h2 className="mb-5 font-semibold">Legal & Commercial</h2>
   <div className="grid gap-5 md:grid-cols-2">
    <Field label="NID"><input className={input} value={form.data.nid_number} onChange={e=>form.setData("nid_number",e.target.value)}/></Field>
    <Field label="TIN"><input className={input} value={form.data.tin_number} onChange={e=>form.setData("tin_number",e.target.value)}/></Field>
    <Field label="VAT / BIN"><input className={input} value={form.data.vat_number} onChange={e=>form.setData("vat_number",e.target.value)}/></Field>
    <Field label="Credit Limit"><input type="number" min="0" step="0.01" className={input} value={form.data.credit_limit} onChange={e=>form.setData("credit_limit",e.target.value)}/></Field>
    <Field label="Payment Terms (days)"><input type="number" min="0" className={input} value={form.data.payment_terms_days} onChange={e=>form.setData("payment_terms_days",e.target.value)}/></Field>
    <Field label="Status"><select className={input} value={form.data.is_active?"1":"0"} onChange={e=>form.setData("is_active",e.target.value==="1")}><option value="1">Active</option><option value="0">Inactive</option></select></Field>
   </div>
  </section>

  <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950">
   <div className="mb-5 flex items-center justify-between"><h2 className="font-semibold">Contacts</h2><button type="button" onClick={addContact} className="rounded-md border px-3 py-2 text-sm">Add Contact</button></div>
   <div className="space-y-4">{form.data.contacts.map((c,i)=><div key={i} className="grid gap-3 rounded-lg border p-4 md:grid-cols-5">
    <input className={input} placeholder="Name" value={c.name} onChange={e=>updateContact(form,i,"name",e.target.value)}/>
    <input className={input} placeholder="Designation" value={c.designation} onChange={e=>updateContact(form,i,"designation",e.target.value)}/>
    <input className={input} placeholder="Phone" value={c.phone} onChange={e=>updateContact(form,i,"phone",e.target.value)}/>
    <input className={input} placeholder="Email" value={c.email} onChange={e=>updateContact(form,i,"email",e.target.value)}/>
    <div className="flex items-center gap-3"><label className="text-sm"><input type="checkbox" checked={c.is_primary} onChange={e=>updateContact(form,i,"is_primary",e.target.checked)}/> Primary</label><button type="button" className="text-sm text-red-600" onClick={()=>form.setData("contacts",form.data.contacts.filter((_,x)=>x!==i))}>Remove</button></div>
   </div>)}</div>
  </section>

  <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950">
   <div className="mb-5 flex items-center justify-between"><h2 className="font-semibold">Addresses</h2><button type="button" onClick={addAddress} className="rounded-md border px-3 py-2 text-sm">Add Address</button></div>
   <div className="space-y-4">{form.data.addresses.map((a,i)=>{
    const ds=districts.filter(d=>!a.division_id||d.division_id===a.division_id);
    const us=upazilas.filter(u=>!a.district_id||u.district_id===a.district_id);
    return <div key={i} className="grid gap-3 rounded-lg border p-4 md:grid-cols-2">
     <select className={input} value={a.address_type} onChange={e=>updateAddress(form,i,"address_type",e.target.value)}><option value="billing">Billing</option><option value="shipping">Shipping</option><option value="other">Other</option></select>
     <input className={input} placeholder="Label" value={a.label} onChange={e=>updateAddress(form,i,"label",e.target.value)}/>
     <select className={input} value={a.division_id??""} onChange={e=>{const v=e.target.value?Number(e.target.value):null; updateAddress(form,i,"division_id",v); updateAddress(form,i,"district_id",null); updateAddress(form,i,"upazila_id",null)}}><option value="">Division</option>{divisions.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select>
     <select className={input} value={a.district_id??""} onChange={e=>{const v=e.target.value?Number(e.target.value):null; updateAddress(form,i,"district_id",v); updateAddress(form,i,"upazila_id",null)}}><option value="">District</option>{ds.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select>
     <select className={input} value={a.upazila_id??""} onChange={e=>updateAddress(form,i,"upazila_id",e.target.value?Number(e.target.value):null)}><option value="">Upazila</option>{us.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select>
     <input className={input} placeholder="Postal Code" value={a.postal_code} onChange={e=>updateAddress(form,i,"postal_code",e.target.value)}/>
     <textarea className="min-h-24 rounded-md border p-3 md:col-span-2 dark:bg-neutral-950" placeholder="Full address" value={a.address} onChange={e=>updateAddress(form,i,"address",e.target.value)}/>
     <div className="flex gap-4"><label className="text-sm"><input type="checkbox" checked={a.is_primary} onChange={e=>updateAddress(form,i,"is_primary",e.target.checked)}/> Primary for this type</label><button type="button" className="text-sm text-red-600" onClick={()=>form.setData("addresses",form.data.addresses.filter((_,x)=>x!==i))}>Remove</button></div>
    </div>})}</div>
  </section>

  <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950">
   <Field label="Notes"><textarea className="min-h-28 w-full rounded-md border p-3 dark:bg-neutral-950" value={form.data.notes} onChange={e=>form.setData("notes",e.target.value)}/></Field>
  </section>

  {Object.keys(form.errors).length>0&&<div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">Please review the highlighted/invalid customer information and try again.</div>}
  <div className="flex justify-end"><button disabled={form.processing} className="rounded-md bg-neutral-950 px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-neutral-950">{form.processing?"Saving...":customer?"Update Customer":"Create Customer"}</button></div>
 </form>
}

function Field({label,children}:{label:string;children:ReactNode}){return <label><span className={labelClass}>{label}</span>{children}</label>}
const labelClass=label;

function updateContact(form:any,i:number,key:keyof CustomerContact,value:any){const rows=[...form.data.contacts]; rows[i]={...rows[i],[key]:value}; form.setData("contacts",rows)}
function updateAddress(form:any,i:number,key:keyof CustomerAddress,value:any){const rows=[...form.data.addresses]; rows[i]={...rows[i],[key]:value}; form.setData("addresses",rows)}
