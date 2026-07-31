<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired from SettingsManager::toggleModule(). Several Livewire components
 * gate what they show/allow behind ModuleSetting::enabled() at render time
 * (Terminal's credit payment option, Dashboard's credit/returns cards,
 * Customer screens' credit tab, ReportViewer) — without this, an
 * already-open session keeps offering an action that's about to be
 * rejected server-side the moment someone else disables the module,
 * instead of the option disappearing immediately.
 *
 * No-op payload: every consumer already re-derives its gate fresh from
 * ModuleSetting::enabled() on render(), so this only needs to trigger that
 * re-render.
 */
class ModuleSettingChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $moduleName, public bool $isEnabled)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('settings')];
    }

    public function broadcastAs(): string
    {
        return 'ModuleSettingChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'module_name' => $this->moduleName,
            'is_enabled' => $this->isEnabled,
        ];
    }
}
