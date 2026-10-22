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
		'[data-ai-post-triage-questions]'
	);
	const template = field.querySelector< HTMLTemplateElement >(
		'[data-ai-post-triage-question-template]'
	);
	const add = field.querySelector< HTMLButtonElement >(
		'[data-ai-post-triage-add-question]'
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
			'[data-ai-post-triage-remove-question]'
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
 * Searches the chosen taxonomy's terms and adds picked terms to the selection.
 *
 * @param field The terms field.
 */
const initTermSearch = ( field: HTMLElement ): void => {
	const baseName = field.dataset[ 'inputName' ] ?? '';
	const taxonomies = field.querySelector< HTMLSelectElement >(
		'[data-ai-post-triage-term-taxonomy]'
	);
	const search = field.querySelector< HTMLInputElement >(
		'[data-ai-post-triage-term-search]'
	);
	const results = field.querySelector< HTMLElement >(
		'[data-ai-post-triage-term-results]'
	);
	const selected = field.querySelector< HTMLElement >(
		'[data-ai-post-triage-selected-terms]'
	);

	if ( ! baseName || ! taxonomies || ! search || ! results || ! selected ) {
		return;
	}

	let timer: number | undefined;
	let request = 0;

	const message = ( text: string ): HTMLLIElement => {
		const item = document.createElement( 'li' );
		item.className = 'ai-post-triage-terms-message';
		item.textContent = text;
		return item;
	};

	const select = ( term: Term, taxonomy: string ): void => {
		const inputName = `${ baseName }[${ taxonomy }][]`;
		const existing = Array.from(
			selected.querySelectorAll< HTMLInputElement >( 'input' )
		).find(
			( input ) =>
				input.name === inputName && input.value === String( term.id )
		);

		if ( existing ) {
			existing.checked = true;
		} else {
			const item = document.createElement( 'li' );
			const label = document.createElement( 'label' );
			const checkbox = document.createElement( 'input' );
			const meta = document.createElement( 'span' );

			checkbox.type = 'checkbox';
			checkbox.name = inputName;
			checkbox.value = String( term.id );
			checkbox.checked = true;

			meta.className = 'ai-post-triage-terms-meta';
			meta.textContent =
				taxonomies.selectedOptions[ 0 ]?.dataset[ 'label' ] ?? taxonomy;

			label.append( checkbox, ' ', term.name, ' ', meta );
			item.append( label );
			selected.append( item );
		}

		results.replaceChildren();
		search.value = '';
		search.focus();
	};

	const render = ( terms: Term[], taxonomy: string ): void => {
		if ( ! terms.length ) {
			results.replaceChildren(
				message( __( 'No terms found.', 'ai-post-triage' ) )
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
				button.addEventListener( 'click', () =>
					select( term, taxonomy )
				);

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

	const find = (): void => {
		window.clearTimeout( timer );
		++request;

		const value = search.value.trim();
		const taxonomy = taxonomies.value;

		if ( value.length < MIN_SEARCH_LENGTH || ! taxonomy ) {
			results.replaceChildren();
			return;
		}

		timer = window.setTimeout( async () => {
			const current = ++request;

			try {
				const terms = await apiFetch< Term[] >( {
					path: addQueryArgs( '/ai-post-triage/v1/terms', {
						taxonomy,
						search: value,
					} ),
				} );

				if ( current === request ) {
					render( terms, taxonomy );
				}
			} catch {
				if ( current === request ) {
					results.replaceChildren(
						message(
							__(
								'The search failed. Try again.',
								'ai-post-triage'
							)
						)
					);
				}
			}
		}, SEARCH_DELAY );
	};

	search.addEventListener( 'input', find );
	taxonomies.addEventListener( 'change', find );
};

/**
 * Asks the selected model the saved questions about a headline.
 *
 * @param box The try box.
 */
const initTry = ( box: HTMLElement ): void => {
	const headline = box.querySelector< HTMLTextAreaElement >(
		'[data-ai-post-triage-try-headline]'
	);
	const submit = box.querySelector< HTMLButtonElement >(
		'[data-ai-post-triage-try-submit]'
	);
	const spinner = box.querySelector< HTMLElement >(
		'[data-ai-post-triage-try-spinner]'
	);
	const result = box.querySelector< HTMLElement >(
		'[data-ai-post-triage-try-result]'
	);

	if ( ! headline || ! submit || ! spinner || ! result ) {
		return;
	}

	const notice = ( text: string ): HTMLParagraphElement => {
		const paragraph = document.createElement( 'p' );
		paragraph.className = 'ai-post-triage-try-error';
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
			cell( 'th', __( 'Question', 'ai-post-triage' ) ),
			cell( 'th', __( 'Answer', 'ai-post-triage' ) ),
			cell( 'th', __( 'Expected', 'ai-post-triage' ) ),
			cell( 'th', __( 'Met', 'ai-post-triage' ) )
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
						__( '%1$s (%2$d%%)', 'ai-post-triage' ),
						yes
							? __( 'Yes', 'ai-post-triage' )
							: __( 'No', 'ai-post-triage' ),
						confidence
					)
				),
				cell(
					'td',
					'yes' === answer.expected
						? __( 'Yes', 'ai-post-triage' )
						: __( 'No', 'ai-post-triage' )
				),
				cell(
					'td',
					answer.met
						? __( 'Yes', 'ai-post-triage' )
						: __( 'No', 'ai-post-triage' )
				)
			);
			body.append( row );
		} );

		table.append( body );

		const summary = document.createElement( 'p' );
		const strong = document.createElement( 'strong' );

		strong.textContent = sprintf(
			/* translators: 1: score from 0 to 100, 2: band name. */
			__( 'Score %1$d: %2$s', 'ai-post-triage' ),
			data.score,
			data.band_label
		);
		summary.className = 'ai-post-triage-try-summary';
		summary.append( strong );

		const model = document.createElement( 'p' );
		model.className = 'description';
		model.textContent = sprintf(
			/* translators: %s: model ID. */
			__( 'Answered by %s.', 'ai-post-triage' ),
			data.model
		);

		result.replaceChildren( summary, table, model );
	};

	submit.addEventListener( 'click', async () => {
		const text = headline.value.trim();

		if ( ! text ) {
			result.replaceChildren(
				notice( __( 'Enter a headline.', 'ai-post-triage' ) )
			);
			headline.focus();
			return;
		}

		const model =
			document.querySelector< HTMLSelectElement | HTMLInputElement >(
				'#ai-post-triage-model'
			)?.value ?? '';

		submit.disabled = true;
		spinner.classList.add( 'is-active' );

		try {
			render(
				await apiFetch< TryResult >( {
					path: '/ai-post-triage/v1/try',
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
						__( 'The request failed. Try again.', 'ai-post-triage' )
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
		'[data-ai-post-triage-rescore-limit]'
	);
	const submit = box.querySelector< HTMLButtonElement >(
		'[data-ai-post-triage-rescore-submit]'
	);
	const spinner = box.querySelector< HTMLElement >(
		'[data-ai-post-triage-rescore-spinner]'
	);
	const result = box.querySelector< HTMLElement >(
		'[data-ai-post-triage-rescore-result]'
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
		result.classList.remove( 'ai-post-triage-try-error' );

		try {
			const { queued } = await apiFetch< { queued: number } >( {
				path: '/ai-post-triage/v1/rescore',
				method: 'POST',
				data: { limit: Number( limit.value ) },
			} );

			result.textContent = sprintf(
				/* translators: %d: number of posts. */
				_n(
					'Queued %d post. It is scored in the background.',
					'Queued %d posts. They are scored in the background.',
					queued,
					'ai-post-triage'
				),
				queued
			);
		} catch ( error ) {
			result.classList.add( 'ai-post-triage-try-error' );
			result.textContent =
				( error as { message?: string } ).message ||
				__( 'The request failed. Try again.', 'ai-post-triage' );
		} finally {
			submit.disabled = false;
			spinner.classList.remove( 'is-active' );
		}
	} );
};

domReady( () => {
	document
		.querySelectorAll< HTMLElement >(
			'[data-ai-post-triage-questions-field]'
		)
		.forEach( initQuestions );

	document
		.querySelectorAll< HTMLElement >( '[data-ai-post-triage-terms]' )
		.forEach( initTermSearch );

	document
		.querySelectorAll< HTMLElement >( '[data-ai-post-triage-try]' )
		.forEach( initTry );

	document
		.querySelectorAll< HTMLElement >( '[data-ai-post-triage-rescore]' )
		.forEach( initRescore );
} );
