/**
 * PlaceRender — Standalone seating chart renderer
 * Rendering ported from place-ui/src/admin/components/PreviewPlan.vue
 * Seat-key formula mirrors place-symfony EventService::createSeatsFromObjects
 *
 *   const r = new PlaceRender({ container, data, onSeatSelected, onSeatDeselected, readOnly });
 *   r.render();
 *   r.updateSeats(seats);
 *   r.getSelectedSeats();   // → [{ seatKey, catId, catColor, catName }]
 *   r.destroy();
 */
(function (global) {
  'use strict';

  // ─── label helpers (port of place-ui/src/services/seatLabel.js) ──────────────

  function _letters(n, upper) {
    let s = '', x = n;
    do { s = String.fromCharCode((upper ? 65 : 97) + (x % 26)) + s; x = Math.floor(x / 26) - 1; } while (x >= 0);
    return s;
  }
  const _ROM = [[1000,'M'],[900,'CM'],[500,'D'],[400,'CD'],[100,'C'],[90,'XC'],[50,'L'],[40,'XL'],[10,'X'],[9,'IX'],[5,'V'],[4,'IV'],[1,'I']];
  function _roman(n) { let v = n+1, r=''; for(const[a,b] of _ROM){while(v>=a){r+=b;v-=a;}} return r||String(n+1); }
  function axisLabel(idx, total, fmt, dir) {
    const i = dir === 'reversed' ? Math.max(0, total - 1 - idx) : idx;
    return fmt === 'A-Z' ? _letters(i,true) : fmt === 'a-z' ? _letters(i,false) : fmt === 'I-X' ? _roman(i) : String(i+1);
  }
  function seatLabel(ri, ci, rows, cols, cfg) {
    return axisLabel(ri, rows, cfg.rowFormat||'A-Z', cfg.rowDirection||'normal')
         + axisLabel(ci, cols, cfg.colFormat||'1-9', cfg.colDirection||'normal');
  }

  // ─── seat-key formulas (mirrors EventService.php) ────────────────────────────
  function seatRowKey(obj, ri, ci) {
    const s = obj.section||obj.label||obj.id||'S';
    return `${s}-${axisLabel(ri,obj.rows,obj.rowFormat,obj.rowDirection)}-${axisLabel(ci,obj.cols,obj.colFormat,obj.colDirection)}`;
  }
  function tableSectionKey(obj, ti, si) { return `${obj.section||obj.label||obj.id||'TS'}-${ti+1}-${si+1}`; }
  function tableZoneKey(obj, i)          { return `${obj.section||obj.label||obj.id||'T'}-${i+1}`; }

  // ─── geometry helpers ─────────────────────────────────────────────────────────
  const TS_PAD = 4;
  function tableZoneSize(t)    { return (t.tableSize||30) + 2*(t.seatSize||15) + 16; }
  function tsSectionUnit(ts)   { return (ts.tableSize||30) + 2*(ts.seatSize||15) + 16; }
  function tsSectionWidth(ts)  { const u=tsSectionUnit(ts),n=ts.tableCount||3,sp=ts.tableSpacing??2; return n*u+(n-1)*sp+2*TS_PAD; }
  function tsSectionHeight(ts) { const u=tsSectionUnit(ts),r=ts.tableRows||1, sp=ts.tableSpacing??2; return r*u+(r-1)*sp+2*TS_PAD; }

  function computeBbox(objects) {
    let x0=Infinity,y0=Infinity,x1=-Infinity,y1=-Infinity;
    for (const o of objects) {
      const x=o.left||0, y=o.top||0; let w=0,h=0;
      const ss=o.seatSize||22, g=o.seatGap??4;
      if (o._type==='zone'||o._type==='freeZone')          { w=o.width||80;  h=o.height||60; }
      else if (o._type==='seatRow')                        { w=(o.cols||1)*(ss+g); h=(o.rows||1)*(ss+g)+14; }
      else if (o._type==='tableZone')                      { const s=tableZoneSize(o); w=s; h=s; }
      else if (o._type==='tableSection')                   { w=tsSectionWidth(o); h=tsSectionHeight(o); }
      x0=Math.min(x0,x); y0=Math.min(y0,y); x1=Math.max(x1,x+w); y1=Math.max(y1,y+h);
    }
    if(!isFinite(x0)) return {minX:0,minY:0,w:400,h:300};
    const p=40; return {minX:x0-p,minY:y0-p,w:x1-x0+p*2,h:y1-y0+p*2};
  }

  // ─── DOM / color helpers ──────────────────────────────────────────────────────
  function el(tag) { return document.createElement(tag); }
  function css(e, s) { Object.assign(e.style, s); return e; }
  function rgba(color, a) {
    if (!color||color[0]!=='#') return `rgba(156,163,175,${a})`;
    return `rgba(${parseInt(color.slice(1,3),16)},${parseInt(color.slice(3,5),16)},${parseInt(color.slice(5,7),16)},${a})`;
  }

  // ─── PlaceRender ─────────────────────────────────────────────────────────────
  class PlaceRender {
    constructor(opts) {
      this._root      = typeof opts.container==='string' ? document.getElementById(opts.container) : opts.container;
      this._data      = opts.data || {};
      this._onSel     = opts.onSeatSelected   || null;
      this._onDesel   = opts.onSeatDeselected || null;
      this._readOnly  = opts.readOnly || false;
      this._selected  = new Set(opts.selectedSeats || []);

      this._catMap     = {};
      this._statusMap  = {};
      this._seatCatMap = {};   // seatKey → catId

      this._bbox      = null;
      this._zoom      = 1;
      this._panX      = 0;
      this._panY      = 0;
      this._dragging  = false;
      this._dragStart = {x:0,y:0};
      this._didDrag   = false;

      this._canvas    = null;
      this._viewport  = null;
      this._tooltip   = null;
      this._mmWrap    = null;
      this._mmRect    = null;
      this._zoomBadge = null;
      this._fsBtn     = null;
      this._cw        = 0;
      this._ch        = 0;

      this._seatSectionMap = {}; // seatKey → section label
      this._lensWrap  = null;
      this._minZoom   = 0.1;
      this._animFrame = null;

      // set by widget to refresh checkout bar on selection change
      this._onSelectionChange = null;

      this._boundMove = this._onPointerMove.bind(this);
      this._boundUp   = this._onPointerUp.bind(this);
    }

    // ── public ──────────────────────────────────────────────────────────────────

    render() {
      this._injectBounceStyle();
      this._buildMaps();
      this._setupDOM();
      this._drawAll();
      this._fitToContainer();
      this._updateMinimap();
      return this;
    }

    updateSeats(seats) {
      // Full replace when called with all seats (initial load); merge when called with a partial list (Mercure)
      for (const s of seats||[]) this._statusMap[s.seatKey] = s.status;
      this._refreshColors();
    }

    /** Replace the full seat status map (initial load only) */
    _resetSeats(seats) {
      this._statusMap = {};
      for (const s of seats||[]) this._statusMap[s.seatKey] = s.status;
    }

    /** Returns [{ seatKey, catId, catColor, catName }] */
    getSelectedSeats() {
      return [...this._selected].map(key => {
        const catId = this._seatCatMap[key] || null;
        return { seatKey:key, catId, catColor:this._catColor(catId), catName:this._catName(catId) };
      });
    }

    destroy() {
      document.removeEventListener('pointermove', this._boundMove);
      document.removeEventListener('pointerup',   this._boundUp);
      if (this._root) this._root.innerHTML = '';
    }

    // ── maps ────────────────────────────────────────────────────────────────────

    _buildMaps() {
      for (const c of this._data.categories||[]) this._catMap[c.id] = c;
      // Merge external price data (keyed by catId) into catMap
      for (const [catId, info] of Object.entries(this._data.categoryPrices||{})) {
        if (this._catMap[catId]) Object.assign(this._catMap[catId], info);
      }
      for (const s of this._data.seats||[]) this._statusMap[s.seatKey] = s.status;
    }

    _catColor(id) { return this._catMap[id]?.color || '#6366f1'; }
    _catName(id)  { return this._catMap[id]?.name  || ''; }
    _bookingStatus(key) { return this._statusMap[key] || 'available'; }

    // ── DOM setup ───────────────────────────────────────────────────────────────

    _injectBounceStyle() {
      if (document.getElementById('pr-animate-css')) return;
      const l = document.createElement('link');
      l.id   = 'pr-animate-css';
      l.rel  = 'stylesheet';
      l.href = 'https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css';
      document.head.appendChild(l);
    }

    _setupDOM() {
      const root = this._root;
      root.innerHTML = '';
      css(root, {
        position:'relative', overflow:'hidden', fontFamily:'system-ui,sans-serif',
        background:'#ffffff', userSelect:'none',
        width:root.style.width||'100%', height:root.style.height||'100%',
      });
      this._cw = root.clientWidth  || 600;
      this._ch = root.clientHeight || 500;
      this._bbox = computeBbox(this._data.chartObjects||[]);

      const vp = css(el('div'), {position:'absolute',inset:'0',overflow:'hidden',cursor:'grab',touchAction:'none'});
      root.appendChild(vp);
      this._viewport = vp;

      const canvas = css(el('div'), {position:'absolute',top:'0',left:'0',transformOrigin:'0 0',background:'#ffffff'});
      vp.appendChild(canvas);
      this._canvas = canvas;

      this._tooltip = this._buildTooltip(root);
      this._buildFullscreenBtn(root);
      this._buildControls(root);
      this._buildMinimap(root);
      this._buildLens(root);

      vp.addEventListener('wheel',       this._onWheel.bind(this), {passive:false});
      vp.addEventListener('pointerdown', this._onPointerDown.bind(this));
      document.addEventListener('pointermove', this._boundMove);
      document.addEventListener('pointerup',   this._boundUp);
    }

    // ── zoom / pan ──────────────────────────────────────────────────────────────

    _overviewZoom() {
      // Initial zoom = min(fit-to-container, 50%) so the plan always fills the div
      const {w, h} = this._bbox;
      const fit = Math.min(this._cw / w, this._ch / h) * 0.92;
      return Math.min(fit, 0.5);
    }

    _fitToContainer() {
      const {w,h,minX,minY} = this._bbox;
      const scale = this._overviewZoom();
      this._minZoom = scale;
      this._zoom = scale;
      this._panX = -minX*scale + (this._cw - w*scale)/2;
      this._panY = -minY*scale + (this._ch - h*scale)/2;
      this._applyTransform();
    }

    // Animate back to overview zoom centered on the plan
    _zoomToFit50(dur) {
      const {w,h,minX,minY} = this._bbox;
      const scale = this._overviewZoom();
      const px2 = -minX*scale + (this._cw - w*scale)/2;
      const py2 = -minY*scale + (this._ch - h*scale)/2;
      this._animateZoom(scale, px2, py2, dur || 380);
    }

    _applyTransform(animated) {
      if (animated) {
        this._canvas.style.transition = 'none';
      } else {
        this._canvas.style.transition = 'none';
      }
      this._canvas.style.transform = `translate(${this._panX}px,${this._panY}px) scale(${this._zoom})`;
      if (this._mmWrap) this._mmWrap.style.display = this._zoom > 1 ? 'block' : 'none';
      this._updateZoomOutBtn();
      this._updateLens();
    }

    // ── animation ───────────────────────────────────────────────────────────────

    _animateZoom(z2, px2, py2, dur) {
      dur = dur || 340;
      if (this._animFrame) { cancelAnimationFrame(this._animFrame); this._animFrame = null; }
      // Hide lens during animation: cloneNode(true) every rAF frame = jank
      if (this._lensWrap) this._lensWrap.innerHTML = '';
      const z1 = this._zoom, px1 = this._panX, py1 = this._panY;
      const t0 = performance.now();
      const tick = (now) => {
        let t = Math.min(1, (now - t0) / dur);
        t = 1 - Math.pow(1 - t, 3); // ease-out cubic
        this._zoom = z1 + (z2 - z1) * t;
        this._panX = px1 + (px2 - px1) * t;
        this._panY = py1 + (py2 - py1) * t;
        this._canvas.style.transform = `translate(${this._panX}px,${this._panY}px) scale(${this._zoom})`;
        if (this._mmWrap) this._mmWrap.style.display = this._zoom > 1 ? 'block' : 'none';
        this._updateMinimap();
        this._updateZoomOutBtn();
        if (t < 1) {
          this._animFrame = requestAnimationFrame(tick);
        } else {
          this._animFrame = null;
          this._updateLens(); // rebuild lens once, only when animation is complete
        }
      };
      this._animFrame = requestAnimationFrame(tick);
    }

    _zoomCenteredOn(cx, cy, ratio) {
      const nz = Math.max(this._minZoom, Math.min(6, this._zoom*ratio));
      const r  = nz/this._zoom;
      this._panX = cx - r*(cx - this._panX);
      this._panY = cy - r*(cy - this._panY);
      this._zoom = nz;
      this._applyTransform(true);
      this._updateMinimap();
    }

    _zoomCenteredOnSmooth(cx, cy, ratio) {
      const nz  = Math.max(this._minZoom, Math.min(6, this._zoom * ratio));
      const r   = nz / this._zoom;
      const px2 = cx - r * (cx - this._panX);
      const py2 = cy - r * (cy - this._panY);
      this._animateZoom(nz, px2, py2);
    }

    _zoomToLevel(targetZoom, cx, cy) {
      const z2  = Math.max(this._minZoom, Math.min(6, targetZoom));
      const r   = z2 / this._zoom;
      const px2 = cx - r * (cx - this._panX);
      const py2 = cy - r * (cy - this._panY);
      this._animateZoom(z2, px2, py2, 280);
    }

    // Zoom to fit a canvas-local bounding box in the viewport
    _zoomToFitBbox(bx, by, bw, bh) {
      const pad   = 60;
      const z2    = Math.min((this._cw - pad*2) / Math.max(bw, 1), (this._ch - pad*2) / Math.max(bh, 1), 5);
      const px2   = -(bx + bw/2) * z2 + this._cw / 2;
      const py2   = -(by + bh/2) * z2 + this._ch / 2;
      this._animateZoom(Math.max(this._minZoom, z2), px2, py2, 420);
    }

    _onWheel(e) {
      e.preventDefault();
      const r = this._viewport.getBoundingClientRect();
      this._zoomCenteredOn(e.clientX - r.left, e.clientY - r.top, e.deltaY < 0 ? 1.12 : 1/1.12);
    }

    _onPointerDown(e) {
      if (e.button !== 0) return;
      this._dragging  = true;
      this._didDrag   = false;
      this._dragStart = {x: e.clientX - this._panX, y: e.clientY - this._panY};
      this._viewport.style.cursor = 'grabbing';
    }

    _onPointerMove(e) {
      if (!this._dragging) return;
      const dx = e.clientX - this._dragStart.x - this._panX;
      const dy = e.clientY - this._dragStart.y - this._panY;
      if (Math.abs(dx)+Math.abs(dy) > 3) this._didDrag = true;
      this._panX = e.clientX - this._dragStart.x;
      this._panY = e.clientY - this._dragStart.y;
      this._applyTransform();
      this._updateMinimap();
    }

    _onPointerUp() {
      if (!this._dragging) return;
      this._dragging = false;
      this._viewport.style.cursor = 'grab';
    }

    // ── fullscreen button (top-right) ────────────────────────────────────────────

    _buildFullscreenBtn(root) {
      const btn = css(el('button'), {
        position:'absolute', top:'10px', right:'10px', zIndex:'110',
        display:'inline-flex', alignItems:'center', gap:'6px',
        padding:'6px 12px', border:'none', borderRadius:'999px',
        background:'rgba(255,255,255,0.92)', backdropFilter:'blur(4px)',
        boxShadow:'0 1px 6px rgba(0,0,0,0.10), 0 0 0 1px rgba(0,0,0,0.07)',
        cursor:'pointer', fontSize:'13px', fontWeight:'500', color:'#1f2937',
        transition:'background 0.15s',
        whiteSpace:'nowrap',
      });
      const iconExpand = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>`;
      const iconShrink = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>`;
      const span = el('span');
      const setFs = (inFs) => {
        btn.innerHTML = '';
        const ic = el('span'); ic.innerHTML = inFs ? iconShrink : iconExpand;
        ic.style.display = 'flex';
        btn.appendChild(ic);
        span.textContent = inFs ? 'Quitter le plein écran' : 'Plein écran';
        btn.appendChild(span);
      };
      setFs(false);
      btn.addEventListener('mouseenter', () => btn.style.background = '#ffffff');
      btn.addEventListener('mouseleave', () => btn.style.background = 'rgba(255,255,255,0.92)');
      btn.addEventListener('click', () => {
        if (document.fullscreenElement === root) {
          document.exitFullscreen?.();
        } else {
          root.requestFullscreen?.().catch(() => {});
        }
      });
      document.addEventListener('fullscreenchange', () => {
        const inFs = document.fullscreenElement === root;
        setFs(inFs);
        if (inFs) {
          this._cw = root.clientWidth; this._ch = root.clientHeight;
        } else {
          this._cw = root.clientWidth; this._ch = root.clientHeight;
        }
        this._fitToContainer();
        this._updateMinimap();
      });
      root.appendChild(btn);
    }

    // ── zoom-out button (bottom-left, visible when zoom > 50%) ──────────────────

    _buildControls(root) {
      // Zoom-out magnifier: returns to 50% overview
      const btn = css(el('button'), {
        position:'absolute', bottom:'10px', left:'10px', zIndex:'110',
        width:'40px', height:'40px', borderRadius:'50%',
        background:'#fff', border:'none',
        boxShadow:'0 2px 10px rgba(0,0,0,0.14)',
        cursor:'pointer', display:'none',
        alignItems:'center', justifyContent:'center', padding:'0',
        transition:'transform 0.15s, box-shadow 0.15s',
      });
      btn.title = 'Vue d\'ensemble (50%)';
      btn.innerHTML = `<svg width="20" height="20" viewBox="0 0 20 20" fill="none">
        <circle cx="8.5" cy="8.5" r="5.5" stroke="#374151" stroke-width="1.8"/>
        <line x1="5.5" y1="8.5" x2="11.5" y2="8.5" stroke="#374151" stroke-width="1.8" stroke-linecap="round"/>
        <line x1="12.5" y1="12.5" x2="16" y2="16" stroke="#374151" stroke-width="1.8" stroke-linecap="round"/>
      </svg>`;
      btn.addEventListener('mouseenter', () => { btn.style.boxShadow='0 4px 16px rgba(0,0,0,0.2)'; btn.style.transform='scale(1.08)'; });
      btn.addEventListener('mouseleave', () => { btn.style.boxShadow='0 2px 10px rgba(0,0,0,0.14)'; btn.style.transform=''; });
      btn.addEventListener('click', () => this._zoomToFit50());
      this._zoomOutBtn = btn;
      root.appendChild(btn);
    }

    _updateZoomOutBtn() {
      if (!this._zoomOutBtn) return;
      const show = this._zoom > 0.55;
      this._zoomOutBtn.style.display = show ? 'flex' : 'none';
    }

    // ── minimap ─────────────────────────────────────────────────────────────────

    _buildMinimap(root) {
      const MW=160, MH=100;
      const wrap = css(el('div'), {
        position:'absolute', bottom:'10px', right:'10px', zIndex:'20',
        width:MW+'px', height:MH+'px',
        background:'rgba(255,255,255,0.95)', border:'1px solid #e2e8f0',
        borderRadius:'8px', boxShadow:'0 2px 8px rgba(0,0,0,0.08)', overflow:'hidden',
        display:'none',
      });
      this._mmWrap = wrap;

      const lbl = css(el('div'), {
        position:'absolute', top:'4px', left:'6px', zIndex:'2',
        fontSize:'9px', fontWeight:'700', color:'#94a3b8',
        textTransform:'uppercase', letterSpacing:'.06em',
      });
      lbl.textContent = "Vue d'ensemble";
      wrap.appendChild(lbl);

      const {w,h,minX,minY} = this._bbox;
      const ms = Math.min((MW-8)/w, (MH-20)/h);
      const ox=4, oy=16;
      this._mmScale=ms; this._mmOffX=ox; this._mmOffY=oy;

      const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
      svg.setAttribute('width',MW); svg.setAttribute('height',MH);
      css(svg, {position:'absolute',top:'0',left:'0'});

      for (const o of this._data.chartObjects||[]) {
        const color = this._catColor(o.categoryId);
        const rx=(o.left||0)-minX, ry=(o.top||0)-minY;
        let rw=0, rh=0;
        if (o._type==='zone'||o._type==='freeZone')  { rw=o.width||80; rh=o.height||60; }
        else if (o._type==='seatRow')                { const ss=o.seatSize||22,g=o.seatGap??4; rw=(o.cols||1)*(ss+g); rh=(o.rows||1)*(ss+g)+14; }
        else if (o._type==='tableZone')              { const s=tableZoneSize(o); rw=s; rh=s; }
        else if (o._type==='tableSection')           { rw=tsSectionWidth(o); rh=tsSectionHeight(o); }
        if (!rw||!rh) continue;
        const rect = document.createElementNS('http://www.w3.org/2000/svg','rect');
        rect.setAttribute('x', ox+rx*ms); rect.setAttribute('y', oy+ry*ms);
        rect.setAttribute('width', Math.max(2,rw*ms)); rect.setAttribute('height', Math.max(2,rh*ms));
        rect.setAttribute('rx', 2);
        rect.setAttribute('fill', rgba(color,0.3)); rect.setAttribute('stroke', rgba(color,0.6));
        rect.setAttribute('stroke-width', '0.5');
        svg.appendChild(rect);
      }

      const vpr = document.createElementNS('http://www.w3.org/2000/svg','rect');
      vpr.setAttribute('fill','rgba(59,130,246,0.08)');
      vpr.setAttribute('stroke','#3b82f6');
      vpr.setAttribute('stroke-width','1.5');
      vpr.setAttribute('rx','2');
      svg.appendChild(vpr);
      this._mmRect = vpr;

      wrap.appendChild(svg);
      root.appendChild(wrap);
    }

    _updateMinimap() {
      if (!this._mmRect) return;
      const {minX,minY}=this._bbox, ms=this._mmScale, ox=this._mmOffX, oy=this._mmOffY;
      this._mmRect.setAttribute('x',      ox + (-this._panX/this._zoom - minX)*ms);
      this._mmRect.setAttribute('y',      oy + (-this._panY/this._zoom - minY)*ms);
      this._mmRect.setAttribute('width',  Math.max(4,(this._cw/this._zoom)*ms));
      this._mmRect.setAttribute('height', Math.max(4,(this._ch/this._zoom)*ms));
    }

    // ── tooltip ──────────────────────────────────────────────────────────────────

    _buildTooltip(root) {
      const tip = css(el('div'), {
        position:'absolute', zIndex:'50', pointerEvents:'none',
        background:'#fff', border:'1px solid #e5e7eb',
        borderRadius:'12px', boxShadow:'0 4px 20px rgba(0,0,0,0.12)',
        display:'none', minWidth:'180px', overflow:'hidden',
      });
      root.appendChild(tip);
      return tip;
    }

    _showTooltip(seatEl, info) {
      const {key, section, rowLabel, colLabel, label, catId, planStatus} = info;
      const color=this._catColor(catId), name=this._catName(catId);
      const cat  = this._catMap[catId];
      const price = cat?.price != null
        ? new Intl.NumberFormat('fr-MG').format(cat.price) + ' ' + (cat.currency || 'MGA')
        : null;
      const bs  = this._bookingStatus(key);
      const sel = this._selected.has(key);

      const isUnavailable = planStatus === 'disabled' || bs === 'booked' || bs === 'canceled' || bs === 'hold';

      let barBg, barLeft, barRight;
      if (isUnavailable) {
        barBg    = '#9ca3af';
        barLeft  = `<span style="font-size:14px;font-weight:700;color:#fff">${bs==='hold' ? 'En attente' : 'Indisponible'}</span>`;
        barRight = '';
      } else if (sel) {
        barBg   = color;
        barLeft = `<div style="display:flex;align-items:center;gap:8px">
          <span style="width:20px;height:20px;border-radius:50%;background:rgba(255,255,255,0.25);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><svg viewBox="0 0 12 12" width="12" height="12" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1.5,6 5,9.5 10.5,2.5"/></svg></span>
          <div style="display:flex;flex-direction:column;line-height:1.3">
            <span style="font-size:14px;font-weight:800;color:#fff">Sélectionné</span>
            <span style="font-size:11px;font-weight:600;color:rgba(255,255,255,0.75)">${name}</span>
          </div>
        </div>`;
        barRight = price ? `<span style="font-size:15px;font-weight:800;color:#fff;white-space:nowrap">${price}</span>` : '';
      } else {
        barBg    = color;
        barLeft  = `<span style="font-size:14px;font-weight:700;color:#fff">${name}</span>`;
        barRight = price ? `<span style="font-size:15px;font-weight:800;color:#fff;white-space:nowrap">${price}</span>` : '';
      }

      this._tooltip.innerHTML = `
        <div style="display:flex;gap:0;padding:10px 4px 6px">
          ${[['Section',section||'—'],['Rangée',rowLabel||'—'],['Siège',colLabel||label||'—']].map(([k,v])=>`
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;padding:0 10px">
              <span style="font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap">${k}</span>
              <span style="font-size:16px;font-weight:700;color:#111827;margin-top:3px;white-space:nowrap">${v}</span>
            </div>`).join('<div style="width:1px;background:#f3f4f6;margin:4px 0"></div>')}
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 14px;background:${barBg};margin-top:4px;gap:10px">
          ${barLeft}${barRight}
        </div>`;
      this._tooltip.style.display = 'block';
      const cr=this._root.getBoundingClientRect(), er=seatEl.getBoundingClientRect();
      let left=er.left-cr.left+er.width/2-90;
      let top=er.top-cr.top-this._tooltip.offsetHeight-8;
      if (top<4) top=er.top-cr.top+er.height+8;
      this._tooltip.style.left = Math.max(4,Math.min(left,this._cw-188))+'px';
      this._tooltip.style.top  = top+'px';
    }
    _hideTooltip() { this._tooltip.style.display='none'; }

    // ── microscope lens ──────────────────────────────────────────────────────────

    _buildLens(root) {
      // Single container; circles rebuilt on each update
      // pointerEvents:'none' on container so only circle elements receive clicks
      const wrap = css(el('div'), { position:'absolute', inset:'0', pointerEvents:'none', zIndex:'100' });
      this._lensWrap = wrap;
      root.appendChild(wrap);
      // circles inside use pointerEvents:'auto' individually
    }

    // Called on selection change and zoom change
    _updateLens() {
      if (!this._lensWrap) return;
      this._lensWrap.innerHTML = '';

      if (this._zoom > 0.5 || this._selected.size === 0) return;

      // Group selected keys by section
      const bySection = {};
      for (const key of this._selected) {
        const sec = this._seatSectionMap[key] || '__none__';
        (bySection[sec] = bySection[sec] || []).push(key);
      }

      for (const [section, keys] of Object.entries(bySection)) {
        this._drawOneCircle(section, keys);
      }
    }

    _drawOneCircle(section, selectedKeys) {
      const selSet  = new Set(selectedKeys);
      const rootRect = this._root.getBoundingClientRect();

      // Viewport positions (relative to root) of selected seats in this section
      const positions = [];
      for (const key of selectedKeys) {
        const e = this._canvas.querySelector(`[data-sk="${CSS.escape(key)}"]`);
        if (!e) continue;
        const r = e.getBoundingClientRect();
        positions.push({ x: r.left + r.width/2  - rootRect.left,
                         y: r.top  + r.height/2 - rootRect.top });
      }
      if (!positions.length) return;

      // Centroid
      let cx = 0, cy = 0;
      for (const p of positions) { cx += p.x; cy += p.y; }
      cx /= positions.length; cy /= positions.length;

      // Radius = max distance from centroid to any selected seat + half-seat + padding
      const sampleEl = this._canvas.querySelector(`[data-sk="${CSS.escape(selectedKeys[0])}"]`);
      const seatPx   = (sampleEl ? sampleEl.getBoundingClientRect().width : 0) / 2;
      const pad      = 24;
      let maxDist = 0;
      for (const p of positions) {
        maxDist = Math.max(maxDist, Math.sqrt((p.x-cx)**2 + (p.y-cy)**2));
      }
      const R = Math.max(50, maxDist + seatPx + pad);
      const D = R * 2;

      const catColor = this._catColor(this._seatCatMap[selectedKeys[0]]);

      // Circle container — clickable to zoom on this section
      const circle = css(el('div'), {
        position:'absolute',
        left: (cx - R) + 'px', top: (cy - R) + 'px',
        width: D+'px', height: D+'px',
        borderRadius:'50%', overflow:'hidden',
        border: `3px solid ${catColor}`,
        boxShadow: '0 4px 24px rgba(0,0,0,0.18)',
        background: 'rgba(255,255,255,0.9)',
        cursor: 'pointer',
        pointerEvents: 'auto',
      });
      circle.addEventListener('click', () => {
        // Zoom to 150% centered on the centroid of selected seats
        const rootRect = this._root.getBoundingClientRect();
        let vx = 0, vy = 0, n = 0;
        for (const key of selectedKeys) {
          const e = this._canvas.querySelector(`[data-sk="${CSS.escape(key)}"]`);
          if (!e) continue;
          const r = e.getBoundingClientRect();
          vx += r.left + r.width/2  - rootRect.left;
          vy += r.top  + r.height/2 - rootRect.top;
          n++;
        }
        if (!n) return;
        this._zoomToLevel(1.5, vx/n, vy/n);
      });

      // Clone canvas and align it exactly under the circle
      // Transform formula (no extra magnification):
      //   translate(panX - (cx-R),  panY - (cy-R)) scale(zoom)
      // → the canvas content at root-position (px,py) appears at circle-pos (px-(cx-R), py-(cy-R))
      // → centroid at root (cx,cy) → circle-pos (cx-(cx-R), cy-(cy-R)) = (R, R) ✓
      const clone = this._canvas.cloneNode(true);
      clone.style.pointerEvents   = 'none';
      clone.style.transition      = 'none';
      clone.style.transformOrigin = '0 0';
      clone.style.transform =
        `translate(${this._panX - (cx - R)}px,${this._panY - (cy - R)}px) scale(${this._zoom})`;

      // Style seats inside the clone
      clone.querySelectorAll('[data-sk]').forEach(e => {
        e.classList.remove('animate__animated','animate__pulse');
        const key       = e.dataset.sk;
        const inSection = this._seatSectionMap[key] === section;

        if (selSet.has(key)) {
          // Selected → keep colour + strong ring
          const c = this._catColor(e.dataset.cat);
          e.style.boxShadow = `${c} 0px 0px 0px 2.5px, rgba(255,255,255,0.9) 0px 0px 0px 3px inset`;
          e.style.zIndex    = '5';
        } else if (inSection) {
          // Same section, not selected → gray
          e.style.background = '#d1d5db';
          e.style.color      = 'transparent';
          e.style.border     = 'none';
          e.style.boxShadow  = 'none';
          e.innerHTML        = '';
        } else {
          // Other sections → invisible
          e.style.visibility = 'hidden';
        }
      });

      circle.appendChild(clone);
      this._lensWrap.appendChild(circle);

      // Label below circle
      const lbl = css(el('div'), {
        position:'absolute',
        left: (cx - R) + 'px', top: (cy + R + 6) + 'px',
        width: D + 'px', textAlign:'center',
        fontSize:'12px', fontWeight:'700', color: catColor,
        textShadow:'0 1px 3px rgba(255,255,255,0.9)',
      });
      lbl.textContent = section;
      this._lensWrap.appendChild(lbl);
    }

    _sectionCanvasBbox(section) {
      let bx0=Infinity,by0=Infinity,bx1=-Infinity,by1=-Infinity;
      this._canvas.querySelectorAll('[data-sk]').forEach(e => {
        if (this._seatSectionMap[e.dataset.sk] !== section) return;
        let x=0, y=0, cur=e;
        while (cur && cur !== this._canvas) { x+=cur.offsetLeft||0; y+=cur.offsetTop||0; cur=cur.offsetParent; }
        bx0=Math.min(bx0,x); by0=Math.min(by0,y);
        bx1=Math.max(bx1,x+(e.offsetWidth||22)); by1=Math.max(by1,y+(e.offsetHeight||22));
      });
      return isFinite(bx0) ? {x:bx0, y:by0, w:bx1-bx0, h:by1-by0} : null;
    }

    _hideLens() {
      if (this._lensWrap) this._lensWrap.innerHTML = '';
    }

    // ── seat styles ───────────────────────────────────────────────────────────────
    // planStatus: 'enabled' | 'disabled' | 'deleted'
    // bookingStatus: 'available' | 'booked' | 'hold' | 'canceled'

    _seatStyle(key, catId, planStatus) {
      const color = this._catColor(catId);
      const bs    = this._bookingStatus(key);
      const sel   = this._selected.has(key);
      let bg, fg, border='none', boxShadow='none';

      if (planStatus === 'disabled' || bs === 'booked' || bs === 'canceled') {
        bg='#e5e7eb'; fg='#9ca3af'; border='1px solid #d1d5db';
      } else if (bs === 'hold') {
        bg='#d1d5db'; fg='#6b7280'; border='1px solid #9ca3af';
      } else if (sel) {
        bg=color; fg='#fff';
        // outer ring fixed + inset white painted over the blue fill; on hover inset shrinks → blue fill grows
        boxShadow=color+' 0px 0px 0px 1.5px, rgba(255,255,255,0.9) 0px 0px 0px 2px inset';
      } else {
        bg=color; fg='#fff';
      }
      return {bg, fg, border, boxShadow};
    }

    _cursor(key, planStatus) {
      if (this._readOnly||planStatus==='disabled'||planStatus==='deleted') return 'default';
      const bs=this._bookingStatus(key);
      return bs==='available' ? 'pointer' : 'not-allowed';
    }

    _isClickable(key, planStatus) {
      if (this._readOnly||planStatus!=='enabled') return false;
      return this._bookingStatus(key)==='available';
    }

    _onSeatClick(key, planStatus, seatEl) {
      if (this._didDrag) return;
      if (!this._isClickable(key, planStatus)) return;

      // If not at 150 %, zoom to 150 % centred on seat first (no selection yet)
      if (this._zoom < 1.49) {
        const vr = this._viewport.getBoundingClientRect();
        const er = seatEl.getBoundingClientRect();
        this._zoomToLevel(1.5, er.left+er.width/2-vr.left, er.top+er.height/2-vr.top);
        return;
      }

      const catId = this._seatCatMap[key];
      const info  = { seatKey:key, catId, catColor:this._catColor(catId), catName:this._catName(catId) };

      if (this._selected.has(key)) {
        this._selected.delete(key);
        if (this._onDesel) this._onDesel(info);
      } else {
        this._selected.add(key);
        if (this._onSel) this._onSel(info);
      }
      this._refreshColors();
      this._updateLens();
      if (this._onSelectionChange) this._onSelectionChange();
      // Bounce animation via animate.css
      seatEl.classList.remove('animate__animated', 'animate__pulse');
      seatEl.style.setProperty('--animate-duration', '0.4s');
      void seatEl.offsetWidth;
      seatEl.classList.add('animate__animated', 'animate__pulse');
    }

    _setSeatContent(e, selected, label) {
      if (selected) {
        // SVG checkmark: position:absolute + inset:0 + margin:auto = perfect centering at any size
        // (parent always has position:relative from _makeSeat — never reset it here)
        e.innerHTML = `<svg viewBox="0 0 12 12" style="position:absolute;inset:0;margin:auto;width:44%;height:44%" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1.5,6 5,9.5 10.5,2.5"/></svg>`;
      } else {
        e.textContent = label;
      }
    }

    _refreshColors() {
      this._canvas.querySelectorAll('[data-sk]').forEach(e => {
        const key=e.dataset.sk, catId=e.dataset.cat, ps=e.dataset.ps;
        const {bg,fg,border,boxShadow} = this._seatStyle(key, catId, ps);
        const sel = this._selected.has(key);
        e.style.background    = bg;
        e.style.color         = fg;
        e.style.border        = border;
        e.style.outline       = 'none';
        e.style.boxShadow     = boxShadow || 'none';
        e.style.filter        = '';
        e.style.cursor        = this._cursor(key, ps);
        e.style.zIndex        = sel ? '3' : '';
        if (ps !== 'deleted') this._setSeatContent(e, sel, e.dataset.label || '');
      });
    }

    // ── seat element factory ──────────────────────────────────────────────────────

    _makeSeat(key, catId, planStatus, size, shape, labelText, tipInfo) {
      this._seatCatMap[key]     = catId;
      this._seatSectionMap[key] = tipInfo.section || '';
      const {bg,fg,border} = this._seatStyle(key, catId, planStatus);
      // Legible font even at low zoom — scale up minimum for small seats
      const fs = Math.max(10, Math.floor(size * 0.55));
      const w  = shape==='rounded' ? Math.round(size*1.5) : size;

      const s = css(el('div'), {
        position:'relative',
        display:'flex', alignItems:'center', justifyContent:'center',
        fontWeight:'700', lineHeight:'1', userSelect:'none', boxSizing:'border-box',
        transition:'filter 0.1s',
        background:bg, color:fg, border,
        cursor:this._cursor(key, planStatus),
        fontSize:fs+'px',
        height:size+'px', width:w+'px', minWidth:w+'px',
        padding:shape==='rounded' ? '0 4px' : '0',
        borderRadius: shape==='round' ? '50%' : shape==='rounded' ? '10px' : '4px',
        visibility:planStatus==='deleted' ? 'hidden' : 'visible',
        boxShadow: this._selected.has(key) ? this._catColor(catId)+' 0px 0px 0px 1.5px, rgba(255,255,255,0.9) 0px 0px 0px 2px inset' : 'none',
      });
      const displayLabel = (size>=14 && planStatus!=='deleted') ? labelText : '';
      s.dataset.sk     = key;
      s.dataset.cat    = catId;
      s.dataset.ps     = planStatus;
      s.dataset.label  = displayLabel;
      this._setSeatContent(s, this._selected.has(key), displayLabel);

      if (planStatus!=='deleted') {
        s.addEventListener('mouseenter', () => {
          if (this._zoom >= 0.5 && planStatus !== 'disabled') {
            this._showTooltip(s, {...tipInfo, key, planStatus});
            if (this._selected.has(key)) {
              s.style.boxShadow = this._catColor(catId)+' 0px 0px 0px 1.5px, rgba(255,255,255,0.9) 0px 0px 0px 1px inset';
            } else if (this._isClickable(key, planStatus)) {
              s.style.filter = 'brightness(1.12)';
            }
          }
        });
        s.addEventListener('mouseleave', () => {
          this._hideTooltip();
          s.style.filter = '';
          if (this._selected.has(key)) {
            s.style.boxShadow = this._catColor(catId)+' 0px 0px 0px 1.5px, rgba(255,255,255,0.9) 0px 0px 0px 2px inset';
          }
        });
        s.addEventListener('pointerdown', () => { this._didDrag=false; });
        s.addEventListener('pointerup', (e) => {
          e.stopPropagation();
          this._onPointerUp();
          this._onSeatClick(key, planStatus, s);
          // Refresh tooltip immediately so "Sélectionné" state shows without needing a re-hover
          if (!this._didDrag && this._zoom >= 1.0 && planStatus !== 'disabled') {
            this._showTooltip(s, {...tipInfo, key, planStatus});
          }
        });
      }
      return s;
    }

    // ── section click helper ──────────────────────────────────────────────────────

    _addSectionClick(el) {
      el.style.cursor = 'pointer';
      // Stop pointerdown from reaching the viewport drag handler → no accidental drag on sections
      el.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
        this._didDrag = false;
      });
      el.addEventListener('pointerup', (e) => {
        e.stopPropagation();
        this._onPointerUp();
        if (this._didDrag) return;
        // Compute canvas-local center of this element
        let ox = 0, oy = 0, cur = el;
        while (cur && cur !== this._canvas) { ox += cur.offsetLeft||0; oy += cur.offsetTop||0; cur = cur.offsetParent; }
        // Convert to viewport coordinates (root-relative)
        const vx = this._panX + (ox + el.offsetWidth/2)  * this._zoom;
        const vy = this._panY + (oy + el.offsetHeight/2) * this._zoom;
        this._zoomToLevel(1.5, vx, vy);
      });
    }

    // ── draw all ──────────────────────────────────────────────────────────────────

    _drawAll() {
      for (const o of this._data.chartObjects||[]) {
        switch(o._type) {
          case 'zone':         this._drawZone(o);         break;
          case 'freeZone':     this._drawFreeZone(o);     break;
          case 'seatRow':      this._drawSeatRow(o);      break;
          case 'tableZone':    this._drawTableZone(o);    break;
          case 'tableSection': this._drawTableSection(o); break;
        }
      }
    }

    // ── zone ──────────────────────────────────────────────────────────────────────

    _drawZone(z) {
      const color=this._catColor(z.categoryId);
      const wrap = css(el('div'), {
        position:'absolute', top:(z.top||0)+'px', left:(z.left||0)+'px',
        width:(z.width||80)+'px', height:(z.height||60)+'px',
        background:rgba(color,0.08), border:`1px solid ${rgba(color,0.33)}`,
        borderRadius:z.shape==='pill' ? '999px' : '8px',
        display:'flex', flexDirection:'column', alignItems:'center', justifyContent:'center',
        textAlign:'center', padding:'0 8px',
      });
      const lbl=css(el('span'),{fontWeight:'700',color,fontSize:(z.labelFontSize||11)+'px'});
      lbl.textContent=z.label||''; wrap.appendChild(lbl);
      this._addSectionClick(wrap);
      this._canvas.appendChild(wrap);
    }

    // ── freeZone ──────────────────────────────────────────────────────────────────

    _drawFreeZone(fz) {
      const wrap=css(el('div'),{
        position:'absolute', top:(fz.top||0)+'px', left:(fz.left||0)+'px',
        width:(fz.width||80)+'px', height:(fz.height||60)+'px',
        background:fz.color||'#6b7280', border:`1px solid ${rgba(fz.color||'#6b7280',0.4)}`,
        borderRadius:'8px', display:'flex', flexDirection:'column',
        alignItems:'center', justifyContent:'center', textAlign:'center',
        gap:'2px', pointerEvents:'none',
      });
      if (fz.icon) {
        const ic=css(el('span'),{fontSize:(fz.iconSize||Math.max(12,(fz.height||60)*0.32))+'px',lineHeight:'1'});
        ic.textContent=fz.icon; wrap.appendChild(ic);
      }
      const lbl=css(el('span'),{fontWeight:'700',textTransform:'uppercase',letterSpacing:'0.05em',color:fz.textColor||'#000',fontSize:(fz.labelFontSize||10)+'px'});
      lbl.textContent=fz.label||''; wrap.appendChild(lbl);
      this._canvas.appendChild(wrap);
    }

    // ── seatRow ───────────────────────────────────────────────────────────────────

    _drawSeatRow(row) {
      const color=this._catColor(row.categoryId);
      const ss=row.seatSize||22;
      const disabled=row.disabledSeats||[], deleted=row.deletedSeats||[];
      const overrides=row.categoryOverrides||{};

      const wrapper=css(el('div'),{position:'absolute',top:(row.top||0)+'px',left:(row.left||0)+'px',paddingTop:'14px'});

      const badge=css(el('div'),{
        position:'absolute', top:'-4px', left:'50%', transform:'translate(-50%,-50%)',
        background:'#fff', border:`1px solid ${rgba(color,0.33)}`,
        borderRadius:'999px', padding:'2px 12px',
        fontWeight:'700', letterSpacing:'0.03em',
        fontSize:(row.rowLabelFontSize||10)+'px', color,
        boxShadow:'0 1px 2px rgba(0,0,0,0.06)', whiteSpace:'nowrap', zIndex:'2',
      });
      badge.textContent=row.section||this._catName(row.categoryId);
      wrapper.appendChild(badge);

      const card=css(el('div'),{
        background:rgba(color,0.08), border:`1px solid ${rgba(color,0.33)}`,
        borderRadius:'8px', padding:'6px',
      });

      const colW=row.shape==='rounded' ? Math.round(ss*1.5) : ss;
      const grid=css(el('div'),{
        display:'grid', gridTemplateColumns:`repeat(${row.cols||1},${colW}px)`, gap:'6px',
      });

      for (let r=0;r<(row.rows||1);r++) {
        for (let c=0;c<(row.cols||1);c++) {
          const pk=`${r}-${c}`;
          const isDel=deleted.includes(pk), isDis=!isDel&&disabled.includes(pk);
          const catId=overrides[pk]||row.categoryId;
          const ps=isDel ? 'deleted' : isDis ? 'disabled' : 'enabled';
          const rl=axisLabel(r,row.rows,row.rowFormat,row.rowDirection);
          const cl=axisLabel(c,row.cols,row.colFormat,row.colDirection);
          const lbl=seatLabel(r,c,row.rows,row.cols,row);
          const key=seatRowKey(row,r,c);
          grid.appendChild(this._makeSeat(key,catId,ps,ss,row.shape,lbl,{
            section:row.section||this._catName(row.categoryId), rowLabel:rl, colLabel:cl, label:lbl, catId,
          }));
        }
      }
      card.appendChild(grid); wrapper.appendChild(card);
      this._addSectionClick(wrapper);
      this._canvas.appendChild(wrapper);
    }

    // ── tableZone ─────────────────────────────────────────────────────────────────

    _drawTableZone(t) {
      const color=this._catColor(t.categoryId);
      const sz=tableZoneSize(t), ts=t.tableSize||30, ss=t.seatSize||15;
      const count=t.seatCount||6, disabled=t.disabledSeats||[];

      const wrapper=css(el('div'),{
        position:'absolute', top:(t.top||0)+'px', left:(t.left||0)+'px',
        width:sz+'px', height:sz+'px', transform:`rotate(${t.rotation||0}deg)`,
      });

      for (let i=0;i<count;i++) {
        if (disabled.includes(i)) continue;
        const angle=(2*Math.PI*i)/count - Math.PI/2;
        const cx=sz/2+(ts/2+ss/2)*Math.cos(angle)-ss/2;
        const cy=sz/2+(ts/2+ss/2)*Math.sin(angle)-ss/2;
        const key=tableZoneKey(t,i);
        const seat=this._makeSeat(key,t.categoryId,'enabled',ss,'round',String(i+1),{
          section:t.section||this._catName(t.categoryId), rowLabel:'', colLabel:String(i+1), label:String(i+1), catId:t.categoryId,
        });
        css(seat,{position:'absolute',left:cx+'px',top:cy+'px'});
        wrapper.appendChild(seat);
      }

      const disc=css(el('div'),{
        position:'absolute', left:(sz-ts)/2+'px', top:(sz-ts)/2+'px',
        width:ts+'px', height:ts+'px',
        background:rgba(color,0.13), border:`2px solid ${rgba(color,0.53)}`,
        borderRadius:'50%', display:'flex', alignItems:'center', justifyContent:'center', pointerEvents:'none',
      });
      const dlbl=css(el('span'),{color,fontSize:(t.tableLabelFontSize||12)+'px',fontWeight:'700',textAlign:'center',lineHeight:'1.2',pointerEvents:'none'});
      dlbl.textContent=t.section||this._catName(t.categoryId);
      disc.appendChild(dlbl); wrapper.appendChild(disc);
      this._addSectionClick(wrapper);
      this._canvas.appendChild(wrapper);
    }

    // ── tableSection ──────────────────────────────────────────────────────────────

    _drawTableSection(ts) {
      const color=this._catColor(ts.categoryId);
      const u=tsSectionUnit(ts), sp=ts.tableSpacing??2;
      const tcols=ts.tableCount||3, trows=ts.tableRows||1;
      const spt=ts.seatsPerTable||6, tsize=ts.tableSize||30, ss=ts.seatSize||15;
      const disabled=ts.disabledSeats||[], deletedTables=ts.deletedTables||[];

      const wrapper=css(el('div'),{
        position:'absolute', top:(ts.top||0)+'px', left:(ts.left||0)+'px',
        width:tsSectionWidth(ts)+'px', height:tsSectionHeight(ts)+'px',
        background:rgba(color,0.08), border:`1px solid ${rgba(color,0.33)}`,
        borderRadius:'10px', transform:`rotate(${ts.rotation||0}deg)`,
      });

      for (let ri=0;ri<trows;ri++) {
        for (let ci=0;ci<tcols;ci++) {
          const ti=ri*tcols+ci;
          if (deletedTables.includes(ti)) continue;

          for (let si=0;si<spt;si++) {
            const pk=`${ti}-${si}`, isDis=disabled.includes(pk);
            const ps=isDis ? 'disabled' : 'enabled';
            const key=tableSectionKey(ts,ti,si);
            const angle=(2*Math.PI*si)/spt - Math.PI/2;
            const cx=TS_PAD+ci*(u+sp)+u/2+(tsize/2+ss/2)*Math.cos(angle)-ss/2;
            const cy=TS_PAD+ri*(u+sp)+u/2+(tsize/2+ss/2)*Math.sin(angle)-ss/2;
            const seat=this._makeSeat(key,ts.categoryId,ps,ss,'round',String(si+1),{
              section:ts.section||this._catName(ts.categoryId), rowLabel:`T${ti+1}`, colLabel:String(si+1), label:String(si+1), catId:ts.categoryId,
            });
            css(seat,{position:'absolute',left:cx+'px',top:cy+'px'});
            wrapper.appendChild(seat);
          }

          // Table disc
          const disc=css(el('div'),{
            position:'absolute',
            left:(TS_PAD+ci*(u+sp)+(u-tsize)/2)+'px',
            top: (TS_PAD+ri*(u+sp)+(u-tsize)/2)+'px',
            width:tsize+'px', height:tsize+'px',
            background:rgba(color,0.13), border:`2px solid ${rgba(color,0.53)}`,
            borderRadius:'50%', pointerEvents:'none',
            display:'flex', alignItems:'center', justifyContent:'center',
          });
          const tlbl=css(el('span'),{color,fontSize:(ts.tableLabelFontSize||12)+'px',fontWeight:'700',lineHeight:'1.2',pointerEvents:'none'});
          tlbl.textContent=`T${ti+1}`;
          disc.appendChild(tlbl); wrapper.appendChild(disc);
        }
      }

      this._addSectionClick(wrapper);
      this._canvas.appendChild(wrapper);
    }
  }

  global.PlaceRender = PlaceRender;

})(window);
