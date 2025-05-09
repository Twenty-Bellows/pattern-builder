
export const PatternDetails = ({ pattern }) => {
	return (
		<div className="pattern-manager_pattern-details">
			<h2>{pattern?.title}</h2>
			<p>{pattern?.description}</p>
		</div>
	);
};

export default PatternDetails;
