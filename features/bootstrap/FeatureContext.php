<?php

use Behat\Step\DataTable;
use Behat\Step\DocString;
use Behat\Step\Given;
use donatj\MockWebServer\MockWebServer;
use donatj\MockWebServer\RequestInfo;
use donatj\MockWebServer\Response as MockWebServerResponse;
use GuzzleHttp\Psr7\Response;
use Libresign\NextcloudBehat\NextcloudApiContext;
use Webmozart\Assert\Assert;

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
		Assert::eq($user, $this->currentUser);
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function assureUserExists(string $user): void {
		parent::assureUserExists($user);
		$lastRequest = $this->getLastREquest();
		$headers = $lastRequest->getHeaders();
		Assert::eq($lastRequest->getRequestUri(), '/ocs/v2.php/cloud/users/test');
		Assert::keyExists($headers, 'OCS-ApiRequest');
		Assert::eq($headers['OCS-ApiRequest'], 'true');
		Assert::keyExists($headers, 'Authorization');
		Assert::keyExists($headers, 'Accept');
		Assert::eq($headers['Accept'], 'application/json');
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
	public function sendRequest(string $verb, string $url, DataTable|DocString|array|null $body = null, array $headers = [], array $options = []): void {
		parent::sendRequest($verb, $url, $body, $headers, $options);
		$lastRequest = $this->getLastRequest();
		$parsedInput = $this->getParsedInputFromRequest($lastRequest);

		// Verb
		Assert::eq($lastRequest->getRequestMethod(), $verb);

		// Url
		$actual = preg_replace('/^\/index.php/', '', $lastRequest->getRequestUri());
		$url = $this->parseText($url);
		Assert::eq($actual, $url);

		// Headers
		Assert::count(
			array_intersect_assoc($lastRequest->getHeaders(), $this->requestOptions['headers']),
			count($this->requestOptions['headers'])
		);

		// Form params
		if (array_key_exists('form_params', $this->requestOptions)) {
			Assert::eq($parsedInput, $this->requestOptions['form_params']);
		}

		// JSON payload
		if (array_key_exists('json', $this->requestOptions)) {
			Assert::eq($parsedInput, $this->requestOptions['json']);
		}

		// Raw body payload
		if (array_key_exists('body', $this->requestOptions)) {
			Assert::eq($lastRequest->getInput(), $this->requestOptions['body']);
			if (!str_starts_with($lastRequest->getRequestUri(), '/remote.php/dav/')) {
				Assert::contains(
					(string)($lastRequest->getHeaders()['Content-Type'] ?? $lastRequest->getHeaders()['CONTENT_TYPE'] ?? ''),
					'application/json'
				);
			}
		}
	}

	#[\Override]
	public function sendWebDavRequest(string $user, string $method, string $path, DocString|string|null $body = null, array $headers = []): void {
		parent::sendWebDavRequest($user, $method, $path, $body, $headers);
		$request = $this->getLastRequest();
		$segments = explode('/', ltrim($this->parseText($path), '/'));
		$encodedPath = implode('/', array_map('rawurlencode', $segments));
		Assert::same($request->getRequestUri(), '/remote.php/dav/files/' . rawurlencode($user) . '/' . $encodedPath);
		Assert::same(strtoupper($request->getRequestMethod()), strtoupper($method));
		Assert::keyExists($request->getHeaders(), 'Authorization');
		Assert::same($request->getHeaders()['Accept'], 'application/xml');
		if ($body !== null) {
			Assert::same($request->getInput(), $this->parseText($body instanceof DocString ? $body->getContent() : $body));
		}
	}

	#[Given('set the WebDAV response to:')]
	public function setWebDavResponse(DocString $response): void {
		$this->mockServer->setDefaultResponse(new MockWebServerResponse(
			$response->getContent(),
			['Content-Type' => 'application/xml'],
			207
		));
	}

	private function getParsedInputFromRequest(RequestInfo $requestInfo): array {
		$headers = $requestInfo->getHeaders();
		$contentType = $headers['Content-Type'] ?? $headers['CONTENT_TYPE'] ?? '';
		$input = $requestInfo->getInput();
		if (str_contains((string)$contentType, 'application/json') || $this->isJson($input)) {
			$decoded = json_decode($input, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		return $requestInfo->getParsedInput() ?? [];
	}

	private function hasNestedPayload(array $payload): bool {
		foreach ($payload as $value) {
			if (is_array($value) || $value instanceof \stdClass) {
				return true;
			}
		}

		return false;
	}

	#[Given('set the response to:')]
	public function setTheResponseTo(DocString $response): void {
		// Mock response to be equal to body of request
		$this->mockServer->setDefaultResponse(new MockWebServerResponse(
			(string)$response
		));
	}

	#[Given('set the response with :header header :value to:')]
	public function setTheResponseWithHeaderTo(string $header, string $value, DocString $response): void {
		$this->mockServer->setDefaultResponse(new MockWebServerResponse(
			(string)$response,
			[$header => $value]
		));
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(DataTable $table): void {
		$lastRequest = $this->getLastRequest();
		$parsedInput = $this->getParsedInputFromRequest($lastRequest);
		if ($this->hasNestedPayload($parsedInput)) {
			$body = json_encode($parsedInput);
			Assert::string($body);
			$this->response = new Response(200, [], $body);
		}
		parent::theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues($table);
	}
}
