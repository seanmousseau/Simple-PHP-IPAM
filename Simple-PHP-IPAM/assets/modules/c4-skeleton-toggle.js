/* === v3.25.0 #855 skeleton-toggle helper ===
 * Pages opt in by setting `data-skeleton="loading"` on a container that
 * holds skeleton placeholder rows; once the real content is ready the
 * container's `data-skeleton` attribute is set to `ready`. CSS handles
 * the visual swap. This file just exposes a window-level helper for
 * page scripts to call.
 */
(function () {
    if (window.ipamSkeleton) return;
    window.ipamSkeleton = {
        loading: function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                el.setAttribute('data-skeleton', 'loading');
            });
        },
        ready: function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                el.setAttribute('data-skeleton', 'ready');
            });
        }
    };
})();
