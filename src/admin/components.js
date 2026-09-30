/**
 * Small shared UI pieces.
 */
import { Button } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { check, copy } from '@wordpress/icons';

/**
 * Colored monogram standing in for an app logo.
 *
 * @param {Object} props
 * @param {Object} props.client Catalog entry.
 * @param {string} props.size   "small" | "large".
 */
export function ClientMark( { client, size = 'large' } ) {
	const initials =
		client?.mark ||
		( client?.name || '?' )
			.split( /[\s.]+/ )
			.filter( Boolean )
			.slice( 0, 2 )
			.map( ( word ) => word[ 0 ] )
			.join( '' )
			.toUpperCase();

	return (
		<span
			className={ `viagent-mark viagent-mark--${ size } ${
				initials.length > 2 ? 'is-long' : ''
			}` }
			style={ { background: client?.color || '#646970' } }
			aria-hidden="true"
		>
			{ initials }
		</span>
	);
}

/**
 * Copy button with a short "Copied" confirmation.
 *
 * @param {Object} props
 * @param {string} props.text  Text to copy.
 * @param {string} props.label Accessible label.
 */
export function CopyButton( { text, label = __( 'Copy', 'viagent' ) } ) {
	const [ copied, setCopied ] = useState( false );
	const ref = useCopyToClipboard( text, () => {
		setCopied( true );
		setTimeout( () => setCopied( false ), 2000 );
	} );

	return (
		<Button
			ref={ ref }
			variant="secondary"
			size="compact"
			icon={ copied ? check : copy }
			className="viagent-copy"
		>
			{ copied ? __( 'Copied', 'viagent' ) : label }
		</Button>
	);
}

/**
 * Code block with a copy button.
 *
 * @param {Object} props
 * @param {string} props.code    Code.
 * @param {string} props.caption Caption shown above (e.g. file name).
 */
export function CodeBlock( { code, caption } ) {
	return (
		<div className="viagent-code">
			<div className="viagent-code__bar">
				<span className="viagent-code__caption">{ caption }</span>
				<CopyButton text={ code } />
			</div>
			<pre>
				<code>{ code }</code>
			</pre>
		</div>
	);
}

/**
 * Single-line value with a copy button (URLs, keys).
 *
 * @param {Object}  props
 * @param {string}  props.label  Label.
 * @param {string}  props.value  Value.
 * @param {boolean} props.secret Emphasize as a secret.
 */
export function CopyField( { label, value, secret = false } ) {
	return (
		<div className={ `viagent-field ${ secret ? 'is-secret' : '' }` }>
			<span className="viagent-field__label">{ label }</span>
			<div className="viagent-field__row">
				<code className="viagent-field__value">{ value }</code>
				<CopyButton text={ value } />
			</div>
		</div>
	);
}

/**
 * Small colored label.
 *
 * @param {Object}  props
 * @param {string}  props.tone     "neutral" | "success" | "warning" | "danger" | "info".
 * @param {Element} props.children Content.
 */
export function Badge( { tone = 'neutral', children } ) {
	return <span className={ `viagent-badge is-${ tone }` }>{ children }</span>;
}

/**
 * Screen heading with optional actions.
 *
 * @param {Object}  props
 * @param {string}  props.title       Title.
 * @param {string}  props.description Description.
 * @param {Element} props.actions     Actions.
 */
export function ScreenHeader( { title, description, actions } ) {
	return (
		<div className="viagent-screen-header">
			<div>
				<h2>{ title }</h2>
				{ description && <p>{ description }</p> }
			</div>
			{ actions && (
				<div className="viagent-screen-header__actions">
					{ actions }
				</div>
			) }
		</div>
	);
}

/**
 * Tone for an access level.
 *
 * @param {string} level Level.
 * @return {string} Tone.
 */
export function levelTone( level ) {
	return { read: 'info', content: 'success', admin: 'warning' }[ level ];
}

/**
 * Catalog entry for a client slug, with a neutral fallback.
 *
 * @param {string} slug Client slug.
 * @return {Object} Catalog entry.
 */
export function clientFor( slug ) {
	return (
		window.viagentSettings.clients.find(
			( client ) => client.slug === slug
		) || {
			name: slug || __( 'AI app', 'viagent' ),
			mark: 'AI',
		}
	);
}

/**
 * "update_post" → "Update post".
 *
 * @param {string} tool Tool name.
 * @return {string} Label.
 */
export function humanizeTool( tool ) {
	const text = tool.replace( /_/g, ' ' );
	return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
}
