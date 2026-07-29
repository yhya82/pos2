<?php

namespace App\Events;

use App\Models\Customer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired from CreditPaymentService::recordPayment() once the transaction has
 * committed. Credit *sales* already refresh the dashboard via SaleCompleted
 * (render() recomputes the outstanding-credit card in the same pass as
 * revenue), so this only needs to cover the other side of the ledger: a
 * customer paying down their balance outside of a sale.
 *
 * Known gap, not addressed here: voiding a credit sale reverses the balance
 * via trg_sales_void_reverses_inventory (a DB trigger), which has no PHP
 * hook to fire this event from — same class of problem as
 * NotificationCreated needing a poll-based watcher. Left alone since it
 * wasn't part of what was asked for.
 */
class CreditBalanceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Customer $customer)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'CreditBalanceChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'customer_id' => $this->customer->id,
        ];
    }
}
