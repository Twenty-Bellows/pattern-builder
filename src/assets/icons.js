import { Icon } from '@wordpress/components';

/**
 * The Pattern Builder mark: two blocks over a full-width block. Drawn in
 * currentColor so it inherits admin, editor, and dark-mode colors.
 *
 * @param {Object} props      Component props.
 * @param {number} props.size Pixel size of the square mark.
 */
export const PatternBuilderLogo = ( { size = 24 } ) => (
	<svg
		width={ size }
		height={ size }
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.8"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
		focusable="false"
	>
		<rect x="2.9" y="3.6" width="8.2" height="7.4" rx="1.7" />
		<rect x="12.9" y="3.6" width="8.2" height="7.4" rx="1.7" />
		<rect x="2.9" y="13.6" width="18.2" height="6.8" rx="1.7" />
	</svg>
);

export const patternBuilderAppIcon = () => (
	<Icon icon={ <PatternBuilderLogo /> } size={ 24 } />
);
