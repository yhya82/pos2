<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired from SettingsManager::saveHardware(). Pos\Terminal reads
 * HardwareSetting::current() for the barcode-scanner toggle and
 * auto-print-receipt — same reasoning as GeneralSettingChanged: an idle
 * terminal screen wouldn't otherwise reflect a hardware-settings change
 * until its next unrelated re-render.
 *
 * No-op payload — Terminal's onLiveDataChanged() re-reads HardwareSetting
 * fresh and patches its own Alpine state via the pos-live-update browser
 * event, the same mechanism already used for stock/module/general changes.
 */
class HardwareSettingChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('settings')];
    }

    public function broadcastAs(): string
    {
        return 'HardwareSettingChanged';
    }
}
