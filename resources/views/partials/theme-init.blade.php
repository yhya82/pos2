{{-- Runs before the stylesheet paints anything, to avoid a flash of the
     wrong theme. A logged-in user's stored preference (Settings >
     Appearance, SRS Sec. 20.18) wins. Signed-out visitors have no user
     row to store a preference on, so the login page's theme toggle
     keeps its own choice in localStorage instead — checked next, ahead
     of the final OS-preference fallback. --}}
<script>
    (function () {
        var stored = @json(auth()->check() ? auth()->user()->theme : null);
        var dark;
        if (stored === 'dark') { dark = true; }
        else if (stored === 'light') { dark = false; }
        else {
            var guestPref = null;
            try { guestPref = localStorage.getItem('login-theme'); } catch (e) {}
            if (guestPref === 'dark') { dark = true; }
            else if (guestPref === 'light') { dark = false; }
            else { dark = window.matchMedia('(prefers-color-scheme: dark)').matches; }
        }
        if (dark) { document.documentElement.classList.add('dark'); }
    })();
</script>
