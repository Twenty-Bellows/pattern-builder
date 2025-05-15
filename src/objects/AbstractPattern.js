import { parse } from '@wordpress/blocks';

/**
 * This is a class that unifies the different types of patterns.
 */
export class AbstractPattern {

	name = '';
	title = '';
	description = '';
	content = '';

	blocks = [];

	categories = [];
	keywords = [];

	source = '';
	synced = false;
	inserter = true;

	blockTypes = [];
	templateTypes = [];
	postTypes = [];

	filePath = null;

	constructor( options ) {

		this.title = options.title || '';
		this.name = options.name;
		this.description = options.description || '';
		this.content = options.content || '';

		this.source = options.source || '';
		this.synced = options.synced || false;
		this.inserter = options.inserter === false ? false : true;

		this.categories = options.categories || [];
		this.keywords = options.keywords || [];

		this.blockTypes = options.blockTypes || [];
		this.templateTypes = options.templateTypes || [];
		this.postTypes = options.postTypes || [];

		this.filePath = options.filePath || null;

		this.blocks = parse( this.content );
	}

}

export default AbstractPattern;
