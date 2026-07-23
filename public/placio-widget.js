/**
 * Placio Widget SDK
 *
 * Requires place-render.js (loaded automatically from the same origin).
 *
 * Usage:
 *   <script src="/placio-widget.js"></script>
 *   <script>
 *     new Placio.SeatingChart({
 *       divId: 'my-div',
 *       workspaceKey: 'pk_pub_...',
 *       event: 'event-uuid-or-external-id',
 *       onSeatSelected:   (seat) => {},
 *       onSeatDeselected: (seat) => {},
 *       onCheckout:       (seats) => {},
 *     }).render();
 *   </script>
 */

(function (global) {
  'use strict';

  // Derive the API base URL from the script's own src attribute
  const API_BASE = (function () {
    const scripts = document.querySelectorAll('script[src*="placio-widget"]');
    if (scripts.length) {
      try { return new URL(scripts[scripts.length - 1].src).origin; } catch (_) {}
    }
    return '';
  })();

  // Ensure place-render.js is loaded before we try to use PlaceRender
  function ensurePlaceRender() {
    if (global.PlaceRender) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const existing = document.querySelector(`script[src*="place-render"]`);
      if (existing) {
        existing.addEventListener('load', resolve, { once: true });
        existing.addEventListener('error', reject,  { once: true });
        return;
      }
      const s = document.createElement('script');
      s.src = `${API_BASE}/place-render.js`;
      s.async = true;
      s.onload  = resolve;
      s.onerror = () => reject(new Error('Impossible de charger place-render.js'));
      document.head.appendChild(s);
    });
  }

  // ─── SeatingChart ─────────────────────────────────────────────────────────

  class SeatingChart {
    constructor(options) {
      this.divId            = options.divId;
      this.workspaceKey     = options.workspaceKey;
      this.eventId          = options.event;
      this.onSeatSelected   = options.onSeatSelected   || null;
      this.onSeatDeselected = options.onSeatDeselected || null;
      this.onCheckout       = options.onCheckout       || null;
      // { [catId]: { price: number, currency: string } } — injected by the host page
      this.categoryPrices   = options.categoryPrices   || {};

      this._sessionToken  = null;
      this._holdToken     = null;
      this._placioEventId = null;
      this._eventData     = null;
      this._renderer      = null;
    }

    async render() {
      const container = document.getElementById(this.divId);
      if (!container) { console.error('[Placio] div not found:', this.divId); return; }

      container.innerHTML = '';
      Object.assign(container.style, {
        position: 'relative', overflow: 'hidden',
        fontFamily: 'system-ui, sans-serif',
        background: '#f8fafc',
        width:  container.style.width  || '100%',
        height: container.style.height || '100%',
      });

      // Loading indicator
      const loader = Object.assign(document.createElement('div'), {
        textContent: 'Chargement du plan…',
      });
      Object.assign(loader.style, {
        position: 'absolute', inset: '0', display: 'flex',
        alignItems: 'center', justifyContent: 'center',
        color: '#9ca3af', fontSize: '14px',
      });
      container.appendChild(loader);

      try {
        await ensurePlaceRender();
        await this._createSession();
        await this._loadEvent();

        container.innerHTML = '';

        this._renderer = new global.PlaceRender({
          container,
          data: {
            chartObjects:   this._eventData.chartObjects || [],
            categories:     this._eventData.categories   || [],
            seats:          this._eventData.seats         || [],
            categoryPrices: this.categoryPrices,
          },
          onSeatSelected:   this.onSeatSelected,
          onSeatDeselected: this.onSeatDeselected,
        });
        this._renderer.render();

        if (this.onCheckout) this._renderCheckoutBar(container);

        // Subscribe to Mercure for real-time seat status updates
        if (this._eventData.mercurePublicUrl && this._placioEventId) {
          this._subscribeMercure(this._eventData.mercurePublicUrl, this._placioEventId);
        }

      } catch (e) {
        container.innerHTML = `<div style="padding:2rem;color:#ef4444;font-size:14px;">${e.message}</div>`;
      }
    }

    // ── session / event ─────────────────────────────────────────────────────

    async _createSession() {
      const res = await fetch(`${API_BASE}/api/public/events/${this.eventId}/session`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ publicKeyId: this.workspaceKey }),
      });
      if (!res.ok) throw new Error('Clé publique invalide ou événement introuvable');
      const data = await res.json();
      this._sessionToken  = data.sessionToken;
      this._holdToken     = data.holdToken;
      this._placioEventId = data.eventId || this.eventId;
    }

    async _loadEvent() {
      const res = await fetch(`${API_BASE}/api/widget/events/${this._placioEventId}`, {
        headers: { Authorization: `Widget ${this._sessionToken}` },
      });
      if (!res.ok) throw new Error('Impossible de charger l\'événement');
      this._eventData = await res.json();
    }

    // ── checkout bar ────────────────────────────────────────────────────────

    _renderCheckoutBar(container) {
      const bar = Object.assign(document.createElement('div'), {});
      Object.assign(bar.style, {
        position: 'absolute', bottom: '0', left: '0', right: '0',
        minHeight: '56px', background: '#fff',
        borderTop: '1px solid #e5e7eb',
        display: 'flex', alignItems: 'center',
        justifyContent: 'space-between', padding: '8px 16px',
        zIndex: '30', gap: '12px', flexWrap: 'wrap',
      });

      // Left: seat summary grouped by category
      const infoWrap = Object.assign(document.createElement('div'), {});
      Object.assign(infoWrap.style, {
        display: 'flex', flexDirection: 'column', gap: '4px', flex: '1',
      });
      const infoEmpty = Object.assign(document.createElement('span'), {
        textContent: 'Aucun siège sélectionné',
      });
      Object.assign(infoEmpty.style, { fontSize: '13px', color: '#9ca3af' });
      infoWrap.appendChild(infoEmpty);

      const btn = Object.assign(document.createElement('button'), {
        textContent: 'Confirmer la sélection',
        disabled: true,
      });
      Object.assign(btn.style, {
        background: '#6366f1', color: '#fff', border: 'none',
        borderRadius: '8px', padding: '10px 20px',
        fontSize: '14px', fontWeight: '600', cursor: 'default', opacity: '0.5',
        whiteSpace: 'nowrap', flexShrink: '0',
      });
      btn.addEventListener('click', () => {
        if (!this._renderer) return;
        const seats = this._renderer.getSelectedSeats();
        if (seats.length && this.onCheckout) this.onCheckout(seats);
      });

      bar.appendChild(infoWrap);
      bar.appendChild(btn);
      container.appendChild(bar);
      this._checkoutBar = { bar, infoWrap, infoEmpty, btn };

      // PlaceRender calls this hook on every selection change
      this._renderer._onSelectionChange = () => this._updateCheckoutBar();
    }

    _updateCheckoutBar() {
      if (!this._checkoutBar || !this._renderer) return;
      const seats = this._renderer.getSelectedSeats(); // [{ seatKey, catId, catColor, catName }]
      const { infoWrap, infoEmpty, btn } = this._checkoutBar;

      infoWrap.innerHTML = '';

      if (seats.length === 0) {
        infoWrap.appendChild(infoEmpty);
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor  = 'default';
        btn.style.background = '#6366f1';
        return;
      }

      // Group by category
      const bycat = {};
      for (const s of seats) {
        const id = s.catId || '__none__';
        if (!bycat[id]) bycat[id] = { name: s.catName, color: s.catColor, count: 0 };
        bycat[id].count++;
      }

      for (const { name, color, count } of Object.values(bycat)) {
        const row = Object.assign(document.createElement('div'), {});
        Object.assign(row.style, {
          display: 'flex', alignItems: 'center', gap: '8px',
        });

        const dot = Object.assign(document.createElement('span'), {});
        Object.assign(dot.style, {
          width: '10px', height: '10px', borderRadius: '50%',
          background: color, flexShrink: '0',
          boxShadow: `0 0 0 2px ${color}33`,
        });

        const label = Object.assign(document.createElement('span'), {
          textContent: `${name || 'Catégorie'} — ${count} siège${count > 1 ? 's' : ''}`,
        });
        Object.assign(label.style, {
          fontSize: '13px', fontWeight: '600', color: '#111827',
        });

        row.appendChild(dot);
        row.appendChild(label);
        infoWrap.appendChild(row);
      }

      // Button color = color of first category
      const firstCat = Object.values(bycat)[0];
      btn.disabled = false;
      btn.style.opacity    = '1';
      btn.style.cursor     = 'pointer';
      btn.style.background = firstCat.color || '#6366f1';
    }

    // ── Mercure real-time ────────────────────────────────────────────────────

    _subscribeMercure(hubUrl, eventId) {
      const topic = encodeURIComponent(`event/${eventId}/seats`);
      const url   = `${hubUrl}?topic=${topic}`;
      const es    = new EventSource(url, { withCredentials: false });

      es.onmessage = (e) => {
        try {
          const { seatKeys, status } = JSON.parse(e.data);
          if (!Array.isArray(seatKeys) || !status) return;
          const seats = seatKeys.map(sk => ({ seatKey: sk, status }));
          if (this._renderer) this._renderer.updateSeats(seats);
        } catch (_) {}
      };

      this._mercureEs = es;
    }

    // ── public API ──────────────────────────────────────────────────────────

    /** Returns [{ seatKey, catId, catColor, catName }] */
    getSelectedSeats() { return this._renderer ? this._renderer.getSelectedSeats() : []; }
    getSessionToken()  { return this._sessionToken; }
    getHoldToken()     { return this._holdToken; }
  }

  global.Placio = { SeatingChart };

})(window);
