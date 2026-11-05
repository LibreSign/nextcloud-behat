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

return (new Config())
	->withProfile((new Profile('default', [
		'autoload' => [
			'' => '%paths.base%/features/bootstrap',
		],
	]))
		->withSuite((new Suite('default'))
			->addContext(FeatureContext::class, [
				'parameters' => [
					'test_password' => '123456',
					'admin_password' => 'admin',
				],
			])
			->withPaths('%paths.base%/features'))
		->withExtension(new Extension(Server::class, [
			'verbose' => false,
			'rootDir' => __DIR__,
			'host' => 'localhost',
		])))
;
