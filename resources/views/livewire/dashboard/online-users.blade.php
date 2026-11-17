<article class="card">
    <div class="bh">
        <span class="ic"><x-icon name="users" /></span>
        <div class="t"><b>Online</b><span class="cnt">{{ count($onlineUsers) }}</span></div>
    </div>
    @forelse ($onlineUsers as $user)
        <div class="ln">
            <span class="av">{{ \Illuminate\Support\Str::of($user['name'])->explode(' ')->map(fn ($w) => mb_substr($w, 0, 1))->join('')->upper()->substr(0, 2) }}</span>
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
