<?php

use App\Services\AppSettings;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Settings › Branding: the project title and a global logo, editable by
 * administrators. The title overrides config('app.name') at boot (browser title,
 * sidebar, logo); the logo replaces the default mark everywhere it appears. The
 * logo file lives on the private disk and is served through the branding.logo route.
 */
new #[Layout('components.layouts.app')] #[Title('Branding')] class extends Component {
    use WithFileUploads;

    public string $title = '';

    public $logo = null;

    public function mount(): void
    {
        $this->guard();

        $this->title = (string) (AppSettings::title() ?? config('app.name', ''));
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function saveTitle(): void
    {
        $this->guard();

        $this->validate([
            'title' => ['required', 'string', 'max:120'],
        ], [
            'title.required' => 'Enter a project title.',
        ]);

        $title = trim($this->title);
        AppSettings::setTitle($title);

        // Apply for this request too, so the sidebar reflects it without a reload.
        config(['app.name' => $title]);

        app(AuditLogger::class)->record('branding.title_updated', null, ['title' => $title]);

        session()->flash('branding-saved', 'Project title updated.');
    }

    public function saveLogo(): void
    {
        $this->guard();

        $this->validate([
            'logo' => ['required', 'image', 'max:4096', 'mimes:png,jpg,jpeg,webp,gif'],
        ], [
            'logo.required' => 'Choose a logo image to upload.',
            'logo.image' => 'The logo must be an image.',
            'logo.max' => 'The logo must be 4 MB or smaller.',
        ]);

        $path = $this->logo->store('logos', 'local');

        $old = AppSettings::logo();
        AppSettings::setLogo($path);
        if ($old !== null) {
            Storage::disk('local')->delete($old);
        }

        app(AuditLogger::class)->record('branding.logo_updated', null, ['path' => $path]);

        $this->reset('logo');
        session()->flash('branding-saved', 'Logo updated.');
    }

    public function removeLogo(): void
    {
        $this->guard();

        $old = AppSettings::logo();
        AppSettings::setLogo(null);
        if ($old !== null) {
            Storage::disk('local')->delete($old);
        }

        app(AuditLogger::class)->record('branding.logo_removed');

        session()->flash('branding-saved', 'Logo removed — the default mark is used again.');
    }

    public function with(): array
    {
        return [
            'currentTitle' => AppSettings::title(),
            'logoPath' => AppSettings::logo(),
        ];
    }
}; ?>

<div class="flex flex-col items-start">
    <div class="mb-6 w-full text-start">
        <flux:heading size="xl">Settings</flux:heading>
        <flux:subheading>Manage your profile and account settings</flux:subheading>
    </div>

    <x-settings.layout heading="Branding" subheading="The project name and logo shown across the app">
        <div class="flex flex-col gap-6">
            @if (session('branding-saved'))
                <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('branding-saved') }}</div>
            @endif

            {{-- Project title --}}
            <form wire:submit="saveTitle" class="flex flex-col gap-3">
                <flux:input wire:model="title" label="Project Title" placeholder="e.g. Helpdesk" />
                <p class="-mt-1 text-xs text-fg-subtle">Shown in the browser tab, sidebar and login screens. Leave as-is to keep the server default.</p>
                <div>
                    <flux:button type="submit" variant="primary" icon="pencil-square">Save title</flux:button>
                </div>
            </form>

            <flux:separator />

            {{-- Logo --}}
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-4">
                    <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-surface-muted">
                        @if ($logoPath)
                            <img src="{{ route('branding.logo') }}" alt="{{ config('app.name') }}" class="size-full object-contain" />
                        @else
                            <x-app-logo-icon class="size-8 text-accent" />
                        @endif
                    </div>
                    <div class="text-sm">
                        <h3 class="font-semibold">Current logo</h3>
                        <p class="text-fg-muted">{{ $logoPath ? 'A custom logo is in use.' : 'Using the built-in mark. Upload a PNG, JPG, WebP or GIF up to 4 MB.' }}</p>
                    </div>
                </div>

                @if ($logo?->isPreviewable())
                    <div wire:loading.remove>
                        <img src="{{ $logo->temporaryUrl() }}" alt="Preview" class="size-16 rounded-lg border border-border object-contain" />
                    </div>
                @endif

                <form wire:submit="saveLogo" class="flex flex-col gap-3">
                    <flux:input wire:model="logo" type="file" accept="image/*" label="Upload logo" />
                    @error('logo') <p class="-mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2">
                        <flux:button type="submit" variant="primary" icon="arrow-up-tray">Upload logo</flux:button>
                        @if ($logoPath)
                            <flux:button wire:click="removeLogo" variant="subtle">Remove logo</flux:button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </x-settings.layout>
</div>
