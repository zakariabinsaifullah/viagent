/**
 * Builds client configuration snippets and one-click install links.
 */

/**
 * Fills {{url}}, {{key}} and {{name}} placeholders in a catalog template.
 *
 * @param {string} template        Template.
 * @param {Object} values          Placeholder values.
 * @param {string} values.url      MCP endpoint URL.
 * @param {string} values.key      Connection key.
 * @param {string} values.name     Server name.
 * @param {string} values.localEnv Extra env entries for local sites (optional).
 * @return {string} Snippet.
 */
export function fillTemplate( template, { url, key, name, localEnv = '' } ) {
	return ( template || '' )
		.replaceAll( '{{url}}', url )
		.replaceAll( '{{key}}', key )
		.replaceAll( '{{name}}', name )
		.replaceAll( '{{local_env}}', localEnv );
}

/**
 * Base64-encodes a UTF-8 string.
 *
 * @param {string} value Value.
 * @return {string} Base64.
 */
function toBase64( value ) {
	const bytes = new TextEncoder().encode( value );
	let binary = '';
	bytes.forEach( ( byte ) => ( binary += String.fromCharCode( byte ) ) );
	return window.btoa( binary );
}

/**
 * One-click install link for clients that support it.
 *
 * @param {string} type        "cursor" | "vscode".
 * @param {Object} values      Placeholder values.
 * @param {string} values.url  MCP endpoint URL.
 * @param {string} values.key  Connection key.
 * @param {string} values.name Server name.
 * @return {string|null} Link.
 */
export function deeplink( type, { url, key, name } ) {
	const headers = { Authorization: `Bearer ${ key }` };

	if ( type === 'cursor' ) {
		const config = toBase64( JSON.stringify( { url, headers } ) );
		return `cursor://anysphere.cursor-deeplink/mcp/install?name=${ encodeURIComponent(
			name
		) }&config=${ encodeURIComponent( config ) }`;
	}

	if ( type === 'vscode' ) {
		return `vscode:mcp/install?${ encodeURIComponent(
			JSON.stringify( { name, type: 'http', url, headers } )
		) }`;
	}

	return null;
}
