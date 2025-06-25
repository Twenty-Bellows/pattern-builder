import './AdminLandingPage.scss';

export const AdminLandingPage = () => {
	return (
		<div className="pattern-builder__admin-landing-page">
			<div class="pattern-builder__admin-landing-page__header">
				<h1>Pattern Builder</h1>
				<span>by Twenty Bellows</span>
			</div>

			<div class="pattern-builder__admin-landing-page__body">
				<h2>Welcome to the Pattern Builder!</h2>
				<p>
					This plugin adds functionality to the{ ' ' }
					<b>WordPress Editor</b> to enhance the{ ' ' }
					<b>Pattern Building</b> experience. You'll find all of the
					functionality that the Pattern Builder Provides in the{ ' ' }
					<b>Site Editor</b> and <b>Block Editor</b>. Patterns
					themselves are already a part of WordPress and these tools
					makes Patterns easer to build and use.
				</p>
				<p>
					The following topics help you to better understand how to
					take full advantage of Patterns in your site building.
					<span class="pattern-builder__admin-landing-page__body__muted-text">
						{ ' ' }
						(These links will take you off-site to the Pattern
						Builder documentation.)
					</span>
				</p>
				<h3>The Basics</h3>
				<ul>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#what-are-patterns"
							target="_blank"
						>
							What are <b>Patterns</b>?
						</a>
					</li>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#how-patterns-improve-wordpress"
							target="_blank"
						>
							How do patterns make <b>WordPress</b> better?
						</a>
					</li>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#theme-vs-user-patterns"
							target="_blank"
						>
							What is the difference between a{ ' ' }
							<b>Theme Pattern</b> and a <b>User Pattern</b>?
						</a>
					</li>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#synced-vs-unsynced-patterns"
							target="_blank"
						>
							What is the difference between a{ ' ' }
							<b>Synced Pattern</b> and an <b>Unsynced Pattern</b>
							?
						</a>
					</li>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#themes-synced-patterns"
							target="_blank"
						>
							Can <b>Themes</b> have <b>Synced Patterns</b>?
						</a>
					</li>
				</ul>
				<h3>How to</h3>
				<ul>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#browse-patterns"
							target="_blank"
						>
							Browse <b>Patterns</b>
						</a>
					</li>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#edit-theme-patterns"
							target="_blank"
						>
							Edit <b>Theme Patterns</b>
						</a>
					</li>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#edit-user-patterns"
							target="_blank"
						>
							Edit <b>User Patterns</b>
						</a>
					</li>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#create-new-patterns"
							target="_blank"
						>
							Create <b>New Patterns</b>
						</a>
					</li>
					<li>
						<a
							href="https://twentybellows.com/pattern-builder-help#add-patterns"
							target="_blank"
						>
							Add Patterns to Posts, Pages, Templates (and even
							other patterns)
						</a>
					</li>
				</ul>
			</div>
		</div>
	);
};
