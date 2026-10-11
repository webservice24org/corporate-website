import { Head, Link } from "@inertiajs/react";
import { ArrowLeft, FilePenLine } from "lucide-react";
import type { ReactNode } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Opportunity } from "@/types/opportunity";

function money(v:string|number,c:string){return `${Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} ${c}`}
function party(o:Opportunity){return o.party?.business_name||o.party?.name||`#${o.party_id}`}

export default function Show({opportunity:o}:{opportunity:Opportunity}){
 const {can}=useAuthorization();
 return <ERPLayout><Head title={o.title}/><div className="mx-auto max-w-7xl space-y-6">
  <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><Link href="/erp/crm/opportunities" className="inline-flex items-center gap-2 text-sm text-slate-500"><ArrowLeft className="h-4 w-4"/>Opportunities</Link><div className="mt-3 flex flex-wrap items-center gap-3"><h1 className="text-2xl font-semibold">{o.title}</h1><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${o.stage?.is_won?"bg-emerald-50 text-emerald-700":o.stage?.is_lost?"bg-red-50 text-red-700":"bg-blue-50 text-blue-700"}`}>{o.stage?.name??"—"}</span></div><p className="mt-1 text-sm text-slate-500">{o.opportunity_number??`Opportunity #${o.id}`}</p></div>{can("erp.opportunities.edit")&&<Link href={`/erp/crm/opportunities/${o.id}/edit`} className="inline-flex h-10 items-center gap-2 rounded-md border px-4 text-sm font-medium"><FilePenLine className="h-4 w-4"/>Edit Opportunity</Link>}</div>
  <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Metric label="Expected Value" value={money(o.expected_value,o.currency)}/><Metric label="Probability" value={`${o.probability}%`}/><Metric label="Weighted Pipeline" value={money(Number(o.expected_value)*o.probability/100,o.currency)}/><Metric label="Expected Close" value={o.expected_close_date?new Date(o.expected_close_date).toLocaleDateString():"—"}/></div>
  <div className="grid gap-6 lg:grid-cols-2">
   <Card title="Commercial Relationship"><Info label="Company" value={o.company?.name}/><Info label="Party" value={party(o)}/><Info label="Salesperson" value={o.salesperson?.name}/><Info label="Source Lead" value={o.lead?`${o.lead.lead_number} · ${o.lead.company_name||o.lead.contact_name}`:"—"}/></Card>
   <Card title="Pipeline"><Info label="Stage" value={o.stage?.name}/><Info label="Probability" value={`${o.probability}%`}/><Info label="Status" value={o.stage?.is_won?"Won":o.stage?.is_lost?"Lost":o.is_active?"Open":"Inactive"}/><Info label="Won At" value={o.won_at?new Date(o.won_at).toLocaleString():"—"}/><Info label="Lost At" value={o.lost_at?new Date(o.lost_at).toLocaleString():"—"}/></Card>
  </div>
  {o.description&&<Card title="Description"><p className="whitespace-pre-wrap text-sm leading-6 text-slate-600 dark:text-slate-400">{o.description}</p></Card>}
  {o.lost_reason&&<Card title="Lost Reason"><p className="whitespace-pre-wrap text-sm text-red-700 dark:text-red-400">{o.lost_reason}</p></Card>}
  {o.notes&&<Card title="Notes"><p className="whitespace-pre-wrap text-sm leading-6 text-slate-600 dark:text-slate-400">{o.notes}</p></Card>}

  <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
              <h2 className="font-semibold">Activities</h2>
              <p className="mt-1 text-sm text-slate-500">
                  CRM communication, tasks, meetings and follow-ups for this opportunity.
              </p>
          </div>

          {can("erp.crm-activities.create") && (
              <Link
                  href={`/erp/crm/activities/create?opportunity_id=${o.id}`}
                  className="inline-flex h-10 items-center justify-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
              >
                  Add Activity
              </Link>
          )}
      </div>

      <div className="mt-6 space-y-4">
          {(o.activities ?? []).map((activity) => (
              <div
                  key={activity.id}
                  className="relative border-l-2 border-slate-200 pl-5 dark:border-slate-800"
              >
                  <div className="absolute -left-[5px] top-1.5 h-2 w-2 rounded-full bg-slate-500" />

                  <div className="flex flex-col gap-3 rounded-lg border border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between dark:border-slate-800">
                      <div>
                          <div className="flex flex-wrap items-center gap-2">
                              <Link
                                  href={`/erp/crm/activities/${activity.id}`}
                                  className="font-medium hover:underline"
                              >
                                  {activity.subject}
                              </Link>

                              <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-400">
                                  {activity.activity_type
                                      .replaceAll("_", " ")
                                      .replace(/\b\w/g, (letter) =>
                                          letter.toUpperCase()
                                      )}
                              </span>

                              <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">
                                  {activity.status
                                      .replaceAll("_", " ")
                                      .replace(/\b\w/g, (letter) =>
                                          letter.toUpperCase()
                                      )}
                              </span>
                          </div>

                          {activity.description && (
                              <p className="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">
                                  {activity.description}
                              </p>
                          )}

                          <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500">
                              {activity.assigned_to && (
                                  <span>
                                      Assigned: {activity.assigned_to.name}
                                  </span>
                              )}

                              {activity.due_at && (
                                  <span>
                                      Due:{" "}
                                      {new Date(
                                          activity.due_at
                                      ).toLocaleString()}
                                  </span>
                              )}

                              <span>
                                  Added:{" "}
                                  {new Date(
                                      activity.created_at
                                  ).toLocaleString()}
                              </span>
                          </div>
                      </div>

                      {can("erp.crm-activities.edit") && (
                          <Link
                              href={`/erp/crm/activities/${activity.id}/edit`}
                              className="shrink-0 text-sm font-medium text-slate-600 hover:underline dark:text-slate-300"
                          >
                              Edit
                          </Link>
                      )}
                  </div>
              </div>
          ))}

          {(o.activities ?? []).length === 0 && (
              <div className="rounded-lg border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700">
                  No activities have been recorded for this opportunity yet.
              </div>
          )}
      </div>
  </section>
 </div>
 </ERPLayout>
}
function Metric({label,value}:{label:string;value:string}){return <div className="rounded-xl border bg-white p-5 dark:bg-slate-950"><div className="text-xs uppercase tracking-wide text-slate-500">{label}</div><div className="mt-2 text-lg font-semibold">{value}</div></div>}
function Card({title,children}:{title:string;children:ReactNode}){return <section className="rounded-xl border bg-white p-6 dark:bg-slate-950"><h2 className="mb-5 font-semibold">{title}</h2><div className="space-y-4">{children}</div></section>}
function Info({label,value}:{label:string;value?:string|null}){return <div><div className="text-xs text-slate-500">{label}</div><div className="mt-1 text-sm font-medium">{value||"—"}</div></div>}