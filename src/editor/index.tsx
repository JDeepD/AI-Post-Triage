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
		aiPostTriageEditor?: EditorData;
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
	__( 'AI Post Triage could not be reached.', 'ai-post-triage' );

/**
 * The AI Post Triage panel in the post sidebar.
 */
const AIPostTriagePanel = () => {
	const data = window.aiPostTriageEditor;
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
					path: `/ai-post-triage/v1/posts/${ postId }`,
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
					path: `/ai-post-triage/v1/posts/${ postId }/rescore`,
					method: 'POST',
				} )
			);
			void createSuccessNotice(
				__(
					'This post will be scored again shortly.',
					'ai-post-triage'
				),
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
			name="ai-post-triage"
			title={ __( 'AI Post Triage', 'ai-post-triage' ) }
			className="ai-post-triage-panel"
		>
			{ error && <p className="ai-post-triage-panel-error">{ error }</p> }

			{ item && (
				<>
					<p className="ai-post-triage-panel-summary">
						<span
							className={ `ai-post-triage-band ai-post-triage-band-${ item.band }` }
						>
							{ data.bands[ item.band ] ?? item.band }
						</span>
						{ null !== item.score && (
							<span className="ai-post-triage-score">
								{ item.score }
							</span>
						) }
						<span className="ai-post-triage-panel-state">
							{ data.states[ item.state ] ?? item.state }
						</span>
					</p>

					{ item.error && 'failed' === item.state && (
						<p className="ai-post-triage-panel-error">
							{ item.error }
						</p>
					) }

					{ item.answers && item.answers.length > 0 && (
						<ul className="ai-post-triage-panel-answers">
							{ item.answers.map( ( answer ) => (
								<li
									key={ answer.id }
									className={
										answer.met ? 'is-met' : 'is-missed'
									}
								>
									<span
										className="ai-post-triage-panel-mark"
										aria-hidden="true"
									>
										{ answer.met ? '✓' : '✗' }
									</span>
									<span className="screen-reader-text">
										{ answer.met
											? __( 'Met:', 'ai-post-triage' )
											: __(
													'Not met:',
													'ai-post-triage'
											  ) }
									</span>
									<span className="ai-post-triage-panel-question">
										{ answer.question }
										<span className="ai-post-triage-panel-meta">
											{ sprintf(
												/* translators: %d: the model's probability of yes, as a percentage. */
												__(
													'%d%% yes',
													'ai-post-triage'
												),
												Math.round( answer.yes * 100 )
											) }
											{ answer.required &&
												` · ${ __(
													'Required',
													'ai-post-triage'
												) }` }
										</span>
									</span>
								</li>
							) ) }
						</ul>
					) }

					{ item.scored_at && (
						<p className="ai-post-triage-panel-meta">
							{ sprintf(
								/* translators: 1: model ID, 2: time since the post was scored, such as 5 minutes ago. */
								__( 'Scored by %1$s %2$s', 'ai-post-triage' ),
								item.model,
								humanTimeDiff( `${ item.scored_at }Z` )
							) }
						</p>
					) }

					<div className="ai-post-triage-panel-actions">
						{ item.actions.includes( 'rescore' ) && (
							<Button
								variant="secondary"
								size="compact"
								isBusy={ isBusy }
								disabled={ isBusy }
								accessibleWhenDisabled
								onClick={ () => void rescore() }
							>
								{ __( 'Re-score', 'ai-post-triage' ) }
							</Button>
						) }
						<ExternalLink href={ data.queueUrl }>
							{ __( 'Review queue', 'ai-post-triage' ) }
						</ExternalLink>
					</div>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'ai-post-triage', { render: AIPostTriagePanel } );
