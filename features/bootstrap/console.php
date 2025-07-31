<?php

/**
 * When whe run the test suit of this repository at GitHub Actions, is
 * necessary to consider that we haven't Nextcloud installed and mock
 * the occ commands execution.
 */

if (in_array('invalid-command', $argv)) {
	echo "Invalid command\n";
	exit(1);
}

if (getenv('OC_PASS') === '123456') {
	echo "I found the environment variable OC_PASS with value 123456\n";
	exit;
}
