<?php

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use donatj\MockWebServer\MockWebServer;
use donatj\MockWebServer\RequestInfo;
use donatj\MockWebServer\Response as MockWebServerResponse;
use GuzzleHttp\Psr7\Response;
use Libresign\NextcloudBehat\NextcloudApiContext;
use PHPUnit\Framework\Assert;

require __DIR__ . '/../../vendor/autoload.php';

final class FeatureContext extends NextcloudApiContext {
	protected MockWebServer $mockServer;
	public function __construct(?array $parameters = []) {
		parent::__construct($parameters);
		$this->mockServer = new MockWebServer();
		$this->mockServer->start();
		$this->baseUrl = $this->mockServer->getServerRoot() . '/';
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function setCurrentUser(string $user): void {
		parent::setCurrentUser($user);
		Assert::assertEquals($this->currentUser, $user);
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function assureUserExists(string $user): void {
		parent::assureUserExists($user);
		$lastRequest = $this->getLastREquest();
		$headers = $lastRequest->getHeaders();
		Assert::assertEquals('/ocs/v2.php/cloud/users/test', $lastRequest->getRequestUri());
		Assert::assertArrayHasKey('OCS-ApiRequest', $headers);
		Assert::assertEquals('true', $headers['OCS-ApiRequest']);
		Assert::assertArrayHasKey('Authorization', $headers);
		Assert::assertArrayHasKey('Accept', $headers);
		Assert::assertEquals('application/json', $headers['Accept']);
	}

	private function getLastRequest(): RequestInfo {
		$lastRequest = $this->mockServer->getLastRequest();
		if (!$lastRequest instanceof RequestInfo) {
			throw new Exception('Invalid response');
		}
		return $lastRequest;
	}

	/**
	 * When whe run the test suit of this repository at GitHub Actions, is
	 * necessary to consider that we haven't Nextcloud installed and mock
	 * the real path of files.
	 */
	#[\Override]
	public static function findParentDirContainingFile(string $filename): string {
		return __DIR__;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function sendRequest(string $verb, string $url, $body = null, array $headers = [], array $options = []): void {
		parent::sendRequest($verb, $url, $body, $headers, $options);
		$lastRequest = $this->getLastRequest();

		// Verb
		Assert::assertEquals($verb, $lastRequest->getRequestMethod());

		// Url
		$actual = preg_replace('/^\/index.php/', '', $lastRequest->getRequestUri());
		$url = $this->parseText($url);
		Assert::assertEquals($url, $actual);

		// Headers
		Assert::assertCount(
			count($this->requestOptions['headers']),
			array_intersect_assoc($lastRequest->getHeaders(), $this->requestOptions['headers'])
		);

		// Form params
		if (array_key_exists('form_params', $this->requestOptions)) {
			Assert::assertEquals($this->requestOptions['form_params'], $lastRequest->getParsedInput());
		}
	}

	#[Given('set the response to:')]
	public function setTheResponseTo(PyStringNode $response): void {
		// Mock response to be equal to body of request
		$this->mockServer->setDefaultResponse(new MockWebServerResponse(
			(string) $response
		));
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(TableNode $table): void {
		$lastRequest = $this->getLastRequest();
		$body = json_encode($lastRequest->getParsedInput());
		Assert::assertIsString($body);
		// Mock response to be equal to body of request
		$this->mockServer->setDefaultResponse(new MockWebServerResponse(
			$body
		));
		parent::theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues($table);
	}
}
