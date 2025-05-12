/**
 * This is a class that unifies the different types of patterns.
 */
export class AbstractPattern {

	name = '';
	title = '';
	description = '';
	content = '';

	categories = [];
	keywords = [];

	source = '';
	synced = false;
	inserter = false;

	filePath = null;

	constructor( options ) {
		this.title = options.title || '';
		this.name = options.name;
		this.description = options.description || '';
		this.content = options.content || '';

		this.source = options.source || '';
		this.synced = options.synced || false;
		this.inserter = options.inserter || false;

		this.filePath = options.filePath || null;
	}

}

export default AbstractPattern;
