export class PBPattern {

	// id = null;
	// name = '';
	// title = '';
	// description = '';
	// content = '';

	// blocks = [];

	// categories = [];
	// keywords = [];

	source = '';
	synced = true;
	// inserter = true;

	// blockTypes = [];
	// templateTypes = [];
	// postTypes = [];

	// filePath = null;

	constructor( patternPost ) {

		console.log('PBPattern', patternPost);

		this.source = patternPost.source || 'user';
		this.synced = patternPost.wp_pattern_sync_status === 'unsynced' ? false : true;

		// this.id = options.id || null;

		// this.title = options.title || '';
		// this.name = options.name || '';
		// this.description = options.description || '';
		// this.content = options.content || '';

		// this.source = options.source || '';
		// this.synced = options.synced || false;
		// this.inserter = options.inserter === false ? false : true;

		// this.categories = options.categories || [];
		// this.keywords = options.keywords || [];

		// this.blockTypes = options.blockTypes || [];
		// this.templateTypes = options.templateTypes || [];
		// this.postTypes = options.postTypes || [];

		// this.filePath = options.filePath || null;

		// this.blocks = parse( this.content );
	}

}
