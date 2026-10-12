<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeDocumentController extends Controller
{
    public function create(
        Employee $employee
    ): Response {
        return Inertia::render(
            'ERP/HRM/Employees/Documents/Create',
            [
                'employee' => $this->employeeData(
                    $employee
                ),
            ]
        );
    }

    public function store(
        Request $request,
        Employee $employee
    ): RedirectResponse {
        $validated =
            $this->validateDocument(
                $request
            );

        $file =
            $request->file(
                'file'
            );

        $path =
            $file->store(
                "employees/{$employee->id}/documents",
                'public'
            );

        EmployeeDocument::create([
            'employee_id' => $employee->id,

            'uploaded_by' => $request
                ->user()
                ?->id,

            'document_type' => $validated[
                    'document_type'
                ],

            'title' => $validated['title'],

            'document_number' => $validated[
                    'document_number'
                ] ?? null,

            'issue_date' => $validated[
                    'issue_date'
                ] ?? null,

            'expiry_date' => $validated[
                    'expiry_date'
                ] ?? null,

            'file_path' => $path,

            'original_name' => $file
                ->getClientOriginalName(),

            'mime_type' => $file
                ->getMimeType(),

            'file_size' => $file->getSize(),

            'notes' => $validated['notes']
                    ?? null,

            'status' => $validated['status'],
        ]);

        return to_route(
            'erp.employees.show',
            $employee
        )->with(
            'success',
            'Employee document added successfully.'
        );
    }

    public function edit(
        Employee $employee,
        EmployeeDocument $document
    ): Response {
        $this->ensureEmployeeOwnsDocument(
            $employee,
            $document
        );

        return Inertia::render(
            'ERP/HRM/Employees/Documents/Edit',
            [
                'employee' => $this->employeeData(
                    $employee
                ),

                'document' => $document,
            ]
        );
    }

    public function update(
        Request $request,
        Employee $employee,
        EmployeeDocument $document
    ): RedirectResponse {
        $this->ensureEmployeeOwnsDocument(
            $employee,
            $document
        );

        $validated =
            $this->validateDocument(
                $request,
                false
            );

        $data = [
            'document_type' => $validated[
                    'document_type'
                ],

            'title' => $validated['title'],

            'document_number' => $validated[
                    'document_number'
                ] ?? null,

            'issue_date' => $validated[
                    'issue_date'
                ] ?? null,

            'expiry_date' => $validated[
                    'expiry_date'
                ] ?? null,

            'notes' => $validated['notes']
                    ?? null,

            'status' => $validated['status'],
        ];

        if (
            $request->hasFile(
                'file'
            )
        ) {
            $file =
                $request->file(
                    'file'
                );

            $oldPath =
                $document
                    ->file_path;

            $newPath =
                $file->store(
                    "employees/{$employee->id}/documents",
                    'public'
                );

            $data = [
                ...$data,

                'file_path' => $newPath,

                'original_name' => $file
                    ->getClientOriginalName(),

                'mime_type' => $file
                    ->getMimeType(),

                'file_size' => $file->getSize(),
            ];

            $document->update(
                $data
            );

            if (
                $oldPath &&
                Storage::disk(
                    'public'
                )->exists(
                    $oldPath
                )
            ) {
                Storage::disk(
                    'public'
                )->delete(
                    $oldPath
                );
            }
        } else {
            $document->update(
                $data
            );
        }

        return to_route(
            'erp.employees.show',
            $employee
        )->with(
            'success',
            'Employee document updated successfully.'
        );
    }

    public function destroy(
        Employee $employee,
        EmployeeDocument $document
    ): RedirectResponse {
        $this->ensureEmployeeOwnsDocument(
            $employee,
            $document
        );

        $filePath =
            $document->file_path;

        $document->delete();

        if (
            $filePath &&
            Storage::disk(
                'public'
            )->exists(
                $filePath
            )
        ) {
            Storage::disk(
                'public'
            )->delete(
                $filePath
            );
        }

        return to_route(
            'erp.employees.show',
            $employee
        )->with(
            'success',
            'Employee document deleted successfully.'
        );
    }

    private function validateDocument(
        Request $request,
        bool $fileRequired = true
    ): array {
        return $request->validate([
            'document_type' => [
                'required',

                Rule::in([
                    'national_id',
                    'passport',
                    'birth_certificate',
                    'appointment_letter',
                    'employment_contract',
                    'educational_certificate',
                    'experience_certificate',
                    'training_certificate',
                    'professional_certificate',
                    'tax_document',
                    'other',
                ]),
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'document_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'issue_date' => [
                'nullable',
                'date',
            ],

            'expiry_date' => [
                'nullable',
                'date',
                'after_or_equal:issue_date',
            ],

            'file' => [
                $fileRequired
                    ? 'required'
                    : 'nullable',

                'file',
                'max:10240',

                'mimes:pdf,jpg,jpeg,png,webp,doc,docx',
            ],

            'notes' => [
                'nullable',
                'string',
            ],

            'status' => [
                'required',

                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ]);
    }

    private function ensureEmployeeOwnsDocument(
        Employee $employee,
        EmployeeDocument $document
    ): void {
        abort_unless(
            $document->employee_id ===
                $employee->id,
            404
        );
    }

    private function employeeData(
        Employee $employee
    ): Employee {
        return $employee->load([
            'company:id,name,code',
        ]);
    }
}
