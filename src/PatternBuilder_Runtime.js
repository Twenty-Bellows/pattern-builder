/**
 * The pattern runtime: teaches the editor that `core/pattern` carries content.
 *
 * Vendored from the Synced Patterns for Themes plugin. This bundle is only
 * enqueued when that plugin is NOT active — when it is, its own identical
 * runtime is the single provider and Pattern Builder defers to it.
 */

import { extendPatternOverridesSource } from './runtime/pattern-overrides-source';

import './runtime/pattern-content-attribute';
import './runtime/pattern-content-edit';

extendPatternOverridesSource();
