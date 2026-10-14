/**
 * Double Youpi — Admin module
 * "Reorganize" tab: tree editor rendered as a COLUMN TABLE.
 *
 * Model: one column per top-level group in the rail (native rows,
 * standalone builders/theme, custom groups). Each column displays its
 * hierarchy fully expanded: chips (level 1) + sub-pages (level 2,
 * indented). Custom drag & drop (pointer events) with a precise insertion
 * indicator and depth resolution (promotion/demotion). Spring-load: hovering
 * a column for an extended period during a drag highlights it.
 *
 * Server source of truth: window.doubleyoupiAdminDeveloper.snapshot.columns
 * (built by ColumnModel). The editor works on a COPY (board) that it
 * mutates locally, then serializes into v2 overrides on Save.
 */
(function () {
	'use strict';

	var ctx = window.doubleyoupiAdminDeveloper;
	if ( ! ctx || ! ctx.snapshot ) {
		return;
	}
	var root = document.getElementById( 'dy-dev-editor' );
	if ( ! root ) {
		return;
	}

	var snapshot = ctx.snapshot;
	var i18n     = ctx.i18n || {};
	var t        = function ( k ) { return Object.prototype.hasOwnProperty.call( i18n, k ) ? i18n[ k ] : k; };

	// --- State ------------------------------------------------------------
	var board          = null;   // { columns: [ {id,kind,label,icon,removable,chips:[ {…,subitems:[…]} ]} ] }
	var savedSignature = '';     // serialized board state at last save (for the banner)
	var collapsed      = {};     // colId -> bool
	var disabled       = {};     // signature -> true (styling DY OFF)
	var customSeq      = 0;      // counter for custom group ids
	var drag           = null;   // current drag state
	var springTimer    = null;
	var springTarget   = null;

	// NB: init() is called AT THE END OF THE FILE, after the registry declaration
	// `hiddenChips`/`hiddenSubs` (otherwise `var` hoisting leaves them as
	// `undefined` when deriveOverrides() iterates over them → TypeError).

	function init() {
		board = buildBoard( snapshot.columns || [] );
		( snapshot.disabled_screens || [] ).forEach( function ( s ) { disabled[ s ] = true; } );
		savedSignature = signature();
		render();
	}

	// --- Board construction ----------------------------------------------

	function buildBoard( columns ) {
		var cols = columns.map( function ( c ) {
			return {
				id:           c.id,
				kind:         c.kind,
				label:        c.label,
				icon:         c.icon,
				removable:    !! c.removable,
				// Externally managed builder (Elementor…): we do not expose its
				// hierarchy in the reorganizer (see renderColumn). We empty its
				// chips in the local copy → no override is derived for
				// its sub-pages, and the column is not a valid drop target.
				managed:      !! c.managed,
				// NON-overridden defaults: deriveOverrides only stores an override
				// for label/icon if it differs from the default. Otherwise, after a reload
				// where the baseline already contains the rename, the diff would be empty and
				// the override would be dropped on the next save.
				defaultLabel: ( c.default_label !== undefined ? c.default_label : c.label ),
				defaultIcon:  ( c.default_icon  !== undefined ? c.default_icon  : c.icon ),
				chips: ( c.managed ? [] : ( c.chips || [] ) ).map( function ( chip ) {
					return {
						slug:       chip.slug,
						label:      chip.label,
						url:        chip.url || '',
						icon:       chip.icon || null,
						nativeCol:  chip.native_col || '',
						origKind:   'chip',
						origParent: null,
						subitems: ( chip.subitems || [] ).map( function ( si ) {
							return {
								slug:       si.slug,
								label:      si.label,
								url:        si.url || '',
								signature:  si.signature || '',
								origKind:   'sub',
								origParent: chip.slug,
							};
						} ),
					};
				} ),
			};
		} );
		return { columns: cols };
	}

	// --- Serialization → overrides ---------------------------------------

	/**
	 * Derives the v2 overrides object from the current board state.
	 */
	function deriveOverrides() {
		var ov = {
			version:          snapshot.version || 2,
			column_order:     [],
			custom_groups:    {},
			group_labels:     {},
			group_icons:      {},
			line_order:       [],
			standalone_order: [],
			classifications:  {},
			weights:          {},
			hidden:           [],
			subitem_hidden:   [],
			promoted:         {},
			demoted:          {},
			subitem_order:    {},
			subitem_reparent: {},
		};

		board.columns.forEach( function ( col ) {
			ov.column_order.push( col.id );

			if ( 'custom' === col.kind ) {
				ov.custom_groups[ col.id ] = { label: col.label, icon: col.icon };
			} else {
				// Rename / icon change for a native or standalone column: we
				// compare against the DEFAULT (non-overridden), not the current snapshot
				// value — otherwise a rename would not survive a second save after
				// reload (the baseline would already contain the rename → empty diff →
				// override dropped).
				if ( col.label !== col.defaultLabel ) {
					ov.group_labels[ col.id ] = col.label;
				}
				if ( col.icon !== col.defaultIcon ) {
					ov.group_icons[ col.id ] = col.icon;
				}
			}

			var assignable = ( 'line' === col.kind || 'custom' === col.kind );

			col.chips.forEach( function ( chip, idx ) {
				if ( assignable ) {
					if ( 'sub' === chip.origKind ) {
						// Sub-page promoted to a top-level chip.
						ov.promoted[ chip.slug ] = col.id;
					} else if ( chip.nativeCol && chip.nativeCol !== col.id ) {
						ov.classifications[ chip.slug ] = col.id;
					}
					ov.weights[ chip.slug ] = ( idx + 1 ) * 10;
				}

				// Sub-pages of this chip.
				var order = [];
				chip.subitems.forEach( function ( si ) {
					order.push( si.slug );
					if ( 'chip' === si.origKind ) {
						// Chip demoted as a sub-page of this chip.
						ov.demoted[ si.slug ] = col.id + '::' + chip.slug;
					} else if ( 'sub' === si.origKind && si.origParent && si.origParent !== chip.slug ) {
						// Sub-page re-parented from another chip.
						ov.subitem_reparent[ si.slug ] = chip.slug;
					}
				} );
				if ( order.length ) {
					ov.subitem_order[ chip.slug ] = order;
				}
			} );
		} );

		// Hidden items: captured in separate registries.
		hiddenChips.forEach( function ( h ) { if ( ov.hidden.indexOf( h.slug ) === -1 ) ov.hidden.push( h.slug ); } );
		hiddenSubs.forEach( function ( h ) {
			var key = h.parent + '::' + h.slug;
			if ( ov.subitem_hidden.indexOf( key ) === -1 ) ov.subitem_hidden.push( key );
		} );

		return ov;
	}

	function disabledList() {
		return Object.keys( disabled ).filter( function ( k ) { return disabled[ k ]; } );
	}

	function signature() {
		return JSON.stringify( deriveOverrides() ) + '|' + JSON.stringify( disabledList().slice().sort() );
	}

	function isDirty() {
		return signature() !== savedSignature;
	}

	// --- Hidden registry (chips + subs removed from the board) -----------
	var hiddenChips = []; // { slug, label }
	var hiddenSubs  = []; // { slug, parent, label }

	(function seedHidden() {
		var h = snapshot.hidden || {};
		( h.chips || [] ).forEach( function ( c ) {
			hiddenChips.push( { slug: c.slug || c, label: c.label || c.slug || c } );
		} );
		( h.subitems || [] ).forEach( function ( s ) {
			if ( typeof s === 'string' ) {
				var p = s.split( '::' );
				hiddenSubs.push( { parent: p[0], slug: p[1], label: p[1] } );
			} else {
				hiddenSubs.push( { parent: s.parent, slug: s.child || s.slug, label: s.child || s.slug } );
			}
		} );
	})();

	// --- Lookups ----------------------------------------------------------

	function findColumn( id ) {
		for ( var i = 0; i < board.columns.length; i++ ) {
			if ( board.columns[ i ].id === id ) return board.columns[ i ];
		}
		return null;
	}

	function locate( rowId ) {
		// rowId = "chip:<colId>:<idx>" or "sub:<colId>:<chipIdx>:<subIdx>"
		var p = rowId.split( ':' );
		if ( p[0] === 'chip' ) {
			var col = findColumn( p[1] );
			if ( ! col ) return null;
			return { kind: 'chip', col: col, chipIdx: parseInt( p[2], 10 ) };
		}
		if ( p[0] === 'sub' ) {
			var col2 = findColumn( p[1] );
			if ( ! col2 ) return null;
			return { kind: 'sub', col: col2, chipIdx: parseInt( p[2], 10 ), subIdx: parseInt( p[3], 10 ) };
		}
		return null;
	}

	// --- Render -----------------------------------------------------------

	function render() {
		var html = '';
		html += '<div class="dy-dev-board-wrap">';
		html +=   '<div class="dy-dev-sbar dy-dev-sbar--top" aria-hidden="true"><div class="dy-dev-sbar__thumb"></div></div>';
		html +=   '<button type="button" class="dy-dev-overflow dy-dev-overflow--left" data-scroll="left" aria-hidden="true"><span class="dy-dev-overflow__chev">‹</span><span class="dy-dev-overflow__n"></span></button>';
		html +=   '<button type="button" class="dy-dev-overflow dy-dev-overflow--right" data-scroll="right" aria-hidden="true"><span class="dy-dev-overflow__n"></span><span class="dy-dev-overflow__chev">›</span></button>';
		html +=   '<div class="dy-dev-board">';
		board.columns.forEach( function ( col ) { html += renderColumn( col ); } );
		html +=     renderNewGroupColumn();
		html +=   '</div>';
		html +=   '<div class="dy-dev-sbar dy-dev-sbar--bottom" aria-hidden="true"><div class="dy-dev-sbar__thumb"></div></div>';
		html += '</div>';
		html += '<div class="dy-dev-drawers">' + renderDetected() + renderHidden() + '</div>';
		html += renderBanner();

		root.dataset.state = 'ready';
		root.innerHTML = html;
		bindActions();
		bindDrag();
		setupBoardScroll();
	}

	// --- Board scroll: custom scrollbars (top + bottom) + overflow indicators ----
	//
	// We do NOT use native scrollbars: on macOS they run in "overlay"
	// mode and disappear when idle. Instead, we draw two custom scrollbars
	// (above and below the board) that are always visible, with a proportional
	// draggable thumb — synchronized with the board's scroll position.

	function setupBoardScroll() {
		var boardEl  = root.querySelector( '.dy-dev-board' );
		var topBar   = root.querySelector( '.dy-dev-sbar--top' );
		var botBar   = root.querySelector( '.dy-dev-sbar--bottom' );
		var leftBtn  = root.querySelector( '.dy-dev-overflow--left' );
		var rightBtn = root.querySelector( '.dy-dev-overflow--right' );
		if ( ! boardEl ) return;
		var bars = [ topBar, botBar ].filter( Boolean );
		var thumbs = bars.map( function ( b ) { return b.querySelector( '.dy-dev-sbar__thumb' ); } );

		if ( root.__dyBoardRO ) { try { root.__dyBoardRO.disconnect(); } catch ( e ) {} }

		function maxScroll() { return Math.max( 0, boardEl.scrollWidth - boardEl.clientWidth ); }

		// Positions the thumbs based on the board's width and scroll position.
		function layoutBars() {
			var max = maxScroll();
			var ratio = boardEl.scrollWidth > 0 ? boardEl.clientWidth / boardEl.scrollWidth : 1;
			bars.forEach( function ( bar, i ) {
				var trackW = bar.clientWidth;
				var thumbW = Math.max( 36, Math.round( trackW * ratio ) );
				var travel = trackW - thumbW;
				var pos = max > 0 ? ( boardEl.scrollLeft / max ) * travel : 0;
				var th = thumbs[ i ];
				th.style.width = thumbW + 'px';
				th.style.transform = 'translateX(' + Math.round( pos ) + 'px)';
				bar.classList.toggle( 'is-hidden', ratio >= 1 ); // nothing to scroll
			} );
		}

		function updateIndicators() {
			var bRect = boardEl.getBoundingClientRect();
			var cols  = boardEl.querySelectorAll( '.dy-dev-col[data-col]' );
			var left = 0, right = 0;
			for ( var i = 0; i < cols.length; i++ ) {
				var r = cols[ i ].getBoundingClientRect();
				if ( r.right <= bRect.left + 1 ) { left++; }
				else if ( r.left >= bRect.right - 1 ) { right++; }
			}
			toggleOverflow( leftBtn, left );
			toggleOverflow( rightBtn, right );
		}
		function toggleOverflow( btn, n ) {
			if ( ! btn ) return;
			var slot = btn.querySelector( '.dy-dev-overflow__n' );
			if ( slot ) slot.textContent = '+' + n;
			btn.classList.toggle( 'is-visible', n > 0 );
		}

		var raf = null;
		function onBoardScroll() {
			if ( raf ) return;
			raf = window.requestAnimationFrame( function () { raf = null; layoutBars(); updateIndicators(); } );
		}
		boardEl.addEventListener( 'scroll', onBoardScroll, { passive: true } );

		// Dragging a thumb → proportional scroll.
		bars.forEach( function ( bar, i ) {
			var th = thumbs[ i ];
			th.addEventListener( 'pointerdown', function ( e ) {
				e.preventDefault();
				var startX = e.clientX;
				var startScroll = boardEl.scrollLeft;
				var trackW = bar.clientWidth;
				var thumbW = th.offsetWidth;
				var travel = trackW - thumbW;
				th.setPointerCapture && th.setPointerCapture( e.pointerId );
				bar.classList.add( 'is-active' );
				function mv( ev ) {
					var dx = ev.clientX - startX;
					var frac = travel > 0 ? dx / travel : 0;
					boardEl.scrollLeft = startScroll + frac * maxScroll();
				}
				function up() {
					bar.classList.remove( 'is-active' );
					window.removeEventListener( 'pointermove', mv );
					window.removeEventListener( 'pointerup', up );
				}
				window.addEventListener( 'pointermove', mv );
				window.addEventListener( 'pointerup', up );
			} );
			// Click on the track (outside the thumb) → jump one page.
			bar.addEventListener( 'pointerdown', function ( e ) {
				if ( e.target !== bar ) return;
				var rect = bar.getBoundingClientRect();
				var dir = ( e.clientX - rect.left ) > ( parseFloat( th.style.transform.replace(/[^0-9.-]/g,'') || 0 ) + th.offsetWidth / 2 ) ? 1 : -1;
				smoothScrollTo( boardEl.scrollLeft + dir * boardEl.clientWidth * 0.9 );
			} );
		} );

		applyOverflowFg( leftBtn, rightBtn );

		if ( leftBtn ) leftBtn.addEventListener( 'click', function () { smoothScrollTo( 0 ); } );
		if ( rightBtn ) rightBtn.addEventListener( 'click', function () { smoothScrollTo( maxScroll() ); } );

		// rAF easing — `scrollTo({behavior:'smooth'})` is inconsistent across
		// browsers (and caused a "micro-scroll" jitter). We animate manually instead.
		function smoothScrollTo( target ) {
			target = Math.max( 0, Math.min( maxScroll(), target ) );
			var start = boardEl.scrollLeft;
			var dist = target - start;
			if ( Math.abs( dist ) < 1 ) return;
			var dur = 320, t0 = null;
			function step( ts ) {
				if ( t0 === null ) t0 = ts;
				var p = Math.min( 1, ( ts - t0 ) / dur );
				var e = 1 - Math.pow( 1 - p, 3 ); // easeOutCubic
				boardEl.scrollLeft = start + dist * e;
				if ( p < 1 ) window.requestAnimationFrame( step );
			}
			window.requestAnimationFrame( step );
		}

		if ( window.ResizeObserver ) {
			var ro = new ResizeObserver( function () { layoutBars(); updateIndicators(); } );
			ro.observe( boardEl );
			root.__dyBoardRO = ro;
		} else {
			window.addEventListener( 'resize', function () { layoutBars(); updateIndicators(); } );
		}

		layoutBars();
		updateIndicators();
	}

	/**
	 * Text color for overflow indicators (dark/white) based on the luminance of
	 * the resolved accent color, exposed as --dy-ov-fg on each button.
	 */
	function applyOverflowFg( leftBtn, rightBtn ) {
		var accent = getComputedStyle( root ).getPropertyValue( '--dya-accent' ).trim()
			|| getComputedStyle( document.body ).getPropertyValue( '--dya-accent' ).trim()
			|| '#5b3efe';
		var rgb = parseColor( accent );
		var fg  = '#fff';
		if ( rgb ) {
			var lin = function ( c ) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ); };
			var L = 0.2126 * lin( rgb[0] ) + 0.7152 * lin( rgb[1] ) + 0.0722 * lin( rgb[2] );
			fg = L > 0.45 ? '#10141c' : '#fff';
		}
		[ leftBtn, rightBtn ].forEach( function ( b ) { if ( b ) b.style.setProperty( '--dy-ov-fg', fg ); } );
	}

	/** Parses #hex / rgb() / other formats (via canvas) → [r,g,b] 0-255, or null. */
	function parseColor( str ) {
		if ( ! str ) return null;
		str = str.trim();
		var m = str.match( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i );
		if ( m ) {
			var h = m[1];
			if ( h.length === 3 ) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
			return [ parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16) ];
		}
		var rm = str.match( /rgba?\(\s*([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)/i );
		if ( rm ) return [ +rm[1], +rm[2], +rm[3] ];
		try {
			var cv = document.createElement( 'canvas' ); cv.width = cv.height = 1;
			var cx = cv.getContext( '2d' );
			cx.fillStyle = '#000'; cx.fillStyle = str; cx.fillRect( 0, 0, 1, 1 );
			var d = cx.getImageData( 0, 0, 1, 1 ).data;
			return [ d[0], d[1], d[2] ];
		} catch ( e ) { return null; }
	}

	function renderColumn( col ) {
		var isCollapsed = !! collapsed[ col.id ];
		var chipCount   = col.chips.length;
		var kindBadge   = '';
		if ( 'standalone' === col.kind ) kindBadge = '<span class="dy-dev-col-badge">' + esc( t( 'builder' ) ) + '</span>';
		if ( 'custom' === col.kind )     kindBadge = '<span class="dy-dev-col-badge is-custom">' + esc( t( 'customGroup' ) ) + '</span>';

		var head =
			'<div class="dy-dev-col__head" data-col="' + esc( col.id ) + '">' +
				'<span class="dy-dev-col__grip" data-col-grip="' + esc( col.id ) + '" title="' + esc( t( 'dragColumn' ) ) + '" aria-hidden="true"></span>' +
				'<span class="dy-dev-col__icon"><svg width="16" height="16"><use href="#' + esc( col.icon ) + '"/></svg></span>' +
				'<span class="dy-dev-col__label">' + esc( col.label ) + '</span>' +
				'<span class="dy-dev-col__count">' + chipCount + '</span>' +
				kindBadge +
				'<span class="dy-dev-col__tools">' +
					iconBtn( 'rename-group', col.id, 'i-edit', t( 'rename' ) ) +
					iconBtn( 'icon-group', col.id, 'i-palette', t( 'changeIcon' ) ) +
					( col.removable ? iconBtn( 'delete-group', col.id, 'i-x', t( 'deleteGroup' ) ) : '' ) +
				'</span>' +
			'</div>';

		// Externally managed builder (Elementor…): no editable hierarchy.
		// We render a "managed by the extension" note WITHOUT `data-drop-col` →
		// the column remains reorderable (head grip) but accepts no
		// drop and exposes no sub-pages.
		if ( col.managed ) {
			var note = isCollapsed
				? ''
				: '<div class="dy-dev-col__rows dy-dev-col__rows--managed">' +
						'<div class="dy-dev-managed">' +
							'<svg class="dy-dev-managed__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' +
							'<span class="dy-dev-managed__label">' + esc( t( 'managedByExtension' ) ) + '</span>' +
						'</div>' +
					'</div>';
			return '<section class="dy-dev-col dy-dev-col--' + esc( col.kind ) + ' dy-dev-col--managed' + ( isCollapsed ? ' is-collapsed' : '' ) + '" data-col="' + esc( col.id ) + '">' + head + note + '</section>';
		}

		var body = '<div class="dy-dev-col__rows" data-drop-col="' + esc( col.id ) + '">';
		if ( ! isCollapsed ) {
			if ( ! col.chips.length ) {
				body += '<div class="dy-dev-empty">' + esc( t( 'emptyColumn' ) ) + '</div>';
			}
			col.chips.forEach( function ( chip, ci ) {
				body += renderChipRow( col, chip, ci );
				chip.subitems.forEach( function ( si, si2 ) {
					body += renderSubRow( col, chip, ci, si, si2 );
				} );
			} );
		}
		body += '</div>';

		return '<section class="dy-dev-col dy-dev-col--' + esc( col.kind ) + ( isCollapsed ? ' is-collapsed' : '' ) + '" data-col="' + esc( col.id ) + '">' + head + body + '</section>';
	}

	function renderChipRow( col, chip, ci ) {
		var rowId  = 'chip:' + col.id + ':' + ci;
		var iconHtml = chip.icon
			? '<svg class="dy-dev-row__pic" width="14" height="14" aria-hidden="true"><use href="#' + esc( chip.icon ) + '"/></svg>'
			: '<span class="dy-dev-row__dot" aria-hidden="true"></span>';
		return '' +
			'<div class="dy-dev-row dy-dev-row--chip" data-row="' + esc( rowId ) + '" data-depth="1">' +
				'<span class="dy-dev-row__grip" data-grip="' + esc( rowId ) + '" aria-hidden="true"></span>' +
				iconHtml +
				'<span class="dy-dev-row__label">' + esc( chip.label ) + '</span>' +
				'<span class="dy-dev-row__actions">' +
					iconBtn( 'hide-row', rowId, 'i-eye-off', t( 'hide' ) ) +
				'</span>' +
			'</div>';
	}

	function renderSubRow( col, chip, ci, si, si2 ) {
		var rowId    = 'sub:' + col.id + ':' + ci + ':' + si2;
		var sig      = si.signature || '';
		var stylOff  = sig && disabled[ sig ];
		var toggle   = sig
			? '<button type="button" class="dy-dev-toggle ' + ( stylOff ? 'is-off' : 'is-on' ) + '" data-action="toggle-styling" data-sig="' + esc( sig ) + '" title="' + esc( stylOff ? t( 'stylingOff' ) : t( 'stylingOn' ) ) + '"><span class="dy-dev-toggle__pip"></span>DY</button>'
			: '';
		return '' +
			'<div class="dy-dev-row dy-dev-row--sub" data-row="' + esc( rowId ) + '" data-depth="2">' +
				'<span class="dy-dev-row__grip" data-grip="' + esc( rowId ) + '" aria-hidden="true"></span>' +
				'<span class="dy-dev-row__label">' + esc( si.label ) + '</span>' +
				'<span class="dy-dev-row__actions">' +
					toggle +
					iconBtn( 'hide-row', rowId, 'i-eye-off', t( 'hide' ) ) +
				'</span>' +
			'</div>';
	}

	function renderNewGroupColumn() {
		return '<section class="dy-dev-col dy-dev-col--new"><button type="button" class="dy-dev-newgroup" data-action="new-group"><span class="dy-dev-newgroup__plus"><svg width="20" height="20"><use href="#i-plus"/></svg></span><span>' + esc( t( 'newGroup' ) ) + '</span></button></section>';
	}

	function renderDetected() {
		var items = ( snapshot.detected || [] );
		var head = '<h3 class="dy-dev-drawer__title">' + esc( t( 'detected' ) ) + ' <span class="dy-dev-pill">' + items.length + '</span></h3>';
		if ( ! items.length ) {
			return '<section class="dy-dev-drawer"><div class="dy-dev-drawer__inner">' + head + '<p class="dy-dev-empty">' + esc( t( 'noDetected' ) ) + '</p></div></section>';
		}
		var targets = snapshot.targets || [];
		var rows = items.map( function ( d ) {
			var opts = targets.map( function ( tg ) {
				return '<option value="' + esc( tg.id ) + '"' + ( tg.id === d.guess ? ' selected' : '' ) + '>' + esc( tg.label ) + '</option>';
			} ).join( '' );
			return '<li class="dy-dev-detected" data-slug="' + esc( d.slug ) + '">' +
				'<span class="dy-dev-detected__label">' + esc( d.label ) + '</span>' +
				'<select class="dy-dev-select" data-assign-slug="' + esc( d.slug ) + '">' + opts + '</select>' +
				'<button type="button" class="button button-small" data-action="assign-detected" data-slug="' + esc( d.slug ) + '">' + esc( t( 'assign' ) ) + '</button>' +
			'</li>';
		} ).join( '' );
		return '<section class="dy-dev-drawer"><div class="dy-dev-drawer__inner">' + head + '<p class="dy-dev-drawer__hint">' + esc( t( 'detectedHelp' ) ) + '</p><ul class="dy-dev-flatlist">' + rows + '</ul></div></section>';
	}

	function renderHidden() {
		var total = hiddenChips.length + hiddenSubs.length;
		var head = '<h3 class="dy-dev-drawer__title">' + esc( t( 'hidden' ) ) + ' <span class="dy-dev-pill">' + total + '</span></h3>';
		if ( ! total ) {
			return '<section class="dy-dev-drawer"><div class="dy-dev-drawer__inner">' + head + '<p class="dy-dev-empty">' + esc( t( 'noHidden' ) ) + '</p></div></section>';
		}
		var rows = '';
		hiddenChips.forEach( function ( h, i ) {
			rows += '<li class="dy-dev-hidden-item"><span class="dy-dev-hidden-item__label">' + esc( h.label ) + '</span><button type="button" class="button button-small" data-action="restore-chip" data-idx="' + i + '">' + esc( t( 'restore' ) ) + '</button></li>';
		} );
		hiddenSubs.forEach( function ( h, i ) {
			rows += '<li class="dy-dev-hidden-item"><span class="dy-dev-hidden-item__label">' + esc( h.label ) + ' <small>(' + esc( h.parent ) + ')</small></span><button type="button" class="button button-small" data-action="restore-sub" data-idx="' + i + '">' + esc( t( 'restore' ) ) + '</button></li>';
		} );
		return '<section class="dy-dev-drawer"><div class="dy-dev-drawer__inner">' + head + '<ul class="dy-dev-flatlist">' + rows + '</ul></div></section>';
	}

	function renderBanner() {
		if ( ! isDirty() ) return '';
		return '<div class="dy-dev-banner" role="status" aria-live="polite">' +
			'<span class="dy-dev-banner__count">' + esc( t( 'unsaved' ) ) + '</span>' +
			'<div class="dy-dev-banner__actions">' +
				'<button type="button" class="button" data-action="discard">' + esc( t( 'discard' ) ) + '</button>' +
				'<button type="button" class="button" data-action="reset">' + esc( t( 'reset' ) ) + '</button>' +
				'<button type="button" class="button button-primary" data-action="save">' + esc( t( 'save' ) ) + '</button>' +
			'</div>' +
		'</div>';
	}

	function iconBtn( action, id, icon, label ) {
		return '<button type="button" class="dy-dev-iconbtn" data-action="' + action + '" data-id="' + esc( id ) + '" title="' + esc( label ) + '" aria-label="' + esc( label ) + '"><svg width="14" height="14" aria-hidden="true"><use href="#' + esc( icon ) + '"/></svg></button>';
	}

	// --- Actions (click delegation) --------------------------------------

	function bindActions() {
		root.querySelectorAll( '[data-action]' ).forEach( function ( el ) {
			el.addEventListener( 'click', onAction );
		} );
	}

	function onAction( e ) {
		var el     = e.currentTarget;
		var action = el.getAttribute( 'data-action' );
		switch ( action ) {
			case 'hide-row':       hideRow( el.getAttribute( 'data-id' ) ); break;
			case 'restore-chip':   restoreChip( parseInt( el.getAttribute( 'data-idx' ), 10 ) ); break;
			case 'restore-sub':    restoreSub( parseInt( el.getAttribute( 'data-idx' ), 10 ) ); break;
			case 'toggle-styling': toggleStyling( el.getAttribute( 'data-sig' ) ); break;
			case 'assign-detected':assignDetected( el.getAttribute( 'data-slug' ) ); break;
			case 'new-group':      newGroup(); break;
			case 'rename-group':   renameGroup( el.getAttribute( 'data-id' ) ); break;
			case 'icon-group':     openIconPicker( el.getAttribute( 'data-id' ) ); break;
			case 'delete-group':   deleteGroup( el.getAttribute( 'data-id' ) ); break;
			case 'discard':        doDiscard(); break;
			case 'reset':          doReset(); break;
			case 'save':           doSave(); break;
		}
	}

	function hideRow( rowId ) {
		var loc = locate( rowId );
		if ( ! loc ) return;
		if ( loc.kind === 'chip' ) {
			var chip = loc.col.chips[ loc.chipIdx ];
			// Its sub-pages remain attached (they will reappear on restore).
			hiddenChips.push( { slug: chip.slug, label: chip.label } );
			loc.col.chips.splice( loc.chipIdx, 1 );
		} else {
			var chip2 = loc.col.chips[ loc.chipIdx ];
			var si = chip2.subitems[ loc.subIdx ];
			hiddenSubs.push( { slug: si.slug, parent: chip2.slug, label: si.label } );
			chip2.subitems.splice( loc.subIdx, 1 );
		}
		render();
	}

	function restoreChip( idx ) {
		var h = hiddenChips[ idx ];
		if ( ! h ) return;
		hiddenChips.splice( idx, 1 );
		// Puts it back in its native column if known, otherwise the first column.
		var target = findColumn( h.nativeCol ) || board.columns[0];
		target.chips.push( { slug: h.slug, label: h.label, url: '', icon: null, nativeCol: h.nativeCol || target.id, origKind: 'chip', origParent: null, subitems: [] } );
		render();
	}

	function restoreSub( idx ) {
		var h = hiddenSubs[ idx ];
		if ( ! h ) return;
		hiddenSubs.splice( idx, 1 );
		// Find the parent chip in the board.
		for ( var i = 0; i < board.columns.length; i++ ) {
			var chips = board.columns[ i ].chips;
			for ( var j = 0; j < chips.length; j++ ) {
				if ( chips[ j ].slug === h.parent ) {
					chips[ j ].subitems.push( { slug: h.slug, label: h.label, url: '', signature: '', origKind: 'sub', origParent: h.parent } );
					render();
					return;
				}
			}
		}
		render();
	}

	function toggleStyling( sig ) {
		if ( ! sig ) return;
		disabled[ sig ] = ! disabled[ sig ];
		render();
	}

	function assignDetected( slug ) {
		var sel = root.querySelector( '[data-assign-slug="' + cssEsc( slug ) + '"]' );
		if ( ! sel ) return;
		var target = findColumn( sel.value ) || board.columns[0];
		var d = ( snapshot.detected || [] ).filter( function ( x ) { return x.slug === slug; } )[0];
		var label = d ? d.label : slug;
		target.chips.push( { slug: slug, label: label, url: '', icon: null, nativeCol: '', origKind: 'chip', origParent: null, subitems: [] } );
		// Remove from the detected drawer (visual only; server clears it on save).
		snapshot.detected = ( snapshot.detected || [] ).filter( function ( x ) { return x.slug !== slug; } );
		render();
	}

	function newGroup() {
		var label = ( window.prompt( t( 'newGroupPrompt' ), t( 'newGroupDefault' ) ) || '' ).trim();
		if ( ! label ) return;
		customSeq++;
		var id = 'dyg_' + Date.now().toString( 36 ) + customSeq;
		board.columns.push( { id: id, kind: 'custom', label: label, icon: 'i-pick-folder', removable: true, chips: [] } );
		render();
	}

	function renameGroup( id ) {
		var col = findColumn( id );
		if ( ! col ) return;
		var label = ( window.prompt( t( 'renamePrompt' ), col.label ) || '' ).trim();
		if ( ! label ) return;
		col.label = label;
		render();
	}

	function deleteGroup( id ) {
		var col = findColumn( id );
		if ( ! col || ! col.removable ) return;
		if ( ! window.confirm( t( 'deleteGroupConfirm' ) ) ) return;
		// Its chips return to their native column (otherwise the first column).
		col.chips.forEach( function ( chip ) {
			var target = findColumn( chip.nativeCol ) || board.columns[0];
			if ( target && target.id !== id ) target.chips.push( chip );
		} );
		board.columns = board.columns.filter( function ( c ) { return c.id !== id; } );
		render();
	}

	// --- Icon picker ------------------------------------------------------

	function openIconPicker( id ) {
		var col = findColumn( id );
		if ( ! col ) return;
		var icons = snapshot.icon_choices || [];
		var grid = icons.map( function ( ic ) {
			return '<button type="button" class="dy-dev-pick' + ( ic === col.icon ? ' is-active' : '' ) + '" data-pick="' + esc( ic ) + '"><svg width="20" height="20"><use href="#' + esc( ic ) + '"/></svg></button>';
		} ).join( '' );
		var overlay = document.createElement( 'div' );
		overlay.className = 'dy-dev-modal';
		overlay.innerHTML = '<div class="dy-dev-modal__panel"><h3>' + esc( t( 'chooseIcon' ) ) + '</h3><div class="dy-dev-pickgrid">' + grid + '</div><div class="dy-dev-modal__foot"><button type="button" class="button" data-close>' + esc( t( 'cancel' ) ) + '</button></div></div>';
		document.body.appendChild( overlay );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay || e.target.hasAttribute( 'data-close' ) ) {
				document.body.removeChild( overlay );
				return;
			}
			var pick = e.target.closest( '[data-pick]' );
			if ( pick ) {
				col.icon = pick.getAttribute( 'data-pick' );
				document.body.removeChild( overlay );
				render();
			}
		} );
	}

	// --- Drag & drop (pointer) -------------------------------------------

	function bindDrag() {
		root.querySelectorAll( '[data-grip]' ).forEach( function ( g ) {
			g.addEventListener( 'pointerdown', onRowGripDown );
		} );
		root.querySelectorAll( '[data-col-grip]' ).forEach( function ( g ) {
			g.addEventListener( 'pointerdown', onColGripDown );
		} );
	}

	// ---- Row drag (chips + subs) ----
	function onRowGripDown( e ) {
		if ( e.button !== 0 ) return;
		e.preventDefault();
		var rowId = e.currentTarget.getAttribute( 'data-grip' );
		var loc   = locate( rowId );
		if ( ! loc ) return;

		// Extract the item being moved from the board (chip with its subs, or sub alone).
		var moving;
		if ( loc.kind === 'chip' ) {
			moving = { type: 'chip', data: loc.col.chips[ loc.chipIdx ] };
		} else {
			moving = { type: 'sub', data: loc.col.chips[ loc.chipIdx ].subitems[ loc.subIdx ] };
		}

		drag = {
			rowId: rowId,
			moving: moving,
			pointerId: e.pointerId,
			startX: e.clientX,
			startY: e.clientY,
			active: false,
		};
		window.addEventListener( 'pointermove', onRowMove );
		window.addEventListener( 'pointerup', onRowUp );
	}

	function onRowMove( e ) {
		if ( ! drag ) return;
		if ( ! drag.active ) {
			if ( Math.abs( e.clientX - drag.startX ) + Math.abs( e.clientY - drag.startY ) < 5 ) return;
			drag.active = true;
			root.classList.add( 'dy-dev-dragging' );
			ensureIndicator();
		}
		var target = resolveDropTarget( e.clientX, e.clientY );
		drag.target = target;
		paintIndicator( target );
		handleSpring( e.clientX, e.clientY );
	}

	function onRowUp() {
		window.removeEventListener( 'pointermove', onRowMove );
		window.removeEventListener( 'pointerup', onRowUp );
		clearSpring();
		root.classList.remove( 'dy-dev-dragging' );
		removeIndicator();
		var d = drag;
		drag = null;
		if ( ! d || ! d.active || ! d.target ) return;
		commitRowDrop( d );
		render();
	}

	/**
	 * Resolves the drop target: { colId, index, depth } where depth 1 = chip,
	 * 2 = sub-page. index = insertion position among chips (depth 1)
	 * or among the sub-pages of the hovered chip (depth 2).
	 */
	function resolveDropTarget( x, y ) {
		var colEl = elementColumnAt( x, y );
		if ( ! colEl ) return null;
		var colId = colEl.getAttribute( 'data-col' );
		var col   = findColumn( colId );
		if ( ! col ) return null;

		var rowsWrap = colEl.querySelector( '[data-drop-col]' );
		var rowEls   = Array.prototype.slice.call( rowsWrap.querySelectorAll( '.dy-dev-row' ) );

		// Target depth based on what we are moving + X position.
		var movingType = drag.moving.type;

		// Find the nearest row vertically (gap above/below).
		var insertBeforeRow = null;
		for ( var i = 0; i < rowEls.length; i++ ) {
			var r = rowEls[ i ].getBoundingClientRect();
			if ( y < r.top + r.height / 2 ) { insertBeforeRow = rowEls[ i ]; break; }
		}

		// Determine the parent chip (for depth 2) and the insertion index.
		// We compute the depth: a sub can target depth 2 (reorder under
		// the hovered chip) or depth 1 (promotion). A chip targets depth 1, or
		// depth 2 (demotion) if dropped WITHIN the zone of another chip.
		var ref = insertBeforeRow;
		var depth, chipIndex, subIndex, parentChipIdx;

		if ( movingType === 'sub' ) {
			// Sub: if hovering a sub row, stay under ITS chip (reorder
			// or move under another chip). If hovering a chip row or the
			// empty zone, promote to depth 1.
			var ctxRow = rowAtY( rowEls, y );
			if ( ctxRow && ctxRow.getAttribute( 'data-depth' ) === '2' ) {
				var sl = locate( ctxRow.getAttribute( 'data-row' ) );
				depth = 2;
				parentChipIdx = sl.chipIdx;
				subIndex = computeSubInsertIndex( col, parentChipIdx, y, rowsWrap );
				return { colId: colId, depth: 2, parentChipIdx: parentChipIdx, subIndex: subIndex };
			}
			if ( ctxRow && ctxRow.getAttribute( 'data-depth' ) === '1' ) {
				// hovering a chip: insert as sub AT THE TOP of this chip.
				var cl = locate( ctxRow.getAttribute( 'data-row' ) );
				return { colId: colId, depth: 2, parentChipIdx: cl.chipIdx, subIndex: 0 };
			}
			// promote to chip at the computed position.
			return { colId: colId, depth: 1, index: chipInsertIndex( col, insertBeforeRow ) };
		}

		// movingType === 'chip'
		var ctxRow2 = rowAtY( rowEls, y );
		if ( ctxRow2 && ctxRow2.getAttribute( 'data-depth' ) === '2' ) {
			// dropped in the sub-page zone of a chip → demote under that chip.
			var sl2 = locate( ctxRow2.getAttribute( 'data-row' ) );
			return { colId: colId, depth: 2, parentChipIdx: sl2.chipIdx, subIndex: computeSubInsertIndex( col, sl2.chipIdx, y, rowsWrap ) };
		}
		return { colId: colId, depth: 1, index: chipInsertIndex( col, insertBeforeRow ) };
	}

	function rowAtY( rowEls, y ) {
		for ( var i = 0; i < rowEls.length; i++ ) {
			var r = rowEls[ i ].getBoundingClientRect();
			if ( y >= r.top && y <= r.bottom ) return rowEls[ i ];
		}
		return null;
	}

	function chipInsertIndex( col, insertBeforeRow ) {
		if ( ! insertBeforeRow ) return col.chips.length;
		// Count chips before this row.
		var rid = insertBeforeRow.getAttribute( 'data-row' );
		var loc = locate( rid );
		if ( ! loc ) return col.chips.length;
		return loc.chipIdx;
	}

	function computeSubInsertIndex( col, chipIdx, y, rowsWrap ) {
		var chip = col.chips[ chipIdx ];
		if ( ! chip ) return 0;
		var subEls = Array.prototype.slice.call( rowsWrap.querySelectorAll( '[data-row^="sub:' + col.id + ':' + chipIdx + ':"]' ) );
		for ( var i = 0; i < subEls.length; i++ ) {
			var r = subEls[ i ].getBoundingClientRect();
			if ( y < r.top + r.height / 2 ) return i;
		}
		return chip.subitems.length;
	}

	function elementColumnAt( x, y ) {
		// Pure geometry (not document.elementsFromPoint, which is bounded to the
		// visible viewport → returns [] as soon as the cursor is over a column taller
		// than the screen, causing drops to fail on targets below the fold). We test
		// the rect of each column; if no exact vertical hit (target out of view),
		// we fall back to the column whose horizontal band contains x.
		var cols = root.querySelectorAll( '.dy-dev-col[data-col]' );
		var fallback = null;
		for ( var i = 0; i < cols.length; i++ ) {
			var r = cols[ i ].getBoundingClientRect();
			if ( x >= r.left && x <= r.right ) {
				if ( y >= r.top && y <= r.bottom ) {
					return cols[ i ];
				}
				fallback = cols[ i ];
			}
		}
		return fallback;
	}

	function commitRowDrop( d ) {
		var src = locate( d.rowId );
		if ( ! src ) return;
		var tgt = d.target;
		var destCol = findColumn( tgt.colId );
		if ( ! destCol ) return;

		// 1) Detach the source.
		var node;
		if ( src.kind === 'chip' ) {
			node = src.col.chips.splice( src.chipIdx, 1 )[0];
		} else {
			node = src.col.chips[ src.chipIdx ].subitems.splice( src.subIdx, 1 )[0];
		}
		if ( ! node ) return;

		// Recompute destination indexes if the splice shifted them (same column).
		// (Re-read from the board after splice.)
		if ( tgt.depth === 1 ) {
			// Chip-level: insert as a chip.
			var chipNode = ( src.kind === 'sub' )
				? { slug: node.slug, label: node.label, url: node.url || '', icon: null, nativeCol: node.origParent ? '' : ( node.nativeCol || '' ), origKind: node.origKind, origParent: node.origParent || null, subitems: ( node.subitems || [] ) }
				: node;
			// if it was a sub, it has no subitems.
			if ( src.kind === 'sub' && ! chipNode.subitems ) chipNode.subitems = [];
			var idx = Math.min( tgt.index, destCol.chips.length );
			destCol.chips.splice( idx, 0, chipNode );
		} else {
			// Sub-level: insert as a sub-page of the targeted parent chip.
			var parentChip = destCol.chips[ tgt.parentChipIdx ];
			if ( ! parentChip ) {
				// fallback: put back as a chip at the end.
				destCol.chips.push( src.kind === 'sub' ? toChip( node ) : node );
			} else {
				var subNode = ( src.kind === 'chip' )
					? { slug: node.slug, label: node.label, url: node.url || '', signature: node.signature || '', origKind: node.origKind, origParent: parentChip.slug }
					: node;
				var sidx = Math.min( tgt.subIndex, parentChip.subitems.length );
				parentChip.subitems.splice( sidx, 0, subNode );
			}
		}
	}

	function toChip( node ) {
		return { slug: node.slug, label: node.label, url: node.url || '', icon: null, nativeCol: '', origKind: node.origKind, origParent: node.origParent || null, subitems: [] };
	}

	// ---- Column drag (reorder) with floating ghost + animated reflow --------
	//
	// During drag: the source column is hidden (placeholder), a floating GHOST
	// follows the cursor, and the board order is recomputed in real time —
	// the other columns slide (FLIP) to materialize the landing position.
	// We aim at a column's slot → it shifts aside and the ghost takes its place.

	function onColGripDown( e ) {
		if ( e.button !== 0 ) return;
		e.preventDefault();
		var colId  = e.currentTarget.getAttribute( 'data-col-grip' );
		var colEl  = root.querySelector( '.dy-dev-col[data-col="' + cssEsc( colId ) + '"]' );
		drag = {
			colDrag: true, colId: colId, colEl: colEl,
			pointerId: e.pointerId, startX: e.clientX, startY: e.clientY,
			active: false, ghost: null, dx: 0, dy: 0
		};
		window.addEventListener( 'pointermove', onColMove );
		window.addEventListener( 'pointerup', onColUp );
	}

	function startColDrag( e ) {
		drag.active = true;
		root.classList.add( 'dy-dev-col-dragging' );
		var r = drag.colEl.getBoundingClientRect();
		drag.dx = e.clientX - r.left;
		drag.dy = e.clientY - r.top;
		drag.w  = r.width;
		// Ghost = visual clone that follows the cursor.
		var g = drag.colEl.cloneNode( true );
		g.classList.add( 'dy-dev-col-ghost' );
		g.style.width = r.width + 'px';
		g.style.left = r.left + 'px';
		g.style.top  = r.top + 'px';
		document.body.appendChild( g );
		drag.ghost = g;
		// The source becomes a placeholder (reserved space, content hidden).
		drag.colEl.classList.add( 'dy-dev-col--placeholder' );
	}

	function onColMove( e ) {
		if ( ! drag || ! drag.colDrag ) return;
		if ( ! drag.active ) {
			if ( Math.abs( e.clientX - drag.startX ) + Math.abs( e.clientY - drag.startY ) < 5 ) return;
			startColDrag( e );
		}
		// Move the ghost.
		drag.ghost.style.transform = 'translate(' + ( e.clientX - drag.startX ) + 'px,' + ( e.clientY - drag.startY ) + 'px)';

		// "Enter-slot" detection (not at midpoint): we look for the column
		// whose FULL BOUNDS contain the cursor.
		// The target then takes the placeholder's place and vice versa. Since
		// after the swap the cursor is over the placeholder (which slid under it),
		// no other column is hovered → no oscillation.
		var cols  = Array.prototype.slice.call( root.querySelectorAll( '.dy-dev-col[data-col]' ) );
		var curIdx = board.columns.findIndex( function ( c ) { return c.id === drag.colId; } );
		var hovered = null, firstR = null, lastR = null;
		for ( var i = 0; i < cols.length; i++ ) {
			if ( cols[ i ] === drag.colEl ) continue;
			var r = cols[ i ].getBoundingClientRect();
			if ( firstR === null ) firstR = r;
			lastR = r;
			if ( e.clientX >= r.left && e.clientX <= r.right ) { hovered = cols[ i ]; break; }
		}

		var destIdx = curIdx;
		if ( hovered ) {
			// Inserting at the hovered column's index gives the correct result
			// in BOTH drag directions (the source is removed first).
			var hid = hovered.getAttribute( 'data-col' );
			destIdx = board.columns.findIndex( function ( c ) { return c.id === hid; } );
		} else if ( lastR && e.clientX > lastR.right ) {
			destIdx = board.columns.length - 1; // beyond the last column → end
		} else if ( firstR && e.clientX < firstR.left ) {
			destIdx = 0;                          // before the first column → beginning
		}

		if ( destIdx !== curIdx && destIdx >= 0 ) {
			flipReorder( function () {
				var moved = board.columns.splice( curIdx, 1 )[0];
				board.columns.splice( destIdx, 0, moved );
				renderBoardOnly();
				drag.colEl = root.querySelector( '.dy-dev-col[data-col="' + cssEsc( drag.colId ) + '"]' );
				drag.colEl.classList.add( 'dy-dev-col--placeholder' );
			} );
		}
	}

	function onColUp() {
		window.removeEventListener( 'pointermove', onColMove );
		window.removeEventListener( 'pointerup', onColUp );
		root.classList.remove( 'dy-dev-col-dragging' );
		var d = drag; drag = null;
		if ( d && d.ghost && d.ghost.parentNode ) d.ghost.parentNode.removeChild( d.ghost );
		root.querySelectorAll( '.dy-dev-col--placeholder' ).forEach( function ( el ) { el.classList.remove( 'dy-dev-col--placeholder' ); } );
		if ( ! d || ! d.active ) return;
		// board.columns already reflects the new order (updated during the
		// drag). Re-render cleanly (scrollbars, overflow indicators…).
		render();
	}

	/**
	 * FLIP: captures positions before mutation (fn), then animates each
	 * column from its old position to its new one.
	 */
	function flipReorder( mutate ) {
		var before = {};
		root.querySelectorAll( '.dy-dev-col[data-col]' ).forEach( function ( el ) {
			before[ el.getAttribute( 'data-col' ) ] = el.getBoundingClientRect().left;
		} );
		mutate();
		root.querySelectorAll( '.dy-dev-col[data-col]' ).forEach( function ( el ) {
			var id = el.getAttribute( 'data-col' );
			if ( el.classList.contains( 'dy-dev-col--placeholder' ) ) return;
			if ( before[ id ] === undefined ) return;
			var dx = before[ id ] - el.getBoundingClientRect().left;
			if ( ! dx ) return;
			el.style.transition = 'none';
			el.style.transform = 'translateX(' + dx + 'px)';
			// force reflow then animate to 0
			void el.offsetWidth;
			el.style.transition = 'transform 200ms cubic-bezier(.2,.8,.2,1)';
			el.style.transform = '';
			el.addEventListener( 'transitionend', function te() {
				el.style.transition = ''; el.removeEventListener( 'transitionend', te );
			} );
		} );
	}

	/**
	 * Re-renders ONLY the board (columns) without touching the drawers/banner —
	 * used during drag to avoid losing the ghost or the scroll position.
	 */
	function renderBoardOnly() {
		var boardEl = root.querySelector( '.dy-dev-board' );
		if ( ! boardEl ) { render(); return; }
		var html = '';
		board.columns.forEach( function ( col ) { html += renderColumn( col ); } );
		html += renderNewGroupColumn();
		boardEl.innerHTML = html;
		bindActions();
		bindDrag();
	}

	// ---- Spring-load ----
	function handleSpring( x, y ) {
		var colEl = elementColumnAt( x, y );
		var id = colEl ? colEl.getAttribute( 'data-col' ) : null;
		if ( id && collapsed[ id ] ) {
			if ( springTarget !== id ) {
				clearSpring();
				springTarget = id;
				springTimer = window.setTimeout( function () {
					collapsed[ id ] = false;
					render();
				}, 600 );
			}
		} else {
			clearSpring();
		}
	}
	function clearSpring() {
		if ( springTimer ) { window.clearTimeout( springTimer ); springTimer = null; }
		springTarget = null;
	}

	// ---- Insertion indicator ----
	var indicatorEl = null;
	function ensureIndicator() {
		if ( indicatorEl ) return;
		indicatorEl = document.createElement( 'div' );
		indicatorEl.className = 'dy-dev-indicator';
		document.body.appendChild( indicatorEl );
	}
	function removeIndicator() {
		if ( indicatorEl && indicatorEl.parentNode ) indicatorEl.parentNode.removeChild( indicatorEl );
		indicatorEl = null;
	}
	function paintIndicator( target ) {
		if ( ! indicatorEl || ! target ) { if ( indicatorEl ) indicatorEl.style.opacity = '0'; return; }
		var colEl = root.querySelector( '.dy-dev-col[data-col="' + cssEsc( target.colId ) + '"]' );
		if ( ! colEl ) { indicatorEl.style.opacity = '0'; return; }
		var rowsWrap = colEl.querySelector( '[data-drop-col]' );
		var rect = rowsWrap.getBoundingClientRect();
		var top, left = rect.left + 8, width = rect.width - 16;
		var indent = target.depth === 2 ? 20 : 0;

		if ( target.depth === 1 ) {
			var chipEls = rowsWrap.querySelectorAll( '.dy-dev-row--chip' );
			if ( typeof target.index === 'number' && chipEls[ target.index ] ) {
				top = chipEls[ target.index ].getBoundingClientRect().top;
			} else {
				top = rect.bottom - 2;
			}
		} else {
			var subEls = rowsWrap.querySelectorAll( '[data-row^="sub:' + cssEsc( target.colId ) + ':' + target.parentChipIdx + ':"]' );
			if ( subEls[ target.subIndex ] ) {
				top = subEls[ target.subIndex ].getBoundingClientRect().top;
			} else {
				var chipEl = rowsWrap.querySelector( '[data-row="chip:' + cssEsc( target.colId ) + ':' + target.parentChipIdx + '"]' );
				top = chipEl ? chipEl.getBoundingClientRect().bottom : rect.top;
			}
		}
		indicatorEl.style.opacity = '1';
		indicatorEl.style.left   = ( left + indent ) + 'px';
		indicatorEl.style.width  = ( width - indent ) + 'px';
		indicatorEl.style.top    = top + 'px';
	}

	// --- REST -------------------------------------------------------------

	function doSave() {
		setBusy( true );
		var sentOverrides = deriveOverrides();
		Promise.all( [
			restCall( '/admin/navigation/overrides', 'POST', { overrides: sentOverrides } ),
			restCall( '/admin/screens/disabled', 'POST', { signatures: disabledList() } ),
		] ).then( function () {
			// The LIVE RAIL (left sidebar) is rendered server-side at page load;
			// an AJAX save does not update it. We therefore reload the page:
			// the reload rebuilds the snapshot with $menu/$submenu present
			// (no "flash of empty columns") AND applies the changes to the live
			// rail. This is what the user used to do manually — we automate it.
			window.location.reload();
		} ).catch( function () {
			setBusy( false );
			toast( t( 'errorToast' ), 'error' );
		} );
	}

	function doReset() {
		if ( ! window.confirm( t( 'confirmReset' ) ) ) return;
		setBusy( true );
		Promise.all( [
			restCall( '/admin/navigation/overrides', 'DELETE' ),
			restCall( '/admin/screens/disabled', 'DELETE' ),
		] ).then( function () {
			// Reset = back to defaults. We reload the page: the REST snapshot
			// is empty ($menu absent), so a real admin page load cleanly reconstructs
			// the default hierarchy.
			window.location.reload();
		} ).catch( function () {
			setBusy( false );
			toast( t( 'errorToast' ), 'error' );
		} );
	}

	function doDiscard() {
		init();
	}

	function setBusy( busy ) {
		var b = root.querySelector( '.dy-dev-banner' );
		if ( b ) b.classList.toggle( 'is-busy', busy );
	}

	function restCall( path, method, body ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': ctx.restNonce, 'Content-Type': 'application/json; charset=utf-8' },
		};
		if ( body ) opts.body = JSON.stringify( body );
		return fetch( ctx.restRoot + path, opts ).then( function ( r ) {
			if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
			return r.json();
		} );
	}

	// --- Toast ------------------------------------------------------------
	function toast( msg, type ) {
		var el = document.getElementById( 'dy-dev-toast' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.id = 'dy-dev-toast';
			document.body.appendChild( el );
		}
		el.textContent = msg;
		el.className = 'dy-dev-toast is-visible dy-dev-toast--' + ( type || 'success' );
		window.clearTimeout( el._timer );
		el._timer = window.setTimeout( function () { el.classList.remove( 'is-visible' ); }, 2600 );
	}

	// --- Helpers ----------------------------------------------------------
	function esc( s ) {
		if ( s == null ) return '';
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}
	function cssEsc( s ) {
		return String( s ).replace( /(["\\\]])/g, '\\$1' );
	}

	// Boot: AFTER all declarations (the hiddenChips/hiddenSubs registry
	// is seeded above; init() depends on it via deriveOverrides()).
	init();
})();
