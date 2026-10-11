export type CompanyOption = { id:number; name:string };
export type DivisionOption = { id:number; name:string };
export type DistrictOption = { id:number; division_id:number; name:string };
export type UpazilaOption = { id:number; district_id:number; name:string };

export type CustomerContact = {
 id?:number; name:string; designation:string; phone:string; email:string; is_primary:boolean; notes:string;
};

export type CustomerAddress = {
 id?:number; address_type:"billing"|"shipping"|"other"; label:string;
 division_id:number|null; district_id:number|null; upazila_id:number|null;
 address:string; postal_code:string; is_primary:boolean;
};

export type Customer = {
 id:number; company_id:number; customer_code?:string|null;
 customer_type:"individual"|"company"; name:string; business_name?:string|null;
 contact_person?:string|null; phone:string; alternate_phone?:string|null; email?:string|null;
 nid_number?:string|null; tin_number?:string|null; vat_number?:string|null;
 credit_limit:string|number; payment_terms_days:number; is_active:boolean; notes?:string|null;
 company?:CompanyOption|null; contacts?:CustomerContact[]; addresses?:CustomerAddress[];
 created_at:string;
};
