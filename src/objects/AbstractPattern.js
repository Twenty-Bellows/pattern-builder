import { parse } from '@wordpress/blocks';

/**
 * This is a class that unifies the different types of patterns.
 */
export class AbstractPattern {
	id = null;
	name = '';
	title = '';
	description = '';
	content = '';

	_blocks = [];

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
		this.id = options.id || null;

		this.title = options.title || '';
		this.name = options.name || '';
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
	}

	getBlocks() {
		if ( this._blocks.length > 0 ) {
			return this._blocks;
		}
		this._blocks = parse( this.content );
		return this._blocks;
	}
}

export default AbstractPattern;
