/**
 * @file
 * Engine front-end behaviours: home infinite scroll, on-demand consensus and
 * the event-page view beacon.
 *
 * Attached by maemgaba_core from its render arrays (maemgaba_core/engine).
 * Brand behaviours such as a sticky masthead belong to the site theme.
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  /**
   * Infinite scroll for the home events grid.
   *
   * Appends the next page of teasers when a sentinel below the grid nears the
   * viewport. Pager state comes from drupalSettings.maemgabaCore.infiniteScroll,
   * which HomeController only sets on the front page.
   */
  Drupal.behaviors.maemgabaCoreInfiniteScroll = {
    attach(context) {
      once('maemgaba-infinite', '.home-events__grid[data-infinite-scroll]', context).forEach((grid) => {
        const cfg = (drupalSettings.maemgabaCore || {}).infiniteScroll;
        if (!cfg || !cfg.hasMore) {
          return;
        }

        let nextPage = cfg.nextPage;
        let hasMore = cfg.hasMore;
        let loading = false;
        let ticking = false;

        const sentinel = document.createElement('div');
        sentinel.className = 'home-events__sentinel';
        sentinel.innerHTML = '<span class="home-events__loading">' + Drupal.t('Loading more events…') + '</span>';
        grid.after(sentinel);

        const nearBottom = () =>
          (window.scrollY + window.innerHeight) > (document.documentElement.scrollHeight - 700);

        const onScroll = () => {
          if (!ticking) {
            ticking = true;
            window.requestAnimationFrame(() => {
              ticking = false;
              if (nearBottom()) {
                load();
              }
            });
          }
        };
        window.addEventListener('scroll', onScroll, { passive: true });

        async function load() {
          if (loading || !hasMore) {
            return;
          }
          loading = true;
          sentinel.classList.add('is-loading');
          try {
            const res = await fetch(cfg.moreUrlBase + nextPage, {
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (data.html) {
              const tmp = document.createElement('div');
              tmp.innerHTML = data.html;
              while (tmp.firstElementChild) {
                grid.appendChild(tmp.firstElementChild);
              }
              Drupal.attachBehaviors(grid);
            }
            hasMore = Boolean(data.has_more);
            nextPage += 1;
          }
          catch (e) {
            hasMore = false;
          }
          loading = false;
          sentinel.classList.remove('is-loading');
          if (!hasMore) {
            sentinel.remove();
            window.removeEventListener('scroll', onScroll);
          }
          // Keep filling while the (possibly tall) viewport isn't covered yet.
          else if (nearBottom()) {
            load();
          }
        }

        // Fill immediately if the initial page doesn't cover the viewport.
        if (nearBottom()) {
          load();
        }
      });
    },
  };

  /**
   * On-demand consensus synthesis for the event page.
   *
   * The event template only renders a [data-consensus-trigger] placeholder
   * for events eligible for lazy synthesis (see ConsensusController). One
   * fetch per page load, no polling: if it doesn't come back "ready" the
   * placeholder either shows a quiet message or removes itself, and a later
   * visit tries again.
   */
  Drupal.behaviors.maemgabaCoreConsensusSynthesis = {
    attach(context) {
      once('maemgaba-consensus', '[data-consensus-trigger]', context).forEach(async (el) => {
        // Route-generated URL (language prefix / base path safe).
        const url = el.getAttribute('data-consensus-url');
        if (!url) {
          return;
        }
        try {
          const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          });
          const data = await res.json();
          if (data.status === 'ready' && data.html) {
            const tmp = document.createElement('div');
            tmp.innerHTML = data.html;
            el.replaceWith(...tmp.children);
            Drupal.attachBehaviors(context);
          }
          else if (data.status === 'pending') {
            const msg = el.querySelector('.consensus-loading');
            if (msg) {
              msg.textContent = Drupal.t('There is not enough coverage yet to synthesize consensus.');
            }
          }
          else {
            el.remove();
          }
        }
        catch (e) {
          el.remove();
        }
      });
    },
  };

  /**
   * Event-page view beacon: the denominator of the click-through rate.
   *
   * The event template renders data-view-beacon only when the site logs
   * outbound clicks (ClickLog). One POST per page load, no cookie, no body.
   */
  Drupal.behaviors.maemgabaCoreViewBeacon = {
    attach(context) {
      once('maemgaba-view-beacon', '[data-view-beacon]', context).forEach((el) => {
        const url = el.getAttribute('data-view-beacon');
        if (navigator.sendBeacon) {
          navigator.sendBeacon(url);
        }
        else {
          fetch(url, { method: 'POST', keepalive: true, credentials: 'omit' }).catch(() => {});
        }
      });
    },
  };

})(Drupal, drupalSettings, once);
