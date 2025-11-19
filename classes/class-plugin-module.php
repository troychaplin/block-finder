<?php
/**
 * Base class for plugin modules which can be initialized.
 *
 * @package Block_Finder
 */

namespace Block_Finder;

/**
 * Plugin module extended by other classes.
 */
abstract class Plugin_Module {
	/**
	 * Initialize the module.
	 */
	abstract public function init();
}
