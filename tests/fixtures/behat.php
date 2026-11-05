<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Profile;
use Behat\Config\Suite;
use PhpBuiltin\Server;

$projectRoot = dirname(__DIR__, 2);

return (new Config())
	->withProfile((new Profile('default', [
		'autoload' => ['' => $projectRoot . '/features/bootstrap'],
	]))
		->withSuite((new Suite('default'))
			->withContexts(FeatureContext::class)
			->withPaths(__DIR__ . '/failure.feature'))
		->withExtension(new Extension(Server::class, [
			'rootDir' => $projectRoot,
			'host' => 'localhost',
		])))
;
