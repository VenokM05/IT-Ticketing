<?php

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
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

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
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
        ];
    }

    public function clear(): void
    {
        $this->reset(['from', 'to', 'status', 'priority', 'category', 'technician', 'department']);
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
        ];
    }

    private function human(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $m = (int) round($minutes);
        if ($m < 60) {
            return "{$m} min";
        }

        $h = intdiv($m, 60);
        $r = $m % 60;
        if ($h < 24) {
            return "{$h}h {$r}m";
        }

        return intdiv($h, 24).'d '.($h % 24).'h';
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Reports</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Operational reporting and management exports (PRD Phase 6)</p>
        </div>
        <flux:button :href="route('reports.export', $exportQuery)" variant="primary" icon="arrow-down-tray" wire:navigate.off>Export CSV</flux:button>
    </div>

    {{-- Filters (shared with the CSV export) --}}
    <div class="grid grid-cols-2 gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-3 lg:grid-cols-8">
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
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                <div class="text-2xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Response & resolution metrics --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="mb-3 text-sm font-semibold">Response &amp; resolution</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">Avg first response <span class="text-zinc-400">({{ $report['metrics']['first_response_measured'] }})</span></div>
                <div class="text-lg font-medium">{{ $report['metrics']['first_response_avg_human'] }}</div>
            </div>
            <div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">Avg resolution <span class="text-zinc-400">({{ $report['metrics']['resolution_measured'] }})</span></div>
                <div class="text-lg font-medium">{{ $report['metrics']['resolution_avg_human'] }}</div>
            </div>
            <div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">Slowest resolution</div>
                <div class="text-lg font-medium">{{ $report['metrics']['resolution_max_human'] }}</div>
            </div>
            <div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">Avg time to close <span class="text-zinc-400">({{ $report['metrics']['closure_measured'] }})</span></div>
                <div class="text-lg font-medium">{{ $report['metrics']['closure_avg_human'] }}</div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Status distribution --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-3 text-sm font-semibold">By status</h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($report['by_status'] as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['status']->label() }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Priority distribution --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-3 text-sm font-semibold">By priority</h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
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
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-3 text-sm font-semibold">By category</h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($report['by_category'] as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['category'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['count'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-zinc-500 dark:text-zinc-400">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- By department --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-3 text-sm font-semibold">By department</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                    <tr><th class="pb-1 font-medium">Department</th><th class="pb-1 text-right font-medium">Open</th><th class="pb-1 text-right font-medium">Total</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($report['by_department'] as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['department'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['open'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['count'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-2 text-zinc-500 dark:text-zinc-400">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Technician workload --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="mb-3 text-sm font-semibold">Technician workload</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                    <tr>
                        <th class="pb-1 font-medium">Technician</th>
                        <th class="pb-1 text-right font-medium">Assigned</th>
                        <th class="pb-1 text-right font-medium">Open</th>
                        <th class="pb-1 text-right font-medium">Resolved</th>
                        <th class="pb-1 text-right font-medium">Avg resolution</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($report['workload'] as $row)
                        <tr>
                            <td class="py-1.5">{{ $row['technician'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['assigned'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['open'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['resolved'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $row['avg_resolution_human'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-zinc-500 dark:text-zinc-400">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Volume trends --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach (['daily' => 'Daily volume', 'monthly' => 'Monthly volume'] as $key => $heading)
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="mb-3 text-sm font-semibold">{{ $heading }}</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                        <tr><th class="pb-1 font-medium">Period</th><th class="pb-1 text-right font-medium">Created</th><th class="pb-1 text-right font-medium">Resolved</th><th class="pb-1 text-right font-medium">Closed</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($report[$key] as $row)
                            <tr>
                                <td class="py-1.5 whitespace-nowrap">{{ $row['period'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['created'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['resolved'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['closed'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-zinc-500 dark:text-zinc-400">No data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</div>
