<article class="card">
    <div class="bh">
        <span class="ic"><x-icon name="users" /></span>
        <div class="t"><b>Online</b><span class="cnt">{{ count($onlineUsers) }}</span></div>
    </div>
    @forelse ($onlineUsers as $user)
        <div class="ln">
            <span class="av">{{ collect(preg_split('/\s+/', trim($user['name'])))->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('') }}</span>
            <div class="m">
                <div class="n">{{ $user['name'] }}</div>
                @if ($user['role'] ?? null)
                    <div class="s">{{ $user['role'] }}</div>
                @endif
            </div>
            <span class="st">Online</span>
        </div>
    @empty
        <p class="none">Connecting&hellip;</p>
    @endforelse
</article>
