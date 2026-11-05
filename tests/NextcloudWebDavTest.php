<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use GuzzleHttp\Psr7\Response;
use Libresign\NextcloudBehat\NextcloudApiContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class NextcloudWebDavTest extends TestCase {
	private const XML = <<<'XML'
		<d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" xmlns:oc="http://owncloud.org/ns">
		  <d:response><d:propstat><d:prop>
		    <nc:fileid>123</nc:fileid>
		    <d:getetag>etag</d:getetag>
		    <oc:permissions>RW</oc:permissions>
		  </d:prop></d:propstat></d:response>
		</d:multistatus>
		XML;

	public static function supportedProperties(): iterable {
		yield 'Nextcloud default namespace' => ['fileid', '123'];
		yield 'explicit Nextcloud namespace' => ['nc:fileid', '123'];
		yield 'DAV namespace' => ['d:getetag', 'etag'];
		yield 'ownCloud namespace' => ['oc:permissions', 'RW'];
	}

	#[DataProvider('supportedProperties')]
	public function testReadWebDavProperty(string $property, string $expected): void {
		$context = new WebDavTestContext();
		$context->setTestResponse(new Response(207, [], self::XML));
		$context->theWebDavResponseShouldContainPropertyWithValue($property, $expected);
		$context->fetchWebDavPropertyTo($property, 'RESULT');
		self::assertSame($expected, $context->getField('RESULT'));
	}

	public function testMissingPropertyFails(): void {
		$context = new WebDavTestContext();
		$context->setTestResponse(new Response(207, [], self::XML));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not found');
		$context->fetchWebDavPropertyTo('nc:does-not-exist', 'RESULT');
	}

	public function testXmlPropertyCannotInjectXPath(): void {
		$context = new WebDavTestContext();
		$context->setTestResponse(new Response(207, [], self::XML));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid WebDAV property name');
		$context->fetchWebDavPropertyTo('fileid[1]', 'RESULT');
	}

	public function testMalformedXmlFailsClearly(): void {
		$context = new WebDavTestContext();
		$context->setTestResponse(new Response(207, [], '<d:multistatus'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid XML');
		$context->fetchWebDavPropertyTo('fileid', 'RESULT');
	}

	public function testUnexpectedWebDavStatusFails(): void {
		$context = new WebDavTestContext();
		$context->setTestResponse(new Response(404, [], self::XML));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('207');
		$context->fetchWebDavPropertyTo('fileid', 'RESULT');
	}

	public function testWebDavPathTraversalIsRejected(): void {
		$context = new WebDavTestContext();
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('traversal');
		$context->sendWebDavRequest('test', 'GET', 'Documents/../secret.pdf');
	}

	public function testUnsupportedWebDavMethodIsRejected(): void {
		$context = new WebDavTestContext();
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported');
		$context->sendWebDavRequest('test', 'POST', 'secret.pdf');
	}
}

final class WebDavTestContext extends NextcloudApiContext {
	public function setTestResponse(ResponseInterface $response): void {
		$this->response = $response;
	}

	public function getField(string $key): ?string {
		return $this->fields[$key] ?? null;
	}
}
