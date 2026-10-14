/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import './styles/styles.scss';

type Term = {
	id: number;
	name: string;
};

type TryAnswer = {
	id: string;
	question: string;
	expected: 'yes' | 'no';
	yes: number;
	met: boolean;
};

type TryResult = {
	model: string;
	score: number;
	band: number;
	band_label: string;
	answers: TryAnswer[];
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

/**
 * Asks the selected model the saved questions about a headline.
 *
 * @param box The try box.
 */
const initTry = ( box: HTMLElement ): void => {
	const headline = box.querySelector< HTMLTextAreaElement >(
		'[data-wire-desk-try-headline]'
	);
	const submit = box.querySelector< HTMLButtonElement >(
		'[data-wire-desk-try-submit]'
	);
	const spinner = box.querySelector< HTMLElement >(
		'[data-wire-desk-try-spinner]'
	);
	const result = box.querySelector< HTMLElement >(
		'[data-wire-desk-try-result]'
	);

	if ( ! headline || ! submit || ! spinner || ! result ) {
		return;
	}

	const notice = ( text: string ): HTMLParagraphElement => {
		const paragraph = document.createElement( 'p' );
		paragraph.className = 'wire-desk-try-error';
		paragraph.textContent = text;
		return paragraph;
	};

	const cell = ( tag: 'td' | 'th', text: string ): HTMLElement => {
		const element = document.createElement( tag );
		element.textContent = text;
		return element;
	};

	const render = ( data: TryResult ): void => {
		const table = document.createElement( 'table' );
		const head = document.createElement( 'tr' );
		const body = document.createElement( 'tbody' );

		table.className = 'widefat striped';
		head.append(
			cell( 'th', __( 'Question', 'wire-desk' ) ),
			cell( 'th', __( 'Answer', 'wire-desk' ) ),
			cell( 'th', __( 'Expected', 'wire-desk' ) ),
			cell( 'th', __( 'Met', 'wire-desk' ) )
		);
		table.createTHead().append( head );

		data.answers.forEach( ( answer ) => {
			const row = document.createElement( 'tr' );
			const yes = answer.yes >= 0.5;
			const confidence = Math.round(
				( yes ? answer.yes : 1 - answer.yes ) * 100
			);

			row.append(
				cell( 'td', answer.question ),
				cell(
					'td',
					sprintf(
						/* translators: 1: Yes or No, 2: confidence percentage. */
						__( '%1$s (%2$d%%)', 'wire-desk' ),
						yes
							? __( 'Yes', 'wire-desk' )
							: __( 'No', 'wire-desk' ),
						confidence
					)
				),
				cell(
					'td',
					'yes' === answer.expected
						? __( 'Yes', 'wire-desk' )
						: __( 'No', 'wire-desk' )
				),
				cell(
					'td',
					answer.met
						? __( 'Yes', 'wire-desk' )
						: __( 'No', 'wire-desk' )
				)
			);
			body.append( row );
		} );

		table.append( body );

		const summary = document.createElement( 'p' );
		const strong = document.createElement( 'strong' );

		strong.textContent = sprintf(
			/* translators: 1: score from 0 to 100, 2: band name. */
			__( 'Score %1$d: %2$s', 'wire-desk' ),
			data.score,
			data.band_label
		);
		summary.className = 'wire-desk-try-summary';
		summary.append( strong );

		const model = document.createElement( 'p' );
		model.className = 'description';
		model.textContent = sprintf(
			/* translators: %s: model ID. */
			__( 'Answered by %s.', 'wire-desk' ),
			data.model
		);

		result.replaceChildren( summary, table, model );
	};

	submit.addEventListener( 'click', async () => {
		const text = headline.value.trim();

		if ( ! text ) {
			result.replaceChildren(
				notice( __( 'Enter a headline.', 'wire-desk' ) )
			);
			headline.focus();
			return;
		}

		const model =
			document.querySelector< HTMLSelectElement | HTMLInputElement >(
				'#wire-desk-model'
			)?.value ?? '';

		submit.disabled = true;
		spinner.classList.add( 'is-active' );

		try {
			render(
				await apiFetch< TryResult >( {
					path: '/wire-desk/v1/try',
					method: 'POST',
					data: model
						? { headline: text, model }
						: { headline: text },
				} )
			);
		} catch ( error ) {
			result.replaceChildren(
				notice(
					( error as { message?: string } ).message ||
						__( 'The request failed. Try again.', 'wire-desk' )
				)
			);
		} finally {
			submit.disabled = false;
			spinner.classList.remove( 'is-active' );
		}
	} );
};

/**
 * Queues the newest posts to be scored again.
 *
 * @param box The re-score box.
 */
const initRescore = ( box: HTMLElement ): void => {
	const limit = box.querySelector< HTMLInputElement >(
		'[data-wire-desk-rescore-limit]'
	);
	const submit = box.querySelector< HTMLButtonElement >(
		'[data-wire-desk-rescore-submit]'
	);
	const spinner = box.querySelector< HTMLElement >(
		'[data-wire-desk-rescore-spinner]'
	);
	const result = box.querySelector< HTMLElement >(
		'[data-wire-desk-rescore-result]'
	);

	if ( ! limit || ! submit || ! spinner || ! result ) {
		return;
	}

	limit.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			submit.click();
		}
	} );

	submit.addEventListener( 'click', async () => {
		if ( ! limit.reportValidity() ) {
			return;
		}

		submit.disabled = true;
		spinner.classList.add( 'is-active' );
		result.classList.remove( 'wire-desk-try-error' );

		try {
			const { queued } = await apiFetch< { queued: number } >( {
				path: '/wire-desk/v1/rescore',
				method: 'POST',
				data: { limit: Number( limit.value ) },
			} );

			result.textContent = sprintf(
				/* translators: %d: number of posts. */
				_n(
					'Queued %d post. It is scored in the background.',
					'Queued %d posts. They are scored in the background.',
					queued,
					'wire-desk'
				),
				queued
			);
		} catch ( error ) {
			result.classList.add( 'wire-desk-try-error' );
			result.textContent =
				( error as { message?: string } ).message ||
				__( 'The request failed. Try again.', 'wire-desk' );
		} finally {
			submit.disabled = false;
			spinner.classList.remove( 'is-active' );
		}
	} );
};

domReady( () => {
	document
		.querySelectorAll< HTMLElement >( '[data-wire-desk-questions-field]' )
		.forEach( initQuestions );

	document
		.querySelectorAll< HTMLElement >( '[data-wire-desk-terms]' )
		.forEach( initTermSearch );

	document
		.querySelectorAll< HTMLElement >( '[data-wire-desk-try]' )
		.forEach( initTry );

	document
		.querySelectorAll< HTMLElement >( '[data-wire-desk-rescore]' )
		.forEach( initRescore );
} );
