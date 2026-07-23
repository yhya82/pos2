<?php

namespace App\Livewire\Layout;

use Livewire\Component;

/**
 * Quick binary light/dark switch beside the notification bell (SRS Sec.
 * 20.18) — a simpler sibling to the full light/dark/system picker in
 * Settings > Appearance, for the common case of just wanting to flip it
 * without leaving the page you're on.
 */
class ThemeToggle extends Component
{
    public string $theme;

    public function mount(): void
    {
        $this->theme = auth()->user()->theme ?? 'system';
    }

    public function toggle(): void
    {
        $this->theme = $this->theme === 'dark' ? 'light' : 'dark';

        auth()->user()->update(['theme' => $this->theme]);

        $this->dispatch('theme-changed', theme: $this->theme);
    }

    public function render()
    {
        return view('livewire.layout.theme-toggle');
    }
}
