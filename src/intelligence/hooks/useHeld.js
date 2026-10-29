import { useEffect, useState } from '@wordpress/element';

/**
 * The holds a read's rows do not yet reflect: a row taken out still listed,
 * or a row put in no listed row `matches`.
 *
 * @param {Map<string, {row: ?Object}>}               held    Each held key's hold.
 * @param {Array<Object>}                             listed  The rows read.
 * @param {(row: Object) => string}                   keyOf   A listed row's key.
 * @param {(listed: Object, held: Object) => boolean} matches Whether a listed row reflects a held one.
 * @return {Array<[string, Object]>} The holds still owed, as entries.
 */
function unreflected( held, listed, keyOf, matches ) {
	return [ ...held ].filter( ( [ key, { row } ] ) =>
		row
			? ! listed.some( ( one ) => matches( one, row ) )
			: listed.some( ( one ) => keyOf( one ) === key )
	);
}

/**
 * One read's rows with each write a curator has sent shown from the moment
 * it is sent: the projection records a write seconds after it lands, so a
 * re-read answers the old rows until then.
 *
 * A write holds one key. Holding it with no row takes that key's row out
 * until a read no longer lists it; holding it with a row shows that row in
 * place of the key's, or after the rows when none is listed, until a read
 * lists a row `matches` says reflects it. A hold the read reflects gives
 * way to the read. A refusal undoes the hold whose send it echoes, and a
 * refusal echoing none, such as a Router's NOT_AVAILABLE, undoes every hold.
 *
 * @param {{answer: ?Object, error: ?string, loading: boolean}} read      What `useIntelRead` returned.
 * @param {string}                                              rows      The answer's row list.
 * @param {(row: Object) => string}                             keyOf     A listed row's key.
 * @param {(listed: Object, held: Object) => boolean}           [matches] Whether a listed row reflects a held one.
 * @return {{read: Object, hold: (key: string, row: ?Object, run: () => string[]) => void, refused: (tokens: string[]) => void}}
 *   The read with every hold shown; `hold()`, which sends a write through
 *   `run` and holds its key; and `refused()`, which undoes the hold whose
 *   send echoed `tokens`, or every hold for none.
 */
export function useHeld( read, rows, keyOf, matches ) {
	// Each held key => the tokens its write sent, as JSON, and its row.
	const [ held, setHeld ] = useState( () => new Map() );
	const listed = read.answer?.[ rows ];
	useEffect( () => {
		if ( ! listed ) {
			return;
		}
		setHeld( ( was ) => {
			const kept = unreflected( was, listed, keyOf, matches );
			return kept.length === was.size ? was : new Map( kept );
		} );
	}, [ listed, keyOf, matches ] );

	const shown = () => {
		const live = new Map( unreflected( held, listed, keyOf, matches ) );
		const keys = new Set( listed.map( keyOf ) );
		return [
			...listed.flatMap( ( one ) => {
				const hold = live.get( keyOf( one ) );
				if ( ! hold ) {
					return [ one ];
				}
				return hold.row ? [ hold.row ] : [];
			} ),
			...[ ...live ]
				.filter( ( [ key, { row } ] ) => row && ! keys.has( key ) )
				.map( ( [ , { row } ] ) => row ),
		];
	};

	return {
		read: listed
			? { ...read, answer: { ...read.answer, [ rows ]: shown() } }
			: read,
		hold: ( key, row, run ) => {
			const tokens = JSON.stringify( run() );
			setHeld( ( was ) => new Map( was ).set( key, { tokens, row } ) );
		},
		refused: ( tokens ) => {
			const sent = JSON.stringify( tokens );
			setHeld(
				( was ) =>
					new Map(
						0 === tokens.length
							? []
							: [ ...was ].filter(
									( [ , hold ] ) => hold.tokens !== sent
							  )
					)
			);
		},
	};
}
