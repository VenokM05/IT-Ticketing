<?php

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Services\ReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Reports')] class extends Component {
    public string $from = '';
    public string $to = '';
    public string $status = '';
    public string $priority = '';
    public string $category = '';
    public string $technician = '';
    public string $department = '';
    public string $company = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        // Company staff get their own company pre-selected as a convenience default;
        // the view is not restricted (companies are a tag, not an access boundary).
        $this->company = (string) (auth()->user()?->company_id ?? '');
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'status' => $this->status,
            'priority' => $this->priority,
            'category' => $this->category,
            'technician' => $this->technician,
            'department' => $this->department,
            'company' => $this->company,
        ];
    }

    public function clear(): void
    {
        $this->reset(['from', 'to', 'status', 'priority', 'category', 'technician', 'department', 'company']);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function with(): array
    {
        $report = app(ReportService::class)->report($this->filters());

        // Humanise the minute averages once, here, so the template stays dumb.
        foreach ([
            'first_response_avg_minutes' => 'first_response_avg_human',
            'resolution_avg_minutes' => 'resolution_avg_human',
            'resolution_max_minutes' => 'resolution_max_human',
            'closure_avg_minutes' => 'closure_avg_human',
        ] as $key => $human) {
            $report['metrics'][$human] = $this->human($report['metrics'][$key]);
        }
        foreach ($report['workload'] as &$row) {
            $row['avg_resolution_human'] = $this->human($row['avg_resolution_minutes']);
        }
        unset($row);

        return [
            'report' => $report,
            'exportQuery' => array_filter($this->filters(), fn (string $v) => $v !== ''),
            'technicians' => User::query()->where('role', UserRole::Technician->value)->orderBy('name')->get(),
            'departments' => Department::query()->orderBy('name')->get(),
            'companies' => Company::query()->orderBy('name')->get(),
        ];
    }

    private function human(?float $minutes): string
    {
        return ReportService::humanize($minutes);
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Reports" subtitle="Operational reporting and management exports">
        <x-slot:actions>
            <flux:button :href="route('reports.export', $exportQuery)" variant="primary" icon="arrow-down-tray" wire:navigate.off>Export CSV</flux:button>
        </x-slot:actions>
    </x-page.header>

    {{-- Filters (shared with the CSV export) --}}
    <div class="grid grid-cols-2 gap-3 card p-4 sm:grid-cols-3 lg:grid-cols-9">
        <flux:input type="date" wire:model.live="from" label="From" />
        <flux:input type="date" wire:model.live="to" label="To" />
        <flux:select wire:model.live="status" placeholder="All statuses">
            <flux:select.option value="">All statuses</flux:select.option>
            @foreach (TicketStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="priority" placeholder="All priorities">
            <flux:select.option value="">All priorities</flux:select.option>
            @foreach (TicketPriority::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="category" placeholder="All categories">
            <flux:select.option value="">All categories</flux:select.option>
            @foreach (TicketCategory::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="technician" placeholder="Any technician">
            <flux:select.option value="">Any technician</flux:select.option>
            @foreach ($technicians as $tech)
                <flux:select.option value="{{ $tech->id }}">{{ $tech->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="company" placeholder="All companies">
            <flux:select.option value="">All companies</flux:select.option>
            @foreach ($companies as $item)
                <flux:select.option value="{{ $item->id }}">{{ $item->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="department" placeholder="All departments">
            <flux:select.option value="">All departments</flux:select.option>
            @foreach ($departments as $dept)
                <flux:select.option value="{{ $dept->id }}">{{ $dept->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button wire:click="clear" variant="subtle">Clear</flux:button>
    </div>

    {{-- Headline totals --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            'Created' => $report['totals']['created'],
            'Open' => $report['totals']['open'],
            'Resolved' => $report['totals']['resolved'],
            'Closed' => $report['totals']['closed'],
            'Unassigned' => $report['totals']['unassigned'],
            'Resolution %' => $report['metrics']['resolution_rate'].'%',
        ] as $label => $value)
            <div class="card p-4">
                <div class="text-xs text-fg-muted">{{ $label }}</div>
                <div class="text-2xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Response & resolution metrics --}}
    <div class="card p-4">
        <h2 class="mb-3 text-sm font-semibold">Response &amp; resolution</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <div>
                <div class="text-xs text-fg-muted">Avg first response <span class="text-fg-subtle">({{ $report['metrics']['first_response_measured'] }})</span></div>
                <div class="text-lg font-medium">{{ $report['metrics']['first_response_avg_human'] }}</div>
            </div>
            <div>
                <div class="text-xs text-fg-muted">Avg resolution (MTTR) <span class="text-fg-subtle">({{ $report['metrics']['resolution_measured'] }})</span></div>
                <div class="text-lg font-medium">{{ $report['metrics']['resolution_avg_human'] }}</div>
            </div>
            <div>
                <div class="text-xs text-fg-muted">Slowest resolution</div>
                <div class="text-lg font-medium">{{ $report['metrics']['resolution_max_human'] }}</div>
            </div>
            <div>
                <div class="text-xs text-fg-muted">Avg time to close <span class="text-fg-subtle">({{ $report['metrics']['closure_measured'] }})</span></div>
                <div class="text-lg font-medium">{{ $report['metrics']['closure_avg_human'] }}</div>
            </div>
            <div>
                <div class="text-xs text-fg-muted">Reopen rate <span class="text-fg-subtle">({{ $report['metrics']['reopened'] }})</span></div>
                <div class="text-lg font-medium">{{ $report['metrics']['reopen_rate'] }}%</div>
            </div>
            <div>
                <div class="text-xs text-fg-muted">CSAT avg <span class="text-fg-subtle">({{ $report['metrics']['csat_count'] }})</span></div>
                <div class="text-lg font-medium">{{ $report['metrics']['csat_avg'] === null ? '—' : $report['metrics']['csat_avg'].' / 5' }}</div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Status distribution --}}
        <div class="card p-4">
            <h2 class="mb-3 text-sm font-semibold">By status</h2>
            @php($statusMax = max(1, collect($report['by_status'])->max('count')))
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @foreach ($report['by_status'] as $row)
                        <tr>
                            <td class="py-1.5 whitespace-nowrap pr-2">{{ $row['status']->label() }}</td>
                            <td class="w-full py-1.5">
                                <div class="h-2 rounded bg-surface-muted">
                                    <div class="h-2 rounded bg-accent" style="width: {{ (int) round($row['count'] / $statusMax * 100) }}%"></div>
                                </div>
                            </td>
                            <td class="py-1.5 pl-2 text-right tabular-nums">{{ $row['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Priority distribution --}}
        <div class="card p-4">
            <h2 class="mb-3 text-sm font-semibold">By priority</h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @foreach ($report['by_priority'] as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['priority']->label() }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- By category --}}
        <div class="card p-4">
            <h2 class="mb-3 text-sm font-semibold">By category</h2>
            @php($categoryMax = max(1, collect($report['by_category'])->max('count')))
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @forelse ($report['by_category'] as $row)
                        <tr>
                            <td class="py-1.5 whitespace-nowrap pr-2">{{ $row['category'] }}</td>
                            <td class="w-full py-1.5">
                                <div class="h-2 rounded bg-surface-muted">
                                    <div class="h-2 rounded bg-accent" style="width: {{ (int) round($row['count'] / $categoryMax * 100) }}%"></div>
                                </div>
                            </td>
                            <td class="py-1.5 pl-2 text-right tabular-nums">{{ $row['count'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-fg-muted">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- By company --}}
        <div class="card p-4">
            <h2 class="mb-3 text-sm font-semibold">By company</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-fg-muted">
                    <tr><th class="pb-1 font-medium">Company</th><th class="pb-1 text-right font-medium">Open</th><th class="pb-1 text-right font-medium">Resolved</th><th class="pb-1 text-right font-medium">Total</th></tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($report['by_company'] as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['company'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['open'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['resolved'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['count'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-fg-muted">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- By department --}}
        <div class="card p-4">
            <h2 class="mb-3 text-sm font-semibold">By department</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-fg-muted">
                    <tr><th class="pb-1 font-medium">Department</th><th class="pb-1 text-right font-medium">Open</th><th class="pb-1 text-right font-medium">Total</th></tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($report['by_department'] as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['department'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['open'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['count'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-2 text-fg-muted">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Technician workload --}}
    <div class="card p-4">
        <h2 class="mb-3 text-sm font-semibold">Technician workload</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-fg-muted">
                    <tr>
                        <th class="pb-1 font-medium">Technician</th>
                        <th class="pb-1 text-right font-medium">Assigned</th>
                        <th class="pb-1 text-right font-medium">Open</th>
                        <th class="pb-1 text-right font-medium">Resolved</th>
                        <th class="pb-1 text-right font-medium">Avg resolution</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($report['workload'] as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['technician'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['assigned'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['open'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['resolved'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['avg_resolution_human'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-fg-muted">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Volume trends --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach (['daily' => 'Daily volume', 'monthly' => 'Monthly volume'] as $key => $heading)
            <div class="card p-4">
                <h2 class="mb-3 text-sm font-semibold">{{ $heading }}</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-fg-muted">
                        <tr><th class="pb-1 font-medium">Period</th><th class="pb-1 text-right font-medium">Created</th><th class="pb-1 text-right font-medium">Resolved</th><th class="pb-1 text-right font-medium">Closed</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($report[$key] as $row)
                            <tr>
                                <td class="py-1.5 whitespace-nowrap">{{ $row['period'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['created'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['resolved'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['closed'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-fg-muted">No data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</div>
