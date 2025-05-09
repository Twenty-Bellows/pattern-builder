
export const PatternDetails = ({ pattern }) => {
	return (
		<div className="pattern-manager_pattern-details">
			<h2>{pattern?.title}</h2>
			<p>{pattern?.name}</p>
			<p>{pattern?.description}</p>
			<p>{pattern?.synced ? 'SYNCED' : 'UNSYNCED'}</p>
			<p>{pattern?.source}</p>
		</div>
	);
};

export default PatternDetails;
