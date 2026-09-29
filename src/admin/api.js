/**
 * Thin wrappers around the Mcpai admin REST API.
 */
import apiFetch from '@wordpress/api-fetch';

const base = '/mcpai/v1/admin';

export const api = {
	getState: () => apiFetch( { path: `${ base }/state` } ),
	updateSettings: ( data ) =>
		apiFetch( { path: `${ base }/settings`, method: 'POST', data } ),

	listConnections: () => apiFetch( { path: `${ base }/connections` } ),
	getConnection: ( id ) =>
		apiFetch( { path: `${ base }/connections/${ id }` } ),
	createConnection: ( data ) =>
		apiFetch( { path: `${ base }/connections`, method: 'POST', data } ),
	revokeConnection: ( id ) =>
		apiFetch( { path: `${ base }/connections/${ id }`, method: 'DELETE' } ),

	revokeGrant: ( id ) =>
		apiFetch( {
			path: `${ base }/oauth-grants/${ id }`,
			method: 'DELETE',
		} ),

	listPrompts: () => apiFetch( { path: `${ base }/prompts` } ),
	togglePrompt: ( name, enabled ) =>
		apiFetch( {
			path: `${ base }/prompts`,
			method: 'POST',
			data: { name, enabled },
		} ),

	listTools: () => apiFetch( { path: `${ base }/tools` } ),
	toggleTool: ( ability, enabled ) =>
		apiFetch( {
			path: `${ base }/tools`,
			method: 'POST',
			data: { ability, enabled },
		} ),

	listActivity: ( page, status ) =>
		apiFetch( {
			path: `${ base }/activity?page=${ page }&status=${ status }`,
		} ),
	revert: ( id ) =>
		apiFetch( {
			path: `${ base }/activity/${ id }/revert`,
			method: 'POST',
		} ),

	getHealth: () => apiFetch( { path: `${ base }/health` } ),
};
