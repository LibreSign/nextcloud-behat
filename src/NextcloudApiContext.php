<?php

namespace Libresign\NextcloudBehat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeSuite;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use DOMDocument;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use PhpBuiltin\RunServerListener;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * Defines application features from the specific context.
 */
class NextcloudApiContext implements Context {
	protected string $testPassword = '123456';
	protected string $adminPassword = 'admin';
	protected string $baseUrl;
	protected RunServerListener $server;
	protected string $currentUser = '';
	protected array $fields = [];
	/**
	 * @var string[]
	 */
	protected array $createdUsers = [];
	protected ResponseInterface $response;
	/** @var CookieJar[] */
	protected $cookieJars;
	protected array $requestOptions = [];

	public function __construct(?array $parameters = []) {
		$this->server = RunServerListener::getInstance();
		$this->baseUrl = RunServerListener::getServerRoot();
		$this->response = new Response();
		$this->cookieJars = [];
		if (isset($parameters['test_password'])) {
			$this->testPassword = $parameters['test_password'];
		}
		if (isset($parameters['admin_password'])) {
			$this->adminPassword = $parameters['admin_password'];
		}
	}

	#[BeforeSuite()]
	public static function beforeSuite(BeforeSuiteScope $scope):void {
		$whoami = (string) exec('whoami');
		if (get_current_user() !== $whoami) {
			$command = implode(' ', $_SERVER['argv'] ?? []);
			throw new Exception(sprintf(
				"Have files that %s is the owner and the user that is running this test is %s, is necessary to be the same user.\n" .
				"You should run the follow command:\n" .
				"runuser -u %s -- %s\n\n",
				get_current_user(), $whoami, get_current_user(), $command));
		}
	}

	#[BeforeScenario()]
	public function setUp(): void {
		$this->createdUsers = [];
	}

	/**
	 * @When as user :user
	 * @param string $user
	 */
	public function setCurrentUser(string $user): void {
		$this->currentUser = $user;
	}

	/**
	 * @When user :user exists
	 * @param string $user
	 */
	public function assureUserExists(string $user): void {
		$response = $this->userExists($user);
		if ($response->getStatusCode() !== 200) {
			$this->createUser($user);
			// Set a display name different than the user ID to be able to
			// ensure in the tests that the right value was returned.
			$this->setUserDisplayName($user);
			$response = $this->userExists($user);
			$this->assertStatusCode($response, 200);
		}
	}

	protected function userExists(string $user): ResponseInterface {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('GET', '/cloud/users/' . $user);
		$this->setCurrentUser($currentUser);
		return $this->response;
	}

	protected function createUser(string $user): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('POST', '/cloud/users', [
			'userid' => $user,
			'password' => $this->testPassword,
		]);
		$this->assertStatusCode($this->response, 200, 'Failed to create user');

		//Quick hack to login once with the current user
		$this->setCurrentUser($user);
		$this->sendOCSRequest('GET', '/cloud/users' . '/' . $user);
		$this->assertStatusCode($this->response, 200, 'Failed to do first login');

		$this->createdUsers[] = $user;

