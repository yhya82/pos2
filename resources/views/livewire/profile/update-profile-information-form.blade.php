<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public bool $editing = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->fillFrom(Auth::user());
    }

    private function fillFrom(User $user): void
    {
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone ? substr($user->phone, 4) : '';
    }

    public function edit(): void
    {
        $this->fillFrom(Auth::user());
        $this->resetValidation();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->fillFrom(Auth::user());
        $this->resetValidation();
        $this->editing = false;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'phone' => ['required', 'digits:9'],
        ], [
            'phone.digits' => 'Phone number must be exactly 9 digits (the +220 prefix is added automatically).',
        ]);

        // Uniqueness has to be checked against the full +220-prefixed value
        // actually stored in the column — the form only collects the local
        // 9 digits, so Rule::unique on that raw input would never match
        // anything and let duplicates through silently.
        $fullPhone = '+220'.$validated['phone'];

        $phoneTaken = User::where('phone', $fullPhone)->where('id', '!=', $user->id)->exists();

        if ($phoneTaken) {
            $this->addError('phone', 'This phone number is already in use.');

            return;
        }

        $validated['phone'] = $fullPhone;

        $user->fill($validated);
        $user->save();

        $this->editing = false;
        $this->dispatch('flash-message', message: 'Profile updated.', variant: 'success');
    }
}; ?>

<div class="card">
    <div class="cardhead">
        <span class="ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/></svg></span>
        <h3>{{ __('Profile Information') }}</h3>
        @unless ($editing)
            <button type="button" wire:click="edit" class="editbtn" aria-label="{{ __('Edit profile information') }}" title="{{ __('Edit') }}">
                <svg viewBox="0 0 24 24"><path d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/><path d="M15.75 6.75l2.5 2.5"/></svg>
            </button>
        @endunless
    </div>

    @if ($editing)
        <form wire:submit="updateProfileInformation" class="cardbody">
            <div class="f">
                <label for="name">{{ __('Name') }}<span class="req">*</span></label>
                <input wire:model="name" id="name" name="name" type="text" required autofocus autocomplete="name">
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>

            <div class="f">
                <label for="email">{{ __('Email') }}<span class="req">*</span></label>
                <input wire:model="email" id="email" name="email" type="email" required autocomplete="username">
                <x-input-error class="mt-2" :messages="$errors->get('email')" />
            </div>

            <div class="f" style="margin-bottom:0;">
                <label for="phone">{{ __('Phone') }}<span class="req">*</span></label>
                <div class="prefix">
                    <span>+220</span>
                    <input wire:model="phone" id="phone" name="phone" type="text" placeholder="831234567" maxlength="9" required autocomplete="tel">
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('phone')" />
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" wire:click="cancel" class="btn">{{ __('Cancel') }}</button>
                <button type="submit" class="btn p">{{ __('Save Changes') }}</button>
            </div>
        </form>
    @else
        <div class="cardbody">
            <div class="viewrow"><span class="k">{{ __('Name') }}</span><span class="v">{{ $name }}</span></div>
            <div class="viewrow"><span class="k">{{ __('Email') }}</span><span class="v">{{ $email }}</span></div>
            <div class="viewrow"><span class="k">{{ __('Phone') }}</span><span class="v">{{ $phone ? '+220'.$phone : '—' }}</span></div>
        </div>
    @endif
</div>
