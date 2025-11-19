<?php
/**
 * Base class for plugin modules which can be initialized.
 *
 * @package BlockFinder
 */

namespace BlockFinder;

/**
 * Plugin module extended by other classes.
 */
abstract class Plugin_Module {
	/**
	 * Initialize the module.
	 */
	abstract public function init();
}