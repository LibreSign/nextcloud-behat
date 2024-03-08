<?php

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use donatj\MockWebServer\MockWebServer;
use donatj\MockWebServer\RequestInfo;
use donatj\MockWebServer\Response as MockWebServerResponse;
use GuzzleHttp\Psr7\Response;
use Libresign\NextcloudBehat\NextcloudApiContext;
use PHPUnit\Framework\Assert;

require __DIR__ . '/../../vendor/autoload.php';

class FeatureContext extends NextcloudApiContext {
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
	public function setCurrentUser(string $user): void {
		parent::setCurrentUser($user);
		Assert::assertEquals($this->currentUser, $user);
	}

	/**
	 * @inheritDoc
	 */
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
	 * @inheritDoc
	 */
	public function sendRequest(string $verb, string $url, $body = null, array $headers = [], array $options = []): void {
		parent::sendRequest($verb, $url, $body, $headers, $options);
		$lastRequest = $this->getLastRequest();

		// Verb
		Assert::assertEquals($verb, $lastRequest->getRequestMethod());

		// Url
		$actual = preg_replace('/^\/index.php/', '', $lastRequest->getRequestUri());
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

	/**
	 * @inheritDoc
	 */
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(TableNode $table): void {
		$lastRequest = $this->getLastRequest();
		// Mock response to be equal to body of request
		$this->mockServer->setDefaultResponse(new MockWebServerResponse(
			json_encode($lastRequest->getParsedInput())
		));
		parent::theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues($table);
	}

	/**
	 * @inheritDoc
	 */
	public function theResponseShouldContainTheInitialStateWithTheFollowingValues(string $name, PyStringNode $expected): void {
		switch ($name) {
			case 'appid-string':
				$value = base64_encode((string) $expected);
				break;
			case 'appid-json-object':
				$value = base64_encode(json_encode(['fruit' => 'orange']));
				break;
			case 'appid-json-array':
				$value = base64_encode(json_encode(['orange']));
				break;
			default:
				$value = '';
		}
		$this->response = new Response(
			200,
			[],
			<<<HTML
			<html>
				<body>
					<input type="hidden" id="initial-state-{$name}" value="{$value}">
				</body>
			</html>
			HTML
		);
		parent::theResponseShouldContainTheInitialStateWithTheFollowingValues($name, $expected);
	}
}
