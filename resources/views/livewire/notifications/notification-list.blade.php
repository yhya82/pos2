<div class="notifications-skin">
    <div class="top">
        <div>
            <h1>Notifications</h1>
            <p>Inventory, sales, customer and system alerts</p>
        </div>
        @if ($unreadCount > 0)
            <button type="button" wire:click="markAllRead" class="btn p">
                <svg viewBox="0 0 24 24" style="width:15px;height:15px"><path d="M5 13l4 4L19 7"/></svg>
                Mark all read
            </button>
        @endif
    </div>

    <div class="filters">
        <span class="lbl">Category</span>
        <select wire:model.live="categoryFilter">
            <option value="">All categories</option>
            <option value="inventory">Inventory</option>
            <option value="sales">Sales</option>
            <option value="customer">Customer</option>
            <option value="user_system">System</option>
        </select>
        <span class="lbl">Status</span>
        <select wire:model.live="readFilter">
            <option value="">All</option>
            <option value="unread">Unread</option>
            <option value="read">Read</option>
        </select>
    </div>

    <div class="nlist">
        @forelse ($notifications as $notification)
            <button type="button" wire:click="markRead({{ $notification->id }})" @class(['nitem', 'unread' => ! $notification->is_read])>
                <span class="ndot"></span>
                <div style="flex:1; min-width:0;">
                    <div class="ncat">{{ str($notification->category)->headline() }}</div>
                    <div class="nmsg">{{ $notification->message }}</div>
                </div>
                <div class="ntime">{{ $notification->created_at->format('Y-m-d g:i A') }}<br><span class="mut">{{ $notification->created_at->diffForHumans() }}</span></div>
            </button>
        @empty
            <div class="empty">No notifications found.</div>
        @endforelse
    </div>

    @if ($notifications->hasPages() || $notifications->total() > 0)
        <div class="pager">
            <span>Showing {{ $notifications->firstItem() ?? 0 }}–{{ $notifications->lastItem() ?? 0 }} of {{ $notifications->total() }}</span>
            @if ($notifications->hasPages())
                <div class="pages">
                    @foreach ($notifications->getUrlRange(max(1, $notifications->currentPage() - 2), min($notifications->lastPage(), $notifications->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $notifications->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
