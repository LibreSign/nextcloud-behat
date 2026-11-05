<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Behat\Gherkin\Node\TableNode;
use Behat\Step\DataTable;
use GuzzleHttp\Psr7\Response;
use Libresign\NextcloudBehat\NextcloudApiContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class NextcloudApiContextAssertionsTest extends TestCase {
	private TestingApiContext $context;

	protected function setUp(): void {
		$this->context = new TestingApiContext();
	}

	public static function matchingJson(): iterable {
		yield 'object keys may be reordered' => ['{"payload":{"b":2,"a":{"x":null,"y":true}}}', '{"a":{"y":true,"x":null},"b":2}'];
		yield 'ordered list of objects' => ['{"payload":[{"b":2,"a":1},{"d":4,"c":3}]}', '[{"a":1,"b":2},{"c":3,"d":4}]'];
		yield 'boolean' => ['{"payload":true}', 'true'];
		yield 'null' => ['{"payload":null}', 'null'];
	}

	#[DataProvider('matchingJson')]
	public function testEquivalentJsonPasses(string $json, string $expected): void {
		$this->context->setTestResponse(new Response(200, [], $json));
		$this->context->theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(self::table('payload', $expected));
		$this->addToAssertionCount(1);
	}

	public function testReorderedListFails(): void {
		$this->context->setTestResponse(new Response(200, [], '{"payload":[1,2]}'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Key: payload');
		$this->context->theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(self::table('payload', '[2,1]'));
	}

	public function testObjectIsNotEquivalentToArray(): void {
		$this->context->setTestResponse(new Response(200, [], '{"payload":[]}'));
		$this->expectException(\InvalidArgumentException::class);
		$this->context->theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(self::table('payload', '{}'));
	}

	public function testInvalidJsonIsRejected(): void {
		$this->context->setTestResponse(new Response(200, [], '{invalid-json'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid response body');
		$this->context->theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(self::table('payload', 'value'));
	}

	public function testMissingKeyHasUsefulFailure(): void {
		$this->context->setTestResponse(new Response(200, [], '{"available":"ok"}'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Not found: "missing"');
		$this->context->theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(self::table('missing', 'value'));
	}

	public function testScalarMismatchHasUsefulFailure(): void {
		$this->context->setTestResponse(new Response(200, [], '{"available":"ok"}'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('actual ok');
		$this->context->theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(self::table('available', 'different'));
	}

	public function testExpectedStatusPasses(): void {
		$this->context->setTestResponse(new Response(201, [], 'created'));
		$this->context->theResponseShouldHaveStatusCode('201');
		$this->addToAssertionCount(1);
	}

	public function testUnexpectedHttpStatusFails(): void {
		$this->context->setTestResponse(new Response(503, [], 'Service temporarily unavailable'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Expected HTTP status 200, got 503');
		$this->context->theResponseShouldHaveStatusCode('200');
	}

	public function testHeaderMatchIsCaseInsensitive(): void {
		$this->context->setTestResponse(new Response(200, ['Content-Type' => 'APPLICATION/JSON'], 'hello'));
		$this->context->theResponseHeaderShouldContain('Content-Type', 'application/json');
		$this->addToAssertionCount(1);
	}

	public function testHeaderMismatchFails(): void {
		$this->context->setTestResponse(new Response(200, ['Content-Type' => 'text/html'], 'hello'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('does not contain');
		$this->context->theResponseHeaderShouldContain('Content-Type', 'application/json');
	}

	public function testRegexMatches(): void {
		$this->context->setTestResponse(new Response(200, [], '%PDF-1.4'));
		$this->context->theResponseBodyShouldMatchTheRegularExpression('^%PDF');
		$this->addToAssertionCount(1);
	}

	public function testRegexMismatchFails(): void {
		$this->context->setTestResponse(new Response(200, [], 'hello'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('does not match pattern');
		$this->context->theResponseBodyShouldMatchTheRegularExpression('^%PDF');
	}

	public function testEmptyBodyFails(): void {
		$this->context->setTestResponse(new Response(200, [], ''));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Response body is empty');
		$this->context->theResponseBodyShouldNotBeEmpty();
	}

	public function testBeforeScenarioClearsPreviousCommandOutput(): void {
		$property = new \ReflectionProperty(NextcloudApiContext::class, 'commandOutput');
		$property->setValue(null, 'left over from previous scenario');
		NextcloudApiContext::beforeScenario();
		$this->context->theOutputOfTheLastCommandShouldBeEmpty();
		self::assertSame('', $property->getValue());
	}

	private static function table(string $key, string $value): DataTable {
		return new DataTable(new TableNode([['key', 'value'], [$key, $value]]));
	}
}

final class TestingApiContext extends NextcloudApiContext {
	public function setTestResponse(ResponseInterface $response): void {
		$this->response = $response;
	}
}
