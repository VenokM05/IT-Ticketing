<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeImportService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] #[Title('Employee Directory')] class extends Component {
    use WithFileUploads, WithPagination;

    public string $search = '';
    public string $departmentId = '';
    public string $companyId = '';
    public string $status = 'active';

    // Create/edit form state.
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $fName = '';
    public string $fNumber = '';
    public string $fDepartmentId = '';
    public string $fCompanyId = '';
    public string $fPosition = '';
    public string $fLocation = '';
    public string $fEmail = '';
    public bool $fActive = true;

    // CSV import state.
    public $csvFile;
    public array $importReport = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Employee::class);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'departmentId', 'companyId', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function startCreate(): void
    {
        $this->reset(['editingId', 'fName', 'fNumber', 'fDepartmentId', 'fCompanyId', 'fPosition', 'fLocation', 'fEmail', 'importReport']);
        $this->fActive = true;
        $this->fDepartmentId = (string) Department::query()->value('id');
        $this->fCompanyId = (string) Company::query()->active()->value('id');
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('update', $employee);

        $this->resetErrorBag();
        $this->editingId = $employee->id;
        $this->fName = $employee->name;
        $this->fNumber = $employee->whatsapp_number;
        $this->fDepartmentId = (string) $employee->department_id;
        $this->fCompanyId = (string) $employee->company_id;
        $this->fPosition = (string) $employee->position;
        $this->fLocation = (string) $employee->location;
        $this->fEmail = (string) $employee->email;
        $this->fActive = $employee->is_active;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->reset(['showForm', 'editingId', 'fName', 'fNumber', 'fDepartmentId', 'fCompanyId', 'fPosition', 'fLocation', 'fEmail', 'importReport']);
    }

    public function save(): void
    {
        $this->authorize('create', Employee::class);

        $this->validate([
            'fName' => ['required', 'string', 'max:255'],
            'fNumber' => ['required', 'string', 'max:32'],
            'fDepartmentId' => ['required', 'integer', 'exists:departments,id'],
            'fCompanyId' => ['required', 'integer', 'exists:companies,id'],
            'fPosition' => ['nullable', 'string', 'max:255'],
            'fEmail' => ['nullable', 'email', 'max:255'],
        ]);

        $normalized = Employee::normalizeNumber($this->fNumber);
        $digits = ltrim($normalized, '+');

        if (! ctype_digit($digits) || strlen($digits) < 8 || strlen($digits) > 15) {
            $this->addError('fNumber', 'Enter a valid E.164 number (8–15 digits, e.g. 639171234567).');

            return;
        }

        // Number must be unique among ACTIVE records (§7.2, §9.1).
        $clash = Employee::query()
            ->where('whatsapp_number', $normalized)
            ->where('is_active', true)
            ->when($this->editingId, fn (Builder $q) => $q->whereKeyNot($this->editingId));

        if ($this->fActive && $clash->exists()) {
            $this->addError('fNumber', 'An active employee already uses this number.');

            return;
        }

        $employee = $this->editingId ? Employee::find($this->editingId) : new Employee;
        $employee->fill([
            'name' => $this->fName,
            'whatsapp_number' => $normalized,
            'department_id' => (int) $this->fDepartmentId,
            'company_id' => (int) $this->fCompanyId,
            'position' => $this->fPosition ?: null,
            'location' => $this->fLocation ?: null,
            'email' => $this->fEmail ?: null,
            'is_active' => $this->fActive,
        ]);
        $employee->save();

        $this->cancel();
        session()->flash('success', 'Employee saved.');
    }

    public function toggle(int $id): void
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('update', $employee);

        if (! $employee->is_active) {
            // Reactivating must not create a duplicate active number.
            $clash = Employee::query()
                ->where('whatsapp_number', $employee->whatsapp_number)
                ->where('is_active', true)
                ->whereKeyNot($employee->id)
                ->exists();

            if ($clash) {
                session()->flash('error', 'Another active employee already uses '.$employee->whatsapp_number.'.');

                return;
            }
        }

        $employee->update(['is_active' => ! $employee->is_active]);
    }

    public function doImport(): void
    {
        $this->authorize('create', Employee::class);

        $this->validate(['csvFile' => ['required', 'file', 'max:2048']]);

        $this->importReport = app(EmployeeImportService::class)
            ->import(file_get_contents($this->csvFile->path()));

        if ($this->importReport['ok']) {
            $this->reset('csvFile');
            session()->flash('success', "Imported {$this->importReport['imported']} employees.");
        } else {
            session()->flash('error', 'Import failed — no rows were saved.');
        }
    }

    public function with(): array
    {
        return [
            'employees' => Employee::query()
                ->with(['department', 'company'])
                ->withCount('tickets')
                ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $i) => $i
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('whatsapp_number', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")))
                ->when($this->departmentId, fn (Builder $q) => $q->where('department_id', $this->departmentId))
                ->when($this->companyId, fn (Builder $q) => $q->where('company_id', $this->companyId))
                ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
                ->when($this->status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
                ->orderBy('name')
                ->paginate(15),
            'departments' => Department::query()->orderBy('name')->get(),
            'companies' => Company::query()->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold">Employee Directory</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Registration management (PRD §10.7)</p>
        </div>
        <flux:button wire:click="startCreate" variant="primary" icon="plus">New employee</flux:button>
    </div>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ session('error') }}</div>
    @endif

    {{-- Create / edit form --}}
    @if ($showForm)
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit employee' : 'New employee' }}</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <flux:input wire:model="fName" label="Name" placeholder="Full name" />
                <flux:input wire:model="fNumber" label="WhatsApp number" placeholder="639171234567" />
                <flux:select wire:model="fDepartmentId" label="Department">
                    @foreach ($departments as $dept)
                        <flux:select.option value="{{ $dept->id }}">{{ $dept->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="fCompanyId" label="Company">
                    @foreach ($companies as $company)
                        <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="fPosition" label="Position" placeholder="e.g. Accountant" />
                <flux:input wire:model="fLocation" label="Location" placeholder="e.g. HQ Floor 3" />
                <flux:input wire:model="fEmail" label="Email" type="email" placeholder="name@company.com" />
                <flux:checkbox wire:model="fActive" label="Active" />
            </div>
            <div class="mt-3 flex gap-2">
                <flux:button wire:click="save" variant="primary">Save</flux:button>
                <flux:button wire:click="cancel" variant="subtle">Cancel</flux:button>
            </div>
        </div>
    @endif

    {{-- CSV import (§9.1) --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="text-sm font-semibold">Bulk CSV import</h2>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Columns: <code>name, whatsapp_number, department, company, position, location, email, is_active</code>.
            Departments and companies must already exist; the import fails and reports any unmapped value rather than creating ad-hoc ones.
        </p>
        <div class="mt-3 flex flex-wrap items-end gap-2">
            <flux:input wire:model="csvFile" type="file" accept=".csv,text/csv" label="CSV file" class="grow" />
            <flux:button wire:click="doImport" variant="subtle">Import</flux:button>
        </div>

        @if ($importReport !== [])
            <div class="mt-3 rounded-lg border p-3 text-sm {{ $importReport['ok'] ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300' }}">
                @if ($importReport['ok'])
                    Import complete — {{ $importReport['imported'] }} employee(s) added.
                @else
                    Import aborted. {{ count($importReport['errors']) }} issue(s):
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($importReport['errors'] as $error)
                            <li>Row {{ $error['row'] }}: {{ $error['message'] }}</li>
                        @endforeach
                    </ul>
                    @if (! empty($importReport['unmapped_departments']))
                        <p class="mt-2">Unmapped departments: {{ implode(', ', $importReport['unmapped_departments']) }}</p>
                    @endif
                    @if (! empty($importReport['unmapped_companies']))
                        <p class="mt-2">Unmapped companies: {{ implode(', ', $importReport['unmapped_companies']) }}</p>
                    @endif
                @endif
            </div>
        @endif
    </div>

    {{-- Filters --}}
    <div class="grid gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-5">
        <flux:input wire:model.live.debounce.400ms="search" placeholder="Search name / number / email" icon="magnifying-glass" class="sm:col-span-2" />
        <flux:select wire:model.live="companyId" placeholder="All companies">
            <flux:select.option value="">All companies</flux:select.option>
            @foreach ($companies as $company)
                <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="departmentId" placeholder="All departments">
            <flux:select.option value="">All departments</flux:select.option>
            @foreach ($departments as $dept)
                <flux:select.option value="{{ $dept->id }}">{{ $dept->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" placeholder="Status">
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="inactive">Inactive</flux:select.option>
            <flux:select.option value="all">All</flux:select.option>
        </flux:select>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Name</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">WhatsApp</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Company</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Department</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Tickets</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($employees as $employee)
                        <tr>
                            <td class="px-4 py-2">
                                <a href="{{ route('employees.show', $employee) }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ $employee->name }}</a>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $employee->email }}{{ $employee->location ? ' · '.$employee->location : '' }}</div>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $employee->whatsapp_number }}</td>
                            <td class="px-4 py-2">{{ $employee->company?->name ?? '—' }}</td>
                            <td class="px-4 py-2">
                                {{ $employee->department?->name ?? '—' }}
                                @if ($employee->position)
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $employee->position }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $employee->tickets_count }}</td>
                            <td class="px-4 py-2"><flux:badge :color="$employee->is_active ? 'green' : 'zinc'" size="sm">{{ $employee->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                            <td class="px-4 py-2">
                                <div class="flex gap-2">
                                    <flux:button size="xs" variant="subtle" wire:click="startEdit({{ $employee->id }})">Edit</flux:button>
                                    <flux:button size="xs" variant="subtle" wire:click="toggle({{ $employee->id }})">{{ $employee->is_active ? 'Deactivate' : 'Activate' }}</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No employees found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($employees->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">{{ $employees->links() }}</div>
        @endif
    </div>
</div>
