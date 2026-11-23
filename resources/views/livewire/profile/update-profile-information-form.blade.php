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

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->phone = Auth::user()->phone ? substr(Auth::user()->phone, 4) : '';
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

        $this->dispatch('profile-updated', name: $user->name);
    }
}; ?>

<div class="card">
    <div class="cardhead">
        <span class="ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/></svg></span>
        <h3>{{ __('Profile Information') }}</h3>
    </div>

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

        <div class="f">
            <label for="phone">{{ __('Phone') }}<span class="req">*</span></label>
            <div class="prefix">
                <span>+220</span>
                <input wire:model="phone" id="phone" name="phone" type="text" placeholder="831234567" maxlength="9" required autocomplete="tel">
            </div>
            <x-input-error class="mt-2" :messages="$errors->get('phone')" />
        </div>

        <button type="submit" class="btn p">{{ __('Save Changes') }}</button>

        <x-action-message class="saved" on="profile-updated">
            {{ __('Saved.') }}
        </x-action-message>
    </form>
</div>
