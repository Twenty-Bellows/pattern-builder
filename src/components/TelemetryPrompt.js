/**
 * The one-time question: may Pattern Builder report anonymous usage?
 *
 * Shown the first time anyone opens the pattern browser on a site, and
 * never again once answered either way (a site that declined is offered
 * it once more, on the connect panel, as a one-line Allow). Two buttons,
 * neither preselected — the answer is an answer, not a default.
 */

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Flex, FlexItem, Modal } from '@wordpress/components';

import { setTelemetryConsent } from '../utils/telemetry';

/**
 * What is and is not sent, in one place, so the prompt and the readme agree.
 *
 * @return {string[]} Bullet points.
 */
export function telemetryFacts() {
	return [
		__(
			'What is sent: which parts of Pattern Builder are used (the browser opened, a pattern created, the community browsed), and the environment — WordPress, PHP and plugin versions, locale, theme, multisite.',
			'pattern-builder'
		),
		__(
			'What is never sent: your site’s address or name, pattern content, or anything about your visitors. The site is identified by a random id.',
			'pattern-builder'
		),
		__(
			'It goes to patternbuilderwp.com, and you can turn it off any time from this screen.',
			'pattern-builder'
		),
	];
}

/**
 * The prompt.
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onAnswer  Called with the new state after either button.
 * @param {Function} props.onDismiss Called when closed without answering (asked again next time).
 */
export function TelemetryPrompt( { onAnswer, onDismiss } ) {
	const [ busy, setBusy ] = useState( false );

	const answer = ( allow ) => {
		setBusy( true );
		setTelemetryConsent( allow )
			.then( ( next ) => onAnswer( next ) )
			.catch( () => setBusy( false ) );
	};

	return (
		<Modal
			title={ __( 'Help improve Pattern Builder?', 'pattern-builder' ) }
			onRequestClose={ onDismiss }
			className="pattern-builder-telemetry-prompt"
			size="medium"
		>
			<p>
				{ __(
					'Pattern Builder can report anonymous usage so we know which features matter and where people get stuck.',
					'pattern-builder'
				) }
			</p>
			<ul className="pattern-builder-telemetry-prompt__facts">
				{ telemetryFacts().map( ( fact, index ) => (
					<li key={ index }>{ fact }</li>
				) ) }
			</ul>
			<Flex justify="flex-end" gap={ 2 }>
				<FlexItem>
					<Button
						variant="tertiary"
						disabled={ busy }
						onClick={ () => answer( false ) }
					>
						{ __( 'No thanks', 'pattern-builder' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () => answer( true ) }
					>
						{ __( 'Allow', 'pattern-builder' ) }
					</Button>
				</FlexItem>
			</Flex>
		</Modal>
	);
}

/**
 * The one-line second offer, for a site that said no on the prompt.
 *
 * @param {Object}   props          Component props.
 * @param {Function} props.onAnswer Called with the new state after Allow.
 */
export function TelemetryOffer( { onAnswer } ) {
	const [ busy, setBusy ] = useState( false );

	return (
		<p className="pattern-builder-telemetry-offer">
			<span>
				{ __(
					'Help improve Pattern Builder by sharing anonymous usage.',
					'pattern-builder'
				) }
			</span>{ ' ' }
			<Button
				variant="link"
				isBusy={ busy }
				disabled={ busy }
				onClick={ () => {
					setBusy( true );
					setTelemetryConsent( true )
						.then( ( next ) => onAnswer( next ) )
						.catch( () => setBusy( false ) );
				} }
			>
				{ __( 'Allow', 'pattern-builder' ) }
			</Button>
		</p>
	);
}
