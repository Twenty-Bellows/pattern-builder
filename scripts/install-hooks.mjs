#!/usr/bin/env node
/**
 * Point git at the hooks this repository ships.
 *
 * `prepare` runs on every `npm install`, so a fresh clone gets the pre-push check
 * without anyone having to be told it exists. It never fails the install: a checkout
 * with no git — a zip of the plugin, a vendored copy — has nothing to configure and
 * is not a broken one.
 */
import { execFileSync } from 'node:child_process';

try {
	execFileSync( 'git', [ 'config', 'core.hooksPath', '.githooks' ], {
		stdio: 'ignore',
	} );
} catch {
	// Not a git checkout, or no git on the path. Nothing to hook.
}
