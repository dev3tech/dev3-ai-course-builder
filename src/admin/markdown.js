/**
 * Markdown-lite (same rules as includes/Course/Markdown.php) rendered as React elements, no innerHTML.
 */
import { Fragment } from '@wordpress/element';

const CALLOUT = /^(tip|note|important|warning|key takeaway|remember)\s*:/i;

/**
 * Parses Markdown-lite into blocks.
 *
 * @param {string} md Markdown.
 * @return {Array} Blocks { type, level, text, items, ordered }.
 */
export function parse( md ) {
	const lines = String( md || '' ).replace( /\r/g, '' ).split( '\n' );
	const blocks = [];
	let para = [];
	let list = null;
	let quote = [];
	let code = null;
	const flushPara = () => {
		if ( para.length ) {
			blocks.push( { type: 'paragraph', text: para.join( ' ' ) } );
			para = [];
		}
	};
	const flushList = () => {
		if ( list ) {
			blocks.push( list );
			list = null;
		}
	};
	const flushQuote = () => {
		if ( quote.length ) {
			const text = quote.join( ' ' );
			blocks.push( { type: CALLOUT.test( text ) ? 'callout' : 'quote', text } );
			quote = [];
		}
	};
	const flush = () => {
		flushPara();
		flushList();
		flushQuote();
	};
	lines.forEach( ( line ) => {
		if ( code !== null ) {
			if ( /^\s*```/.test( line ) ) {
				blocks.push( { type: 'code', text: code.join( '\n' ) } );
				code = null;
			} else {
				code.push( line );
			}
			return;
		}
		const trim = line.trim();
		if ( /^```/.test( trim ) ) {
			flush();
			code = [];
			return;
		}
		if ( ! trim ) {
			flush();
			return;
		}
		let m = trim.match( /^(#{1,4})\s+(.+)$/ );
		if ( m ) {
			flush();
			blocks.push( { type: 'heading', level: Math.max( 2, Math.min( 4, m[ 1 ].length ) ), text: m[ 2 ].trim() } );
			return;
		}
		if ( /^(-{3,}|\*{3,})$/.test( trim ) ) {
			flush();
			blocks.push( { type: 'separator' } );
			return;
		}
		m = trim.match( /^(?:[-*•]|(\d+)[.)])\s+(.+)$/ );
		if ( m ) {
			const ordered = m[ 1 ] !== undefined;
			flushPara();
			flushQuote();
			if ( list && list.ordered !== ordered ) {
				flushList();
			}
			if ( ! list ) {
				list = { type: 'list', ordered, items: [] };
			}
			list.items.push( m[ 2 ].trim() );
			return;
		}
		m = trim.match( /^>\s?(.*)$/ );
		if ( m ) {
			flushPara();
			flushList();
			quote.push( m[ 1 ].trim() );
			return;
		}
		flushList();
		flushQuote();
		para.push( trim );
	} );
	if ( code !== null ) {
		blocks.push( { type: 'code', text: code.join( '\n' ) } );
	}
	flush();
	return blocks;
}

/**
 * Inline formatting → React nodes.
 *
 * @param {string} text Text.
 * @return {Array} Nodes.
 */
export function inline( text ) {
	const out = [];
	const re = /(`[^`]+`)|(\*\*[^*]+\*\*)|(\*[^*\s][^*]*\*)|(\[[^\]]+\]\([^)\s]+\))/g;
	let last = 0;
	let m;
	let k = 0;
	const s = String( text || '' );
	while ( ( m = re.exec( s ) ) !== null ) {
		if ( m.index > last ) {
			out.push( s.slice( last, m.index ) );
		}
		const tok = m[ 0 ];
		if ( m[ 1 ] ) {
			out.push( <code key={ k++ }>{ tok.slice( 1, -1 ) }</code> );
		} else if ( m[ 2 ] ) {
			out.push( <strong key={ k++ }>{ tok.slice( 2, -2 ) }</strong> );
		} else if ( m[ 3 ] ) {
			out.push( <em key={ k++ }>{ tok.slice( 1, -1 ) }</em> );
		} else {
			const lm = tok.match( /^\[([^\]]+)\]\(([^)\s]+)\)$/ );
			const safe = /^(https?:|mailto:|\/|#)/i.test( lm[ 2 ] );
			out.push(
				safe ? (
					<a key={ k++ } href={ lm[ 2 ] } target="_blank" rel="noreferrer">
						{ lm[ 1 ] }
					</a>
				) : (
					<Fragment key={ k++ }>{ lm[ 1 ] }</Fragment>
				)
			);
		}
		last = m.index + tok.length;
	}
	if ( last < s.length ) {
		out.push( s.slice( last ) );
	}
	return out;
}

/**
 * Word count of Markdown-lite.
 *
 * @param {string} md Markdown.
 * @return {number} Words.
 */
export function words( md ) {
	const text = String( md || '' )
		.replace( /^(#{1,4}|>|[-*•]|\d+[.)])\s*/gm, '' )
		.replace( /[`*]/g, '' )
		.trim();
	return text ? text.split( /\s+/ ).length : 0;
}

/**
 * Preview of Markdown-lite as the lesson will look (structure only; the theme styles it on the site).
 *
 * @param {Object} props    Props.
 * @param {string} props.md Markdown.
 * @return {Element} Preview.
 */
export function MarkdownPreview( { md } ) {
	const blocks = parse( md );
	return (
		<div className="dev3lm-md">
			{ blocks.map( ( b, i ) => {
				switch ( b.type ) {
					case 'heading': {
						const H = `h${ b.level + 1 }`;
						return <H key={ i }>{ inline( b.text ) }</H>;
					}
					case 'list': {
						const L = b.ordered ? 'ol' : 'ul';
						return (
							<L key={ i }>
								{ b.items.map( ( item, j ) => (
									<li key={ j }>{ inline( item ) }</li>
								) ) }
							</L>
						);
					}
					case 'quote':
						return <blockquote key={ i }>{ inline( b.text ) }</blockquote>;
					case 'callout':
						return (
							<p key={ i } className="dev3lm-md__callout">
								{ inline( b.text ) }
							</p>
						);
					case 'code':
						return (
							<pre key={ i }>
								<code>{ b.text }</code>
							</pre>
						);
					case 'separator':
						return <hr key={ i } />;
					default:
						return <p key={ i }>{ inline( b.text ) }</p>;
				}
			} ) }
		</div>
	);
}
