/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
// eslint-disable-next-line @wordpress/use-recommended-components -- WordPress 7.1 doesn't ship @wordpress/ui as a script.
import { Button, ExternalLink } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { humanTimeDiff } from '@wordpress/date';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import './styles.scss';

type Answer = {
	id: string;
	question: string;
	expected: 'yes' | 'no';
	weight: string;
	required: boolean;
	yes: number;
	met: boolean;
};

type WirePost = {
	id: number;
	state: string;
	band: string;
	score: number | null;
	model: string;
	scored_at: string | null;
	error: string;
	answers: Answer[] | null;
	actions: string[];
};

type EditorData = {
	isWire: boolean;
	queueUrl: string;
	bands: Record< string, string >;
	states: Record< string, string >;
};

type ApiError = {
	code?: string;
	message?: string;
};

declare global {
	interface Window {
		wireDeskEditor?: EditorData;
	}
}

const NOT_FOUND = 'rest_post_invalid_id';

/**
 * The error message from a failed request.
 *
 * @param error Thrown value.
 */
const errorMessage = ( error: unknown ): string =>
	( error as ApiError )?.message ||
	__( 'Wire Desk could not be reached.', 'wire-desk' );

/**
 * The Wire Desk panel in the post sidebar.
 */
const WireDeskPanel = () => {
	const data = window.wireDeskEditor;
	const { postId, isSaving } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId() as number | null,
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const [ item, setItem ] = useState< WirePost | null >( null );
	const [ error, setError ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const wasSaving = useRef( false );

	const load = useCallback( async () => {
		if ( ! postId ) {
			return;
		}

		try {
			setItem(
				await apiFetch< WirePost >( {
					path: `/wire-desk/v1/posts/${ postId }`,
				} )
			);
			setError( '' );
		} catch ( thrown ) {
			if ( NOT_FOUND === ( thrown as ApiError )?.code ) {
				setItem( null );
				setError( '' );
			} else {
				setError( errorMessage( thrown ) );
			}
		}
	}, [ postId ] );

	useEffect( () => {
		if ( data?.isWire ) {
			void load();
		}
	}, [ data, load ] );

	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			void load();
		}

		wasSaving.current = isSaving;
	}, [ isSaving, load ] );

	const rescore = async () => {
		setIsBusy( true );

		try {
			setItem(
				await apiFetch< WirePost >( {
					path: `/wire-desk/v1/posts/${ postId }/rescore`,
					method: 'POST',
				} )
			);
			void createSuccessNotice(
				__( 'This post will be scored again shortly.', 'wire-desk' ),
				{ type: 'snackbar' }
			);
		} catch ( thrown ) {
			void createErrorNotice( errorMessage( thrown ), {
				type: 'snackbar',
			} );
		} finally {
			setIsBusy( false );
		}
	};

	if ( ! data || ( ! item && ! error ) ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="wire-desk"
			title={ __( 'Wire Desk', 'wire-desk' ) }
			className="wire-desk-panel"
		>
			{ error && <p className="wire-desk-panel-error">{ error }</p> }

			{ item && (
				<>
					<p className="wire-desk-panel-summary">
						<span
							className={ `wire-desk-band wire-desk-band-${ item.band }` }
						>
							{ data.bands[ item.band ] ?? item.band }
						</span>
						{ null !== item.score && (
							<span className="wire-desk-score">
								{ item.score }
							</span>
						) }
						<span className="wire-desk-panel-state">
							{ data.states[ item.state ] ?? item.state }
						</span>
					</p>

					{ item.error && 'failed' === item.state && (
						<p className="wire-desk-panel-error">{ item.error }</p>
					) }

					{ item.answers && item.answers.length > 0 && (
						<ul className="wire-desk-panel-answers">
							{ item.answers.map( ( answer ) => (
								<li
									key={ answer.id }
									className={
										answer.met ? 'is-met' : 'is-missed'
									}
								>
									<span
										className="wire-desk-panel-mark"
										aria-hidden="true"
									>
										{ answer.met ? '✓' : '✗' }
									</span>
									<span className="screen-reader-text">
										{ answer.met
											? __( 'Met:', 'wire-desk' )
											: __( 'Not met:', 'wire-desk' ) }
									</span>
									<span className="wire-desk-panel-question">
										{ answer.question }
										<span className="wire-desk-panel-meta">
											{ sprintf(
												/* translators: %d: the model's probability of yes, as a percentage. */
												__( '%d%% yes', 'wire-desk' ),
												Math.round( answer.yes * 100 )
											) }
											{ answer.required &&
												` · ${ __(
													'Required',
													'wire-desk'
												) }` }
										</span>
									</span>
								</li>
							) ) }
						</ul>
					) }

					{ item.scored_at && (
						<p className="wire-desk-panel-meta">
							{ sprintf(
								/* translators: 1: model ID, 2: time since the post was scored, such as 5 minutes ago. */
								__( 'Scored by %1$s %2$s', 'wire-desk' ),
								item.model,
								humanTimeDiff( `${ item.scored_at }Z` )
							) }
						</p>
					) }

					<div className="wire-desk-panel-actions">
						{ item.actions.includes( 'rescore' ) && (
							<Button
								variant="secondary"
								size="compact"
								isBusy={ isBusy }
								disabled={ isBusy }
								accessibleWhenDisabled
								onClick={ () => void rescore() }
							>
								{ __( 'Re-score', 'wire-desk' ) }
							</Button>
						) }
						<ExternalLink href={ data.queueUrl }>
							{ __( 'Review queue', 'wire-desk' ) }
						</ExternalLink>
					</div>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'wire-desk', { render: WireDeskPanel } );
