import { Icon } from '@wordpress/components';

const RING_MOTIF =
	'M100,50L100,40L106,40L106,30L94,30L94,20L106,20L106,10L94,10';
const RING_ANGLES = [
	0, 18, 36, 54, 72, 90, 108, 126, 144, 162, 180, 198, 216, 234, 252, 270,
	288, 306, 324, 342,
];

/**
 * The Pattern Builder mark: the anvil inside a twenty-point meander ring,
 * ported from assets/logo_black.svg.
 *
 * @param {Object} props      Component props.
 * @param {number} props.size Pixel size of the square mark.
 */
export const PatternBuilderLogo = ( { size = 24 } ) => (
	<svg
		width={ size }
		height={ size }
		viewBox="0 0 1000 1000"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
		focusable="false"
	>
		<g transform="matrix(1.2,0,0,1.2,500,500)">
			<g transform="matrix(1,0,0,1,-416.667,-416.667)">
				<g transform="matrix(4.16667,0,0,4.16667,0,0)">
					<g
						fill="none"
						stroke="currentColor"
						strokeWidth="6"
						strokeLinecap="square"
						strokeLinejoin="miter"
					>
						{ RING_ANGLES.map( ( angle ) => (
							<path
								key={ angle }
								d={ RING_MOTIF }
								transform={
									angle
										? `rotate(${ angle } 100 100)`
										: undefined
								}
							/>
						) ) }
					</g>
				</g>
			</g>
		</g>
		<g transform="matrix(0.132235,0,0,0.132235,503.586,502.944)">
			<g transform="matrix(1,0,0,1,-1200,-770.417)">
				<g
					transform="matrix(4.16667,0,0,4.16667,0,0)"
					fill="currentColor"
				>
					<path d="M158.8,48.1L20.9,48.2C20.9,94.9 58.4,132.8 104.9,133.7C105.388,133.709 158.8,133.8 158.8,133.8L158.8,48.1Z" />
					<path d="M538.7,34.5C538.7,34.4 179.8,34.4 179.8,34.4L180,199.2C180.8,253.9 131.6,273.9 109,280.2C102.5,282 97.9,288.5 97.9,295.9L97.9,324.1L462.8,324.1L462.8,295.8C462.8,288.5 458.3,282.1 451.8,280.3C426.7,273.3 368.3,249.4 383,179.1C401.6,90.2 544,98.5 544,98.5L544,34.4L540.1,34.4C539.9,34.6 539.6,34.8 539.4,34.8C539.1,34.8 538.9,34.6 538.8,34.5L538.7,34.5Z" />
				</g>
			</g>
		</g>
	</svg>
);

export const patternBuilderAppIcon = () => (
	<Icon icon={ <PatternBuilderLogo /> } size={ 24 } />
);
