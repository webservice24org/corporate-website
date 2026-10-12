<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CrmActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'activity_type' => ['nullable', Rule::in(['call', 'meeting', 'email', 'task', 'follow_up', 'note'])],
            'status' => ['nullable', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $activities = CrmActivity::query()
            ->with([
                'company:id,name',
                'assignedTo:id,name,email',
                'createdBy:id,name,email',
                'activityable',
            ])
            ->when($filters['company_id'] ?? null, fn($query, $companyId) => $query->where('company_id', $companyId))
            ->when($filters['activity_type'] ?? null, fn($query, $type) => $query->where('activity_type', $type))
            ->when($filters['status'] ?? null, fn($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn($query, $priority) => $query->where('priority', $priority))
            ->when($filters['assigned_to_user_id'] ?? null, fn($query, $userId) => $query->where('assigned_to_user_id', $userId))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('subject', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('outcome', 'like', "%{$search}%");
                });
            })
            ->orderByRaw('CASE WHEN status IN ("planned", "in_progress") THEN 0 ELSE 1 END')
            ->orderByRaw('COALESCE(due_at, scheduled_at) ASC')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ERP/CRM/Activities/Index', [
            'activities' => $activities,
            'companies' => $this->companies(),
            'users' => $this->users(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        $opportunityId = $request->integer('opportunity_id') ?: null;
        $leadId = $request->integer('lead_id') ?: null;
        $customerId = $request->integer('customer_id') ?: null;

        $subject = null;
        $subjectType = null;

        if ($opportunityId) {
            $subject = Opportunity::query()->findOrFail($opportunityId);
            $subjectType = 'opportunity';
        } elseif ($customerId) {
            $subject = Customer::query()->findOrFail($customerId);
            $subjectType = 'customer';
        } elseif ($leadId) {
            $subject = Lead::query()->with('company:id,name')->findOrFail($leadId);
            $subjectType = 'lead';
        }

        return Inertia::render('ERP/CRM/Activities/Create', [
            'subject' => $subject,
            'subject_type' => $subjectType,
            'subject_id' => $subject?->id,
            'leads' => $this->leads(),
            'customers' => $this->customers(),
            'opportunities' => $this->opportunities(),
            'users' => $this->users(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $subject = $this->resolveSubject($data['subject_type'], $data['subject_id']);

        $activity = $subject->activities()->create([
            'company_id' => $subject->company_id ?? null,
            'activity_type' => $data['activity_type'],
            'subject' => trim($data['subject']),
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'priority' => $data['priority'],
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'created_by_user_id' => $request->user()?->id,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'reminder_at' => $data['reminder_at'] ?? null,
            'completed_at' => $this->completedAt($data['status'], $data['completed_at'] ?? null),
            'outcome' => $data['outcome'] ?? null,
        ]);

        return $this->redirectBackToSubject($subject, 'CRM activity created successfully.');
    }

    public function show(CrmActivity $crmActivity): Response
    {
        $crmActivity->load([
            'company:id,name',
            'assignedTo:id,name,email',
            'createdBy:id,name,email',
            'activityable',
        ]);

        return Inertia::render('ERP/CRM/Activities/Show', [
            'activity' => $crmActivity,
        ]);
    }

    public function edit(CrmActivity $crmActivity): Response
    {
        $crmActivity->load('activityable');

        if (!$crmActivity->activityable) {
            abort(422, 'This activity subject is missing or not supported.');
        }

        return Inertia::render('ERP/CRM/Activities/Edit', [
            'activity' => $crmActivity,
            'subject' => $crmActivity->activityable,
            'leads' => $this->leads(),
            'customers' => $this->customers(),
            'opportunities' => $this->opportunities(),
            'users' => $this->users(),
        ]);
    }

    public function update(Request $request, CrmActivity $crmActivity): RedirectResponse
    {
        $data = $this->validateData($request);
        $subject = $this->resolveSubject($data['subject_type'], $data['subject_id']);

        $crmActivity->update([
            'activityable_type' => $subject->getMorphClass(),
            'activityable_id' => $subject->getKey(),
            'company_id' => $subject->company_id ?? $crmActivity->company_id,
            'activity_type' => $data['activity_type'],
            'subject' => trim($data['subject']),
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'priority' => $data['priority'],
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'reminder_at' => $data['reminder_at'] ?? null,
            'completed_at' => $this->completedAt(
                $data['status'],
                $data['completed_at'] ?? null,
                $crmActivity->completed_at
            ),
            'outcome' => $data['outcome'] ?? null,
        ]);

        return $this->redirectBackToSubject($subject, 'CRM activity updated successfully.');
    }

    public function destroy(CrmActivity $crmActivity): RedirectResponse
    {
        $subject = $crmActivity->activityable;
        $crmActivity->delete();

        if ($subject) {
            return $this->redirectBackToSubject($subject, 'CRM activity deleted successfully.');
        }

        return redirect()
            ->route('erp.crm-activities.index')
            ->with('success', 'CRM activity deleted successfully.');
    }

    /** @return Lead|Customer|Opportunity */
    private function resolveSubject(string $type, int $id): Model
    {
        return match ($type) {
            'opportunity' => Opportunity::query()->findOrFail($id),
            'customer' => Customer::query()->findOrFail($id),
            'lead' => Lead::query()->findOrFail($id),
            default => abort(422, 'Invalid subject type provided.'),
        };
    }

    private function redirectBackToSubject(Model $subject, string $message): RedirectResponse
    {
        $route = match (true) {
            $subject instanceof Lead => route('erp.leads.show', $subject),
            $subject instanceof Opportunity => route('erp.opportunities.show', $subject),
            $subject instanceof Customer => route('erp.customers.show', $subject),
            default => route('erp.crm-activities.index'),
        };

        return redirect($route)->with('success', $message);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'subject_type' => ['required', Rule::in(['lead', 'customer', 'opportunity'])],
            'subject_id' => ['required', 'integer', 'min:1'],
            ...$this->activityRules(),
        ]);
    }

    private function activityRules(): array
    {
        return [
            'activity_type' => ['required', Rule::in(['call', 'meeting', 'email', 'task', 'follow_up', 'note'])],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['planned', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'scheduled_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'reminder_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'outcome' => ['nullable', 'string', 'max:10000'],
        ];
    }

    private function completedAt(string $status, ?string $submitted = null, ?string $existing = null): ?string
    {
        if ($status !== 'completed') {
            return null;
        }

        return $submitted ?: $existing ?: now()->toDateTimeString();
    }

    private function companies()
    {
        return Company::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']);
    }

    private function users()
    {
        return User::query()->orderBy('name')->get(['id', 'name', 'email']);
    }

    private function leads()
    {
        return Lead::query()
            ->where('is_active', true)
            ->with(['company:id,name'])
            ->orderByDesc('id')
            ->get(['id', 'company_id', 'lead_number', 'lead_type', 'company_name', 'contact_name']);
    }

    private function customers()
    {
        return Customer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'customer_code',
                'name',
                'business_name',
            ]);
    }

    private function opportunities()
    {
        return Opportunity::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get(['id', 'company_id', 'title', 'opportunity_number']);
    }
}
