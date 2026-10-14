/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import './styles/styles.scss';

type Term = {
	id: number;
	name: string;
};

const SEARCH_DELAY = 300;
const MIN_SEARCH_LENGTH = 2;

/**
 * Adds and removes question rows.
 *
 * @param field The questions field.
 */
const initQuestions = ( field: HTMLElement ): void => {
	const rows = field.querySelector< HTMLElement >(
		'[data-wire-desk-questions]'
	);
	const template = field.querySelector< HTMLTemplateElement >(
		'[data-wire-desk-question-template]'
	);
	const add = field.querySelector< HTMLButtonElement >(
		'[data-wire-desk-add-question]'
	);

	if ( ! rows || ! template || ! add ) {
		return;
	}

	const max = Number( add.dataset[ 'max' ] ) || 20;
	let next = rows.children.length;

	const update = (): void => {
		add.disabled = rows.children.length >= max;
	};

	add.addEventListener( 'click', () => {
		rows.insertAdjacentHTML(
			'beforeend',
			template.innerHTML.split( '__INDEX__' ).join( String( next++ ) )
		);
		rows.lastElementChild
			?.querySelector< HTMLInputElement >( 'input[type="text"]' )
			?.focus();
		update();
	} );

	rows.addEventListener( 'click', ( event ) => {
		const remove = ( event.target as HTMLElement ).closest(
			'[data-wire-desk-remove-question]'
		);

		if ( remove ) {
			remove.closest( 'tr' )?.remove();
			update();
			add.focus();
		}
	} );

	update();
};

/**
 * Searches a taxonomy's terms and adds picked terms to the selection.
 *
 * @param fieldset The taxonomy's fieldset.
 */
const initTermSearch = ( fieldset: HTMLElement ): void => {
	const taxonomy = fieldset.dataset[ 'taxonomy' ] ?? '';
	const inputName = fieldset.dataset[ 'inputName' ] ?? '';
	const search = fieldset.querySelector< HTMLInputElement >(
		'[data-wire-desk-term-search]'
	);
	const results = fieldset.querySelector< HTMLElement >(
		'[data-wire-desk-term-results]'
	);
	const selected = fieldset.querySelector< HTMLElement >(
		'[data-wire-desk-selected-terms]'
	);

	if ( ! taxonomy || ! inputName || ! search || ! results || ! selected ) {
		return;
	}

	let timer: number | undefined;
	let request = 0;

	const message = ( text: string ): HTMLLIElement => {
		const item = document.createElement( 'li' );
		item.className = 'wire-desk-terms-message';
		item.textContent = text;
		return item;
	};

	const select = ( term: Term ): void => {
		const existing = Array.from(
			selected.querySelectorAll< HTMLInputElement >( 'input' )
		).find( ( input ) => input.value === String( term.id ) );

		if ( existing ) {
			existing.checked = true;
		} else {
			const item = document.createElement( 'li' );
			const label = document.createElement( 'label' );
			const checkbox = document.createElement( 'input' );

			checkbox.type = 'checkbox';
			checkbox.name = inputName;
			checkbox.value = String( term.id );
			checkbox.checked = true;

			label.append( checkbox, ' ', term.name );
			item.append( label );
			selected.append( item );
		}

		results.replaceChildren();
		search.value = '';
		search.focus();
	};

	const render = ( terms: Term[] ): void => {
		if ( ! terms.length ) {
			results.replaceChildren(
				message( __( 'No terms found.', 'wire-desk' ) )
			);
			return;
		}

		results.replaceChildren(
			...terms.map( ( term ) => {
				const item = document.createElement( 'li' );
				const button = document.createElement( 'button' );

				button.type = 'button';
				button.className = 'button-link';
				button.textContent = term.name;
				button.addEventListener( 'click', () => select( term ) );

				item.append( button );
				return item;
			} )
		);
	};

	search.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
		}
	} );

	search.addEventListener( 'input', () => {
		window.clearTimeout( timer );

		const value = search.value.trim();

		if ( value.length < MIN_SEARCH_LENGTH ) {
			results.replaceChildren();
			return;
		}

		timer = window.setTimeout( async () => {
			const current = ++request;

			try {
				const terms = await apiFetch< Term[] >( {
					path: addQueryArgs( '/wire-desk/v1/terms', {
						taxonomy,
						search: value,
					} ),
				} );

				if ( current === request ) {
					render( terms );
				}
			} catch {
				if ( current === request ) {
					results.replaceChildren(
						message(
							__( 'The search failed. Try again.', 'wire-desk' )
						)
					);
				}
			}
		}, SEARCH_DELAY );
	} );
};

domReady( () => {
	document
		.querySelectorAll< HTMLElement >( '[data-wire-desk-questions-field]' )
		.forEach( initQuestions );

	document
		.querySelectorAll< HTMLElement >( '[data-wire-desk-terms]' )
		.forEach( initTermSearch );
} );
