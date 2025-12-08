<?php
/**
 * BatchQueue Plugin Bootstrap
 *
 * This file is loaded automatically when the plugin is loaded.
 */

use Cake\Core\Configure;

// Load default configuration if not already set
if (!Configure::check('BatchQueue')) {
    // Configure::load('BatchQueue.app_local', 'default', false);
}
