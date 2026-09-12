/**
 * The pattern runtime: teaches the editor that `core/pattern` carries content.
 */

import { extendPatternOverridesSource } from './runtime/pattern-overrides-source';

import './runtime/pattern-content-attribute';
import './runtime/pattern-content-edit';

extendPatternOverridesSource();
