<?php

use App\Models\Category;
use App\Models\KbArticle;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Knowledge base (PRD Phase 10, Rec #14). Administrators author short self-help
 * articles tagged to a request category; only published ones are suggested to
 * requesters on the intake form, the receipt and the tracking page.
 */
new #[Layout('components.layouts.app')] #[Title('Knowledge Base')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $fTitle = '';

    public string $fBody = '';

    public string $fCategory = '';

    public bool $fPublished = false;

    public function mount(): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function startCreate(): void
    {
        $this->guard();

        $this->reset(['editingId', 'fTitle', 'fBody', 'fCategory']);
        $this->fPublished = false;
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $this->guard();

        $article = KbArticle::findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $article->id;
        $this->fTitle = $article->title;
        $this->fBody = $article->body;
        $this->fCategory = (string) $article->category_key;
        $this->fPublished = $article->published;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->reset(['showForm', 'editingId', 'fTitle', 'fBody', 'fCategory']);
        $this->fPublished = false;
    }

    public function save(): void
    {
        $this->guard();

        $this->validate([
            'fTitle' => ['required', 'string', 'max:160'],
            'fBody' => ['required', 'string', 'max:5000'],
            'fCategory' => ['nullable', 'string', 'max:40'],
        ]);

        $article = $this->editingId ? KbArticle::find($this->editingId) : new KbArticle;
        $article->fill([
            'title' => trim($this->fTitle),
            'body' => trim($this->fBody),
            'category_key' => $this->fCategory !== '' ? $this->fCategory : null,
            'published' => $this->fPublished,
            'created_by' => $article->created_by ?? auth()->id(),
        ]);
        $article->save();

        app(AuditLogger::class)->record(
            $this->editingId ? 'kb_article.updated' : 'kb_article.created',
            $article,
            ['title' => $article->title, 'published' => $article->published],
        );

        $this->cancel();
        session()->flash('success', 'Article saved.');
    }

    public function toggle(int $id): void
    {
        $this->guard();

        $article = KbArticle::findOrFail($id);
        $article->update(['published' => ! $article->published]);

        app(AuditLogger::class)->record('kb_article.toggled', $article, ['published' => $article->published]);

        session()->flash('success', $article->published ? 'Article published.' : 'Article unpublished.');
    }

    public function delete(int $id): void
    {
        $this->guard();

        $article = KbArticle::findOrFail($id);
        $title = $article->title;
        $article->delete();

        app(AuditLogger::class)->record('kb_article.deleted', null, ['title' => $title]);

        session()->flash('success', 'Article deleted.');
    }

    public function with(): array
    {
        return [
            'articles' => KbArticle::query()->latest('updated_at')->get(),
            'categories' => Category::query()->active()->ordered()->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Knowledge Base" subtitle="Self-help articles suggested to requesters at intake and on their tracking link.">
        <x-slot:actions>
            <flux:button wire:click="startCreate" variant="primary" icon="plus">New article</flux:button>
        </x-slot:actions>
    </x-page.header>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
    @endif

    @if ($showForm)
        <div class="card p-4">
            <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit article' : 'New article' }}</h2>
            <div class="mt-3 flex flex-col gap-3">
                <flux:input wire:model="fTitle" label="Title" placeholder="e.g. Clear your print queue" />
                <flux:select wire:model="fCategory" label="Category (optional)">
                    <flux:select.option value="">General — every category</flux:select.option>
                    @foreach ($categories as $case)
                        <flux:select.option value="{{ $case->key }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model="fBody" label="Body" rows="5" placeholder="Plain-language steps the requester can follow." />
                <flux:checkbox wire:model="fPublished" label="Published (visible to requesters)" />
            </div>
            @error('fTitle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('fBody') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <div class="mt-3 flex gap-2">
                <flux:button wire:click="save" variant="primary">Save</flux:button>
                <flux:button wire:click="cancel" variant="subtle">Cancel</flux:button>
            </div>
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-muted text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Title</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Category</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Status</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($articles as $article)
                        <tr>
                            <td class="px-4 py-2 font-medium">{{ $article->title }}</td>
                            <td class="px-4 py-2 text-fg-muted">{{ $article->category_key ? ($categories->firstWhere('key', $article->category_key)?->label() ?? $article->category_key) : 'General' }}</td>
                            <td class="px-4 py-2"><flux:badge :color="$article->published ? 'green' : 'zinc'" size="sm">{{ $article->published ? 'Published' : 'Draft' }}</flux:badge></td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="xs" variant="subtle" wire:click="startEdit({{ $article->id }})">Edit</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="toggle({{ $article->id }})">{{ $article->published ? 'Unpublish' : 'Publish' }}</flux:button>
                                    <flux:button size="xs" variant="ghost" class="text-red-600" wire:click="delete({{ $article->id }})" wire:confirm="Delete this article?">Delete</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-fg-muted">No articles yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
