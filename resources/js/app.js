import './bootstrap';
import './echo';

/**
 * A global Alpine store, not local x-data, specifically because
 * wire:navigate swaps in a whole fresh document per page — any state that
 * lived in x-data on a per-page element got reset to its initial value on
 * every navigation. Alpine itself (and this store) survives wire:navigate
 * since it's a client-side transition, not a real page reload.
 *
 * Backed by localStorage on top of that, deliberately — belt and braces.
 * A pure in-memory store only survives as long as the SPA-style
 * wire:navigate interception actually fires for every transition; a plain
 * link somehow falling back to a real browser navigation (or a hard
 * refresh) would otherwise silently reset the collapsed state back to
 * expanded. Reading the last value from localStorage on init means the
 * correct state is restored immediately either way, before first paint.
 */
document.addEventListener('alpine:init', () => {
    let storedCollapsed = false;

    try {
        storedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    } catch (e) {
        // Storage inaccessible (private browsing, disabled, etc.) — fall
        // back to the in-memory default rather than breaking the page.
    }

    Alpine.store('sidebar', {
        open: false,
        collapsed: storedCollapsed,

        toggleCollapsed() {
            this.collapsed = !this.collapsed;

            try {
                localStorage.setItem('sidebarCollapsed', this.collapsed ? 'true' : 'false');
            } catch (e) {
                // Ignore — the in-memory value still updated, this session
                // just won't remember the preference for next time.
            }
        },
    });
});
