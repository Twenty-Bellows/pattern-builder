/**
 * This is a class that unifies the different types of patterns.
 */
export class Pattern {

	title = '';
	content = '';
	source = '';
	slug = '';
	synced = false;

	constructor( options ) {
		this.title = options.title || '';
		this.content = options.content || '';
		this.source = options.source || '';
		this.name = options.name;
		this.synced = options.synced || false;
	}

}

export default Pattern;
