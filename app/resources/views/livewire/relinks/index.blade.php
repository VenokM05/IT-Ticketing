<?php

use App\Models\Employee;
use App\Models\RelinkRequest;
use App\Services\RelinkService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Relink Approvals')] class extends Component {
    /** @var array<int, int|null> selected target employee per request id */
    public array $assign = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function approve(int $requestId): void
    {
        $request = RelinkRequest::findOrFail($requestId);
        $employeeId = $this->assign[$requestId] ?? null;

        if (! $employeeId) {
            session()->flash('error', 'Choose the employee to link before approving.');

            return;
        }

        try {
            app(RelinkService::class)->approve($request, Employee::findOrFail($employeeId), auth()->user());
            unset($this->assign[$requestId]);
            session()->flash('success', "Linked {$request->requested_number} to the selected employee.");
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first() ?? 'Unable to approve.');
        }
    }

    public function reject(int $requestId): void
    {
        app(RelinkService::class)->reject(RelinkRequest::findOrFail($requestId), auth()->user());
        session()->flash('success', 'Relink request rejected.');
    }

    public function with(): array
    {
        return [
            'pending' => RelinkRequest::query()->where('status', RelinkRequest::STATUS_PENDING)->latest()->get(),
            'decided' => RelinkRequest::query()->where('status', '!=', RelinkRequest::STATUS_PENDING)->latest()->limit(20)->get(),
            'employees' => Employee::query()->where('is_active', true)->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-4">
    <div>
        <h1 class="text-xl font-semibold">Number-Relink Approvals</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Registered employees writing in from a new number via REGISTER (PRD §9.3)</p>
    </div>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ session('error') }}</div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-sm font-semibold">Pending ({{ $pending->count() }})</h2>
        </div>

        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($pending as $request)
                <div class="flex flex-wrap items-center gap-3 p-4">
                    <div class="grow">
                        <div class="text-sm font-medium">{{ $request->requested_number }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Requested {{ $request->created_at->format('Y-m-d H:i') }}</div>
                    </div>
                    <flux:select wire:model="assign.{{ $request->id }}" placeholder="Link to employee…" class="w-56">
                        @foreach ($employees as $employee)
                            <flux:select.option value="{{ $employee->id }}">{{ $employee->name }} ({{ $employee->whatsapp_number }})</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" variant="primary" wire:click="approve({{ $request->id }})">Approve</flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="reject({{ $request->id }})">Reject</flux:button>
                </div>
            @empty
                <div class="p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No pending relink requests.</div>
            @endforelse
        </div>
    </div>

    @if ($decided->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <h2 class="text-sm font-semibold">Recent decisions</h2>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($decided as $request)
                    <div class="flex items-center gap-3 p-4 text-sm">
                        <span class="grow">{{ $request->requested_number }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $request->employee?->name ?? '—' }}</span>
                        <flux:badge :color="$request->status === 'approved' ? 'green' : 'zinc'" size="sm">{{ ucfirst($request->status) }}</flux:badge>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
