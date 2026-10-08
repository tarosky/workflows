/**
 * 範囲・属性によるフィルタ. JSが無くても全件が表示される.
 */
( function () {
	const form = document.getElementById( 'filters' );
	const summary = document.getElementById( 'summary' );
	const sections = Array.from( document.querySelectorAll( '.release' ) );
	const compare = ( a, b ) => {
		const pa = a.split( '.' ).map( Number );
		const pb = b.split( '.' ).map( Number );
		return pa[ 0 ] - pb[ 0 ] || pa[ 1 ] - pb[ 1 ];
	};

	form.hidden = false;

	const readState = () => {
		const data = new FormData( form );
		const state = { from: data.get( 'from' ), to: data.get( 'to' ), q: ( data.get( 'q' ) || '' ).trim().toLowerCase() };
		[ 'severity', 'status', 'confidence', 'type' ].forEach( ( key ) => {
			state[ key ] = new Set( data.getAll( key ) );
		} );
		return state;
	};

	const apply = () => {
		const state = readState();
		let total = 0;
		const bySeverity = { high: 0, medium: 0, low: 0 };
		sections.forEach( ( section ) => {
			const release = section.dataset.release;
			const inRange = compare( release, state.from ) >= 0 && compare( release, state.to ) <= 0;
			let visible = 0;
			section.querySelectorAll( '.change' ).forEach( ( item ) => {
				const show =
					inRange &&
					[ 'severity', 'status', 'confidence', 'type' ].every( ( key ) => state[ key ].has( item.dataset[ key ] ) ) &&
					( ! state.q || item.textContent.toLowerCase().includes( state.q ) );
				item.hidden = ! show;
				if ( show ) {
					visible++;
					bySeverity[ item.dataset.severity ]++;
				}
			} );
			section.hidden = ! inRange;
			total += visible;
		} );
		summary.textContent = `表示中 ${ total } 件（重大度 高 ${ bySeverity.high } ／ 中 ${ bySeverity.medium } ／ 低 ${ bySeverity.low }）`;
		try {
			history.replaceState( null, '', '?' + new URLSearchParams( new FormData( form ) ).toString() + location.hash );
		} catch ( e ) {}
	};

	// URLクエリから状態を復元する（Issueからのリンク用）.
	const params = new URLSearchParams( location.search );
	if ( [ ...params.keys() ].length ) {
		form.querySelectorAll( 'input[type=checkbox]' ).forEach( ( input ) => {
			if ( params.has( input.name ) ) {
				input.checked = params.getAll( input.name ).includes( input.value );
			}
		} );
		[ 'from', 'to', 'q' ].forEach( ( name ) => {
			if ( params.has( name ) ) {
				form.elements[ name ].value = params.get( name );
			}
		} );
	}

	form.addEventListener( 'input', apply );
	form.addEventListener( 'submit', ( e ) => e.preventDefault() );
	apply();

	// 個別項目へのリンクで来た場合は、フィルタに関係なく表示する.
	if ( location.hash ) {
		const target = document.getElementById( decodeURIComponent( location.hash.slice( 1 ) ) );
		if ( target && target.classList.contains( 'change' ) ) {
			target.hidden = false;
			target.closest( '.release' ).hidden = false;
			target.scrollIntoView();
		}
	}
} )();
