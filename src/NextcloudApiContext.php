<?php

namespace Libresign\NextcloudBehat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeSuite;
use Behat\Step\Given;
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
	protected static array $environments = [];
	protected static string $commandOutput = '';
	protected int $startWaitFor = 0;
	protected array $customHeaders = [];

	/**
	 * @var string[]
	 */
	protected static array $createdUsers = [];
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
	public static function beforeScenario(): void {
		self::$createdUsers = [];
		self::$environments = [];
	}

	#[Given('as user :user')]
	public function setCurrentUser(string $user): void {
		$this->currentUser = $user;
	}

	#[Given('user :user exists')]
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

	#[Given('guest :guest exists')]
	public function assureGuestExists(string $guest): void {
		$response = $this->userExists($guest);
		if ($response->getStatusCode() !== 200) {
			static::createAnEnvironmentWithValueToBeUsedByOccCommand('OC_PASS', '123456');
			$this->runCommandWithResultCode('guests:add admin ' . $guest . ' --password-from-env', 0);
			// Set a display name different than the user ID to be able to
			// ensure in the tests that the right value was returned.
			$this->setUserDisplayName($guest);
			self::$createdUsers[] = $guest;
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

		self::$createdUsers[] = $user;

		$this->setCurrentUser($currentUser);
	}

	#[Given('/^set the display name of user "([^"]*)" to "([^"]*)"$/')]
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

	#[Given('/^set the email of user "([^"]*)" to "([^"]*)"$/')]
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
	 * @param string $verb
	 * @param string $url
	 * @param TableNode|array|null $body
	 */
	#[Given('sending :verb to ocs :url')]
	public function sendOCSRequest(string $verb, string $url, $body = null, array $headers = [], array $options = []): void {
		$url = '/ocs/v2.php' . $url;
		$headers['OCS-ApiRequest'] = 'true';
		$this->sendRequest($verb, $url, $body, $headers, $options);
	}

	/**
	 * @param string $verb
	 * @param string $url
	 * @param TableNode|array|null $body
	 * @param array $headers
	 */
	#[Given('sending :verb to :url')]
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
		if ($this->currentUser !== '') {
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
		], $this->customHeaders);

		if ($this->currentUser === 'admin') {
			$options['auth'] = ['admin', $this->adminPassword];
		} elseif ($this->currentUser) {
			$options['auth'] = [$this->currentUser, $this->testPassword];
		}

		try {
			list($fullUrl, $options) = $this->beforeRequest($fullUrl, $options);
			$options = $this->normalizePayloadForRequest($verb, $options);
			$this->requestOptions = $options;
			$this->response = $client->{$verb}($fullUrl, $options);
		} catch (ClientException $ex) {
			$this->response = $ex->getResponse();
		} catch (\GuzzleHttp\Exception\ServerException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	private function normalizePayloadForRequest(string $verb, array $options): array {
		if (empty($options['form_params'])) {
			return $options;
		}

		$writeVerbs = ['post', 'put', 'patch'];
		if (!in_array(strtolower($verb), $writeVerbs, true)) {
			return $options;
		}

		$hasComplexPayload = false;
		foreach ($options['form_params'] as $value) {
			if (is_array($value) || $value instanceof \stdClass) {
				$hasComplexPayload = true;
				break;
			}
		}

		if (!$hasComplexPayload) {
			return $options;
		}

		$encoded = json_encode($options['form_params']);
		Assert::assertIsString($encoded);
		$decoded = json_decode($encoded, true);
		Assert::assertIsArray($decoded);

		$options['json'] = $decoded;
		unset($options['form_params']);
		if (!isset($options['headers']['Content-Type'])) {
			$options['headers']['Content-Type'] = 'application/json';
		}

		return $options;
	}

	#[Given('/^set the custom http header "([^"]*)" with "([^"]*)" as value to next request$/')]
	public function setTheCustomHttpHeaderAsValueToNextRequest(string $header, string $value):void {
		if (empty($value)) {
			unset($this->customHeaders[$header]);
			return;
		}
		$this->customHeaders[$header] = $this->parseText($value);
	}

	protected function beforeRequest(string $fullUrl, array $options): array {
		$options = $this->parseFormParams($options);
		$fullUrl = $this->parseText($fullUrl);
		return [$fullUrl, $options];
	}

	protected function decodeIfIsJsonString(array $list): array {
		foreach ($list as $key => $value) {
			if (!is_string($value)) {
				continue;
			}
			if (str_starts_with($value, '(string)')) {
				$list[$key] = substr($value, strlen('(string)'));
				continue;
			}
			if ($this->isJson($value)) {
				$list[$key] = json_decode($value);
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

	protected function assertStatusCode(ResponseInterface $response, int $statusCode, string $message = ''): void {
		Assert::assertEquals($statusCode, $response->getStatusCode(), $message);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	#[Given('the response should have a status code :code')]
	public function theResponseShouldHaveStatusCode(string $code): void {
		$currentCode = $this->response->getStatusCode();
		Assert::assertEquals($code, $currentCode, $this->response->getBody()->getContents());
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	#[Given('the response should be a JSON array with the following mandatory values')]
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

	#[Given('fetch field :path from previous JSON response')]
	public function fetchFieldFromPreviousJsonResponse(string $path): void {
		$this->response->getBody()->seek(0);
		$body = $this->response->getBody()->getContents();

		// Is json query
		if (preg_match('/(?<alias>\([^)]*\))\(jq\)(?<path>.*)/', $path, $matches)) {
			$this->fields[$matches['alias']] = $this->testAndGetActualValue(
				['key' => '(jq)' . $matches['path']],
				$body
			);
			return;
		}

		// Is array with alias
		if (preg_match('/(?<alias>\([^)]*\)){1,}(?<path>.*)/', $path, $matches)) {
			$alias = $matches['alias'];
			$path = $matches['path'];
		}
		$keys = explode('.', $path);
		$value = json_decode($body, true);
		foreach ($keys as $key) {
			Assert::assertArrayHasKey($key, $value, 'Key [' . $key . '] of path [' . $path . '] not found at body: ' . $body);
			$value = $value[$key];
		}
		if (isset($alias)) {
			$this->fields[$alias] = $value;
		}
		$this->fields[$path] = $value;
	}

	#[Given('the response should contain the initial state :name with the following values:')]
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

	#[Given('the response should contain the initial state :name json that match with:')]
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

	#[Given('the following :appId app config is set')]
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
			$options['form_params'] = $this->decodeIfIsJsonString($options['form_params']);
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

	#[Given('/^run the command "(?P<command>(?:[^"]|\\")*)"$/')]
	public static function runCommand(string $command): array {
		$console = static::findParentDirContainingFile('console.php');
		$console .= '/console.php';
		$fileOwnerUid = fileowner($console);
		if (!is_int($fileOwnerUid)) {
			throw new \Exception('The console file owner of ' . $console . ' is not an integer UID.');
		}
		$owner = posix_getpwuid($fileOwnerUid);
		if ($owner === false) {
			throw new \Exception('Could not retrieve owner information for UID ' . $fileOwnerUid);
		}
		$fullCommand = 'php ' . $console . ' ' . $command;
		if (!empty(self::$environments)) {
			$fullCommand = http_build_query(self::$environments, '', ' ') . ' ' . $fullCommand;
		}
		if (posix_getuid() !== $owner['uid']) {
			$fullCommand = 'runuser -u ' . $owner['name'] . ' -- ' . $fullCommand;
		}
		$fullCommand .= '  2>&1';
		return self::runBashCommand($fullCommand);
	}

	public static function findParentDirContainingFile(string $filename): string {
		$dir = getcwd();
		if (is_bool($dir)) {
			throw new \Exception('Could not get current working directory (getcwd() returned false)');
		}

		while ($dir !== dirname($dir)) {
			if (file_exists($dir . DIRECTORY_SEPARATOR . $filename)) {
				return $dir;
			}
			$dir = dirname($dir);
		}

		throw new \Exception('The file ' . $filename . ' was not found in the parent directories of ' . $dir);
	}

	private static function runBashCommand(string $command): array {
		$command = str_replace('\"', '"', $command);
		$patterns = [];
		$replacements = [];
		$fields = [
			'appRootDir' => static::findParentDirContainingFile('appinfo'),
			'nextcloudRootDir' => static::findParentDirContainingFile('console.php'),
		];
		foreach ($fields as $key => $value) {
			$patterns[] = '/<' . $key . '>/';
			$replacements[] = $value;
		}
		$command = preg_replace($patterns, $replacements, $command);
		if (!is_string($command)) {
			throw new \Exception('The command is not a string after preg_replace: ' . print_r($command, true));
		}

		exec($command, $output, $resultCode);
		self::$commandOutput = implode("\n", $output);
		return [
			'command' => $command,
			'output' => $output,
			'resultCode' => $resultCode,
		];
	}

	#[Given('the output of the last command should contain the following text:')]
	public static function theOutputOfTheLastCommandContains(PyStringNode $text): void {
		Assert::assertStringContainsString((string) $text, self::$commandOutput, 'The output of the last command does not contain: ' . $text);
	}

	#[Given('the output of the last command should be empty')]
	public static function theOutputOfTheLastCommandShouldBeEmpty(): void {
		Assert::assertEmpty(self::$commandOutput, 'The output of the last command should be empty, but got: ' . self::$commandOutput);
	}

	#[Given('/^run the command "(?P<command>(?:[^"]|\\")*)" with result code (\d+)$/')]
	public static function runCommandWithResultCode(string $command, int $resultCode = 0): void {
		$return = self::runCommand($command);
		Assert::assertEquals($resultCode, $return['resultCode'], print_r($return, true));
	}

	#[Given('/^run the bash command "(?P<command>(?:[^"]|\\")*)" with result code (\d+)$/')]
	public static function runBashCommandWithResultCode(string $command, int $resultCode = 0): void {
		$return = self::runBashCommand($command);
		Assert::assertEquals($resultCode, $return['resultCode'], print_r($return, true));
	}

	#[Given('create an environment :name with value :value to be used by occ command')]
	public static function createAnEnvironmentWithValueToBeUsedByOccCommand(string $name, string $value):void {
		self::$environments[$name] = $value;
	}

	#[Given('/^wait for ([0-9]+) (second|seconds)$/')]
	public function waitForXSecond(int $seconds): void {
		$this->startWaitFor = $seconds;
		sleep($seconds);
	}

	#[Given('/^past ([0-9]+) (second|seconds) since wait step$/')]
	public function pastXSecondsSinceWaitStep(int $seconds): void {
		$currentTime = time();
		$startTime = $currentTime - $this->startWaitFor;
		Assert::assertGreaterThanOrEqual($startTime, $currentTime, 'The current time is not greater than or equal to the start time.');
	}

	#[AfterScenario()]
	public function tearDown(): void {
		self::$environments = [];
		foreach (self::$createdUsers as $user) {
			$this->deleteUser($user);
		}
	}

	protected function deleteUser(string $user): ResponseInterface {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('DELETE', '/cloud/users/' . $user);
		$this->setCurrentUser($currentUser);

		unset(self::$createdUsers[array_search($user, self::$createdUsers, true)]);

		return $this->response;
	}
}
