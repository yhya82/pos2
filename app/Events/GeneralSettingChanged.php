<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired from SettingsManager::saveGeneral() and removeLogo(). Only
 * Pos\Terminal reads GeneralSetting::current() outside of the settings
 * screen itself (tax rate/toggle, currency code) — an idle terminal screen
 * wouldn't otherwise reflect a tax-rate change until its next unrelated
 * re-render. Purely a display-freshness fix: SaleService::completeSale()
 * always reads GeneralSetting fresh at the moment of checkout regardless
 * of what the screen showed a moment earlier, so this doesn't change what
 * was or wasn't a correctness gap.
 *
 * No-op payload — Terminal's render() already re-reads GeneralSetting
 * fresh every time.
 */
class GeneralSettingChanged implements ShouldBroadcastNow
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
        return 'GeneralSettingChanged';
    }
}