		$this->setCurrentUser($currentUser);
	}

	/** @When /^set the display name of user "([^"]*)" to "([^"]*)"$/  */
	public function setUserDisplayName(string $user, ?string $displayName = null): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$displayName = $displayName ?? $user . '-displayname';
		$this->sendOCSRequest('PUT', '/cloud/users/' . $user, [
			'key' => 'displayname',
			'value' => $displayName,
		]);
		$this->setCurrentUser($currentUser);
	}

	/** @When /^set the email of user "([^"]*)" to "([^"]*)"$/  */
	public function setUserEmail(string $user, string $email): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('PUT', '/cloud/users/' . $user, [
			'key' => 'email',
			'value' => $email
		]);
		$this->setCurrentUser($currentUser);
	}

	/**
	 * @When sending :verb to ocs :url
	 * @param string $verb
	 * @param string $url
	 * @param TableNode|array|null $body
	 */
	public function sendOCSRequest(string $verb, string $url, $body = null, array $headers = [], array $options = []): void {
		$url = '/ocs/v2.php' . $url;
		$headers['OCS-ApiRequest'] = 'true';
		$this->sendRequest($verb, $url, $body, $headers, $options);
	}

	/**
	 * @When sending :verb to :url
	 * @param string $verb
	 * @param string $url
	 * @param TableNode|array|null $body
	 * @param array $headers
	 */
	public function sendRequest(string $verb, string $url, $body = null, array $headers = [], array $options = []): void {
		if (!str_starts_with($url, '/')) {
			$url = '/' . $url;
		}
		if (strpos($url, '/ocs/v2.php') === false) {
			$url = '/index.php' . $url;
		}
		if (str_ends_with($this->baseUrl, '/')) {
			$this->baseUrl = rtrim($this->baseUrl, '/');
		}
		$fullUrl = $this->baseUrl . $url;
		$client = new Client();
		if (!empty($this->currentUser)) {
			$options = array_merge(
				['cookies' => $this->getUserCookieJar($this->currentUser)],
				$options
			);
		}
		if ($body instanceof TableNode) {
			$fd = $body->getRowsHash();
			$options['form_params'] = $this->decodeIfIsJsonString($fd);
		} elseif (is_array($body)) {
			$options['form_params'] = $body;
		}

		$options['headers'] = array_merge($headers, [
			'Accept' => 'application/json',
		]);
		if ($this->currentUser === 'admin') {
			$options['auth'] = ['admin', $this->adminPassword];
		} elseif ($this->currentUser) {
			$options['auth'] = [$this->currentUser, $this->testPassword];
		}

		try {
			list($fullUrl, $options) = $this->beforeRequest($fullUrl, $options);
			$this->requestOptions = $options;
			$this->response = $client->{$verb}($fullUrl, $options);
		} catch (ClientException $ex) {
			$this->response = $ex->getResponse();
		} catch (\GuzzleHttp\Exception\ServerException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	protected function beforeRequest(string $fullUrl, array $options): array {
		$options = $this->parseFormParams($options);
		$fullUrl = $this->parseText($fullUrl);
		return [$fullUrl, $options];
	}

	protected function decodeIfIsJsonString(array $list): array {
		foreach ($list as $key => $value) {
			if ($this->isJson($value)) {
				$list[$key] = json_decode($value);
			}
			if (str_starts_with($value, '(string)')) {
				$list[$key] = substr($value, strlen('(string)'));
			}
		}
		return $list;
	}

	protected function isJson(string $string): bool {
		json_decode($string);
		return json_last_error() === JSON_ERROR_NONE;
	}

	protected function getUserCookieJar(string $user): CookieJar {
		if (!isset($this->cookieJars[$user])) {
			$this->cookieJars[$user] = new CookieJar();
		}
		return $this->cookieJars[$user];
	}

	/**
	 * @param ResponseInterface $response
	 * @param int $statusCode
	 * @param string $message
	 */
	protected function assertStatusCode(ResponseInterface $response, int $statusCode, string $message = ''): void {
		Assert::assertEquals($statusCode, $response->getStatusCode(), $message);
	}

	/**
	 * @When the response should have a status code :code
	 * @param string $code
	 * @throws \InvalidArgumentException
	 */
	public function theResponseShouldHaveStatusCode($code): void {
		$currentCode = $this->response->getStatusCode();
		Assert::assertEquals($code, $currentCode, $this->response->getBody()->getContents());
	}

	/**
	 * @When the response should be a JSON array with the following mandatory values
	 * @param TableNode $table
	 * @throws \InvalidArgumentException
	 */
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(TableNode $table): void {
		$this->response->getBody()->seek(0);
		$expectedValues = $table->getColumnsHash();
		$json = $this->response->getBody()->getContents();
		$this->response->getBody()->seek(0);
		$this->jsonStringMatchWith($json, $expectedValues);
	}

	private function jsonStringMatchWith(string $json, array $expectedValues): void {
		Assert::assertJson($json);
		foreach ($expectedValues as $value) {
			$value['key'] = $this->parseText($value['key']);
			$value['value'] = $this->parseText($value['value']);
			$actual = $this->testAndGetActualValue($value, $json);
			// Test actual value
			if (str_starts_with($value['value'], '(jq)')) {
				$expected = substr($value['value'], 4);
				$this->validateAsJsonQuery($expected, $actual);
				continue;
			}
			if ($this->isJson($actual) && $this->isJson($value['value'])) {
				Assert::assertJsonStringEqualsJsonString($value['value'], $actual, 'Key: ' . $value['key'] . ' JSON: ' . $json);
				continue;
			}
			Assert::assertEquals($value['value'], $actual, 'Key: ' . $value['key'] . ' JSON: ' . $json);
		}
	}

	private function testAndGetActualValue(array $value, string $json): string {
		$realResponseArray = json_decode($json, true);
		Assert::assertIsArray($realResponseArray, 'The response is not a JSON array: ' . $json);
		if (str_starts_with($value['key'], '(jq)')) {
			$actual = $this->evalJsonQuery(
				substr($value['key'], 4),
				$json
			);
			if (!is_string($actual)) {
				$actual = json_encode($actual);
				Assert::assertIsString($actual);
			}
			return $actual;
		}
		$responseAsJsonString = json_encode($realResponseArray);
		Assert::assertIsString($responseAsJsonString);
		Assert::assertArrayHasKey(
			$value['key'],
			$realResponseArray,
			'Not found: "' . $value['key'] . '" at array: ' . $responseAsJsonString
		);
		$actual = $realResponseArray[$value['key']];
		if (!is_string($actual)) {
			$actual = json_encode($actual);
			Assert::assertIsString($actual);
		}
		return $actual;
	}

	/**
	 * @return mixed
	 */
	private function evalJsonQuery(string $jsonQuery, string $target) {
		Assert::assertNotEmpty(`which jq`, 'Is necessary install the jq command to use jq');
		$jq = \JsonQueryWrapper\JsonQueryFactory::createWith($target);
		return $jq->run($jsonQuery);
	}

	private function validateAsJsonQuery(string $expected, string $actual): void {
		Assert::assertNotEmpty(`which jq`, 'Is necessary install the jq command to use jq');
		$jq = \JsonQueryWrapper\JsonQueryFactory::createWith($actual);
		$result = $jq->run($expected);
		Assert::assertTrue($result, 'The jq "' . $expected . '" do not match with: ' . $actual);
	}

	/**
	 * @When fetch field :path from prevous JSON response
	 */
	public function fetchFieldFromPreviousJsonResponse(string $path): void {
		$this->response->getBody()->seek(0);
		$responseArray = json_decode($this->response->getBody()->getContents(), true);
		if (preg_match('/(?<alias>\([^)]*\))(?<patch>.*)/', $path, $matches)) {
			$alias = $matches['alias'];
			$path = $matches['patch'];
		}
		$keys = explode('.', $path);
		$value = $responseArray;
		foreach ($keys as $key) {
			$body = json_encode($responseArray);
			Assert::assertIsString($body);
			Assert::assertArrayHasKey($key, $value, 'Key [' . $key . '] of path [' . $path . '] not found at body: ' . $body);
			$value = $value[$key];
		}
		if (isset($alias)) {
			$this->fields[$alias] = $value;
		}
		$this->fields[$path] = $value;
	}

	/**
	 * @When the response should contain the initial state :name with the following values:
	 */
	public function theResponseShouldContainTheInitialStateWithTheFollowingValues(string $name, PyStringNode $expected): void {
		$this->response->getBody()->seek(0);
		$html = $this->response->getBody()->getContents();
		$dom = new DOMDocument();
		// https://www.php.net/manual/en/domdocument.loadhtml.php#95463
		libxml_use_internal_errors(true);
		if (empty($html) || !$dom->loadHTML($html)) {
			throw new \Exception('The response is not HTML');
		}
		$element = $dom->getElementById('initial-state-' . $name);
		if (!$element) {
			throw new \Exception('Initial state not found: '. $name);
		}
		$base64 = $element->getAttribute('value');
		$actual = base64_decode($base64);
		$actual = $this->parseText($actual);
		$expected = $this->parseText((string) $expected);
		if ($this->isJson($expected)) {
			Assert::assertJsonStringEqualsJsonString($expected, $actual);
		} else {
			if (trim($actual, '"') === json_decode($actual) && str_starts_with($actual, '"')) {
				$actual = trim($actual, '"');
				$expected = trim($expected, '"');
			}
			Assert::assertEquals($expected, $actual);
		}
	}

	/**
	 * @When the response should contain the initial state :name json that match with:
	 */
	public function theResponseShouldContainTheInitialStateJsonThatMatchWith(string $name, TableNode $table): void {
		$this->response->getBody()->seek(0);
		$html = $this->response->getBody()->getContents();
		$dom = new DOMDocument();
		// https://www.php.net/manual/en/domdocument.loadhtml.php#95463
		libxml_use_internal_errors(true);
		if (empty($html) || !$dom->loadHTML($html)) {
			throw new \Exception('The response is not HTML');
		}
		$element = $dom->getElementById('initial-state-' . $name);
		if (!$element) {
			throw new \Exception('Initial state not found: '. $name);
		}
		$base64 = $element->getAttribute('value');
		$actual = base64_decode($base64);
		$actual = $this->parseText($actual);
		$expectedValues = $table->getColumnsHash();
		$this->jsonStringMatchWith($actual, $expectedValues);
	}

	/**
	 * @When the following :appId app config is set
	 *
	 * @param TableNode $formData
	 */
	public function setAppConfig(string $appId, TableNode $formData): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		foreach ($formData->getRows() as $row) {
			$this->sendOCSRequest('POST', '/apps/provisioning_api/api/v1/config/apps/' . $appId . '/' . $row[0], [
				'value' => $row[1],
			]);
		}
		$this->setCurrentUser($currentUser);
	}

	protected function parseFormParams(array $options): array {
		if (!empty($options['form_params'])) {
			$this->parseTextRcursive($options['form_params']);
		}
		return $options;
	}

	private function parseTextRcursive(array &$array): array {
		array_walk_recursive($array, function (mixed &$value) {
			if (is_string($value)) {
				$value = $this->parseText($value);
			} elseif ($value instanceof \stdClass) {
				$value = (array) $value;
				$buffer = json_encode($this->parseTextRcursive($value));
				Assert::assertIsString($buffer);
				$value = json_decode($buffer);
			}
		});
		return $array;
	}

	protected function parseText(string $text): string {
		$patterns = [];
		$replacements = [];
		foreach ($this->fields as $key => $value) {
			$patterns[] = '/<' . $key . '>/';
			$replacements[] = $value;
		}
		$text = preg_replace($patterns, $replacements, $text);
		Assert::assertIsString($text);
		return $text;
	}

	#[AfterScenario()]
	public function tearDown(): void {
		foreach ($this->createdUsers as $user) {
			$this->deleteUser($user);
		}
	}

	protected function deleteUser(string $user): ResponseInterface {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('DELETE', '/cloud/users/' . $user);
		$this->setCurrentUser($currentUser);

		unset($this->createdUsers[array_search($user, $this->createdUsers, true)]);

		return $this->response;
	}
}
