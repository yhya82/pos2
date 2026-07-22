{{-- Runs before the stylesheet paints anything, to avoid a flash of the
     wrong theme. A logged-in user's stored preference (Settings >
     Appearance, SRS Sec. 20.18) wins; otherwise this follows the OS. --}}
<script>
    (function () {
        var stored = @json(auth()->check() ? auth()->user()->theme : null);
        var dark;
        if (stored === 'dark') { dark = true; }
        else if (stored === 'light') { dark = false; }
        else { dark = window.matchMedia('(prefers-color-scheme: dark)').matches; }
        if (dark) { document.documentElement.classList.add('dark'); }
    })();
</script>
