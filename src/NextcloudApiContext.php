<?php

namespace Libresign\NextcloudBehat;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeSuite;
use Behat\Step\DataTable;
use Behat\Step\DocString;
use Behat\Step\Given;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Composer\XdebugHandler\XdebugHandler;
use DOMDocument;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use PhpBuiltin\RunServerListener;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Webmozart\Assert\Assert;

/**
 * Defines application features from the specific context.
 */
class NextcloudApiContext implements Context {
	protected string $testPassword = '123456';
	protected string $adminPassword = 'admin';
	protected string $baseUrl = '';
	protected ?RunServerListener $server = null;
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
	protected static bool $shouldRestoreGuestIdHashing = false;
	protected static ?bool $guestIdHashingOriginalValue = null;
	protected ResponseInterface $response;
	/** @var CookieJar[] */
	protected $cookieJars;
	protected array $requestOptions = [];

	public function __construct(?array $parameters = []) {
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
		$whoami = (string)exec('whoami');
		if (get_current_user() !== $whoami) {
			$command = implode(' ', $_SERVER['argv'] ?? []);
			throw new Exception(sprintf(
				"Have files that %s is the owner and the user that is running this test is %s, is necessary to be the same user.\n"
				. "You should run the follow command:\n"
				. "runuser -u %s -- %s\n\n",
				get_current_user(), $whoami, get_current_user(), $command));
		}
	}

	#[BeforeScenario()]
	public static function beforeScenario(): void {
		self::$createdUsers = [];
		self::$environments = [];
		self::$commandOutput = '';
		self::$shouldRestoreGuestIdHashing = false;
		self::$guestIdHashingOriginalValue = null;
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
		self::disableGuestIdHashing();
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

	private static function disableGuestIdHashing(): void {
		if (self::$shouldRestoreGuestIdHashing) {
			return;
		}

		// nextcloud/guests 555cf1bc hashes guest IDs by default, but guest
		// scenarios still use the raw identifier as the login/user reference.
		$currentValue = self::runCommand('config:app:get guests hash_user_ids');
		self::$shouldRestoreGuestIdHashing = true;
		self::$guestIdHashingOriginalValue = $currentValue['resultCode'] === 0
			? trim(implode("\n", $currentValue['output'])) === '1'
			: null;
		self::runCommandWithResultCode('config:app:set guests hash_user_ids --value false --type boolean', 0);
	}

	private static function restoreGuestIdHashing(): void {
		if (!self::$shouldRestoreGuestIdHashing) {
			return;
		}

		$originalValue = self::$guestIdHashingOriginalValue;
		self::$shouldRestoreGuestIdHashing = false;
		self::$guestIdHashingOriginalValue = null;

		if ($originalValue === null) {
			self::runCommandWithResultCode('config:app:delete guests hash_user_ids', 0);
			return;
		}

		self::runCommandWithResultCode(
			'config:app:set guests hash_user_ids --value ' . ($originalValue ? 'true' : 'false') . ' --type boolean',
			0
		);
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
	 * @param DataTable|DocString|array|null $body
	 */
	#[Given('sending :verb to ocs :url')]
	public function sendOCSRequest(string $verb, string $url, DataTable|DocString|array|null $body = null, array $headers = [], array $options = []): void {
		$url = '/ocs/v2.php' . $url;
		$headers['OCS-ApiRequest'] = 'true';
		$this->sendRequest($verb, $url, $body, $headers, $options);
	}

	/**
	 * @param string $verb
	 * @param string $url
	 * @param DataTable|DocString|array|null $body
	 * @param array $headers
	 */
	#[Given('sending :verb to :url')]
	public function sendRequest(string $verb, string $url, DataTable|DocString|array|null $body = null, array $headers = [], array $options = []): void {
		$verb = strtoupper($verb);
		if (!str_starts_with($url, '/')) {
			$url = '/' . $url;
		}
		if (strpos($url, '/ocs/v2.php') === false && !str_starts_with($url, '/remote.php/')) {
			$url = '/index.php' . $url;
		}
		if ($this->baseUrl === '') {
			// Behat 4 may instantiate contexts before the server listener exists.
			$this->server = RunServerListener::getInstance();
			$this->baseUrl = RunServerListener::getServerRoot();
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
		if ($body instanceof DataTable) {
			$fd = $body->asMap();
			$options['form_params'] = $fd;
			$options['_decode_table_json'] = true;
		} elseif ($body instanceof DocString) {
			$options['body'] = $body->getContent();
			$options['headers']['Content-Type'] = 'application/json';
		} elseif (is_array($body)) {
			$options['form_params'] = $body;
		}

		$options['headers'] = array_merge($headers, [
			'Accept' => 'application/json',
		], $this->customHeaders, $options['headers'] ?? []);

		if ($this->currentUser === 'admin') {
			$options['auth'] = ['admin', $this->adminPassword];
		} elseif ($this->currentUser) {
			$options['auth'] = [$this->currentUser, $this->testPassword];
		}

		try {
			[$fullUrl, $options] = $this->beforeRequest($fullUrl, $options);
			$options = $this->normalizePayloadForRequest($verb, $options);
			$this->requestOptions = $options;
			$this->response = $client->request($verb, $fullUrl, $options);
		} catch (ClientException $ex) {
			$this->response = $ex->getResponse();
		} catch (\GuzzleHttp\Exception\ServerException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	/**
	 * Send a request to the Nextcloud WebDAV endpoint using the same authentication,
	 * response handling, and request hooks as the regular HTTP steps.
	 */
	#[Given('user :user sends WebDAV :method to :path')]
	public function sendWebDavRequest(string $user, string $method, string $path, DocString|string|null $body = null, array $headers = []): void {
		$method = strtoupper(trim($method));
		Assert::inArray($method, ['DELETE', 'PUT', 'PROPFIND', 'GET', 'HEAD', 'MOVE', 'COPY', 'MKCOL'], 'Unsupported WebDAV method');

		$this->setCurrentUser($user);
		$path = ltrim($this->parseText($path), '/');
		$segments = explode('/', $path);
		foreach ($segments as $segment) {
			if ($segment === '.' || $segment === '..') {
				throw new \InvalidArgumentException('WebDAV paths cannot contain traversal segments');
			}
		}
		$path = implode('/', array_map('rawurlencode', $segments));
		$url = '/remote.php/dav/files/' . rawurlencode($user) . '/' . $path;
		$options = ['headers' => ['Accept' => 'application/xml'] + $headers];
		if ($body !== null) {
			$options['body'] = $this->parseText($body instanceof DocString ? $body->getContent() : $body);
		}
		$this->sendRequest($method, $url, null, [], $options);
	}

	#[Given('the WebDAV response should contain property :property with value :value')]
	public function theWebDavResponseShouldContainPropertyWithValue(string $property, string $value): void {
		$actual = $this->getWebDavPropertyValue($property);
		$expected = $this->parseText($value);
		Assert::same($actual, $expected, self::safeAssertionMessage('WebDAV property ' . $property . ' expected ' . $expected . ', got ' . $actual));
	}

	#[Given('fetch WebDAV property :property to :alias')]
	public function fetchWebDavPropertyTo(string $property, string $alias): void {
		$this->fields[$alias] = $this->getWebDavPropertyValue($property);
	}

	private function getWebDavPropertyValue(string $property): string {
		if (!preg_match('/^(?:(d|nc|oc):)?([A-Za-z_][A-Za-z0-9_.-]*)$/D', $property, $matches)) {
			throw new \InvalidArgumentException('Invalid WebDAV property name: ' . $property);
		}
		$namespace = ($matches[1] ?? '') !== '' ? $matches[1] : 'nc';
		$name = $matches[2];

		Assert::eq($this->response->getStatusCode(), 207, 'Expected WebDAV HTTP 207 Multi-Status');
		$xml = @simplexml_load_string((string)$this->response->getBody(), \SimpleXMLElement::class, LIBXML_NONET);
		if (!$xml instanceof \SimpleXMLElement) {
			throw new \InvalidArgumentException('Invalid XML in WebDAV response');
		}
		$xml->registerXPathNamespace('d', 'DAV:');
		$xml->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
		$xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');
		$result = $xml->xpath('//' . $namespace . ':' . $name);
		Assert::notEmpty($result, self::safeAssertionMessage('WebDAV property ' . $property . ' not found in response'));
		return (string)$result[0];
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
		Assert::string($encoded);
		$decoded = json_decode($encoded, true);
		Assert::isArray($decoded);

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
		Assert::eq($response->getStatusCode(), $statusCode, self::safeAssertionMessage($message));
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	#[Given('the response should have a status code :code')]
	public function theResponseShouldHaveStatusCode(string $code): void {
		$currentCode = $this->response->getStatusCode();
		Assert::eq($currentCode, $code, self::safeAssertionMessage(sprintf('Expected HTTP status %s, got %d. Response body: %s', $code, $currentCode, (string)$this->response->getBody())));
	}

	#[Given('the response header :header should contain :value')]
	public function theResponseHeaderShouldContain(string $header, string $value): void {
		$actual = strtolower($this->response->getHeaderLine($header));
		Assert::contains($actual, strtolower($value), self::safeAssertionMessage(sprintf('Response header "%s" does not contain "%s"', $header, $value)));
	}

	#[Given('the response body should not be empty')]
	public function theResponseBodyShouldNotBeEmpty(): void {
		$this->response->getBody()->rewind();
		Assert::notSame($this->response->getBody()->getContents(), '', 'Response body is empty');
	}

	#[Given('the response body should match the regular expression :pattern')]
	public function theResponseBodyShouldMatchTheRegularExpression(string $pattern): void {
		$this->response->getBody()->rewind();
		$content = $this->response->getBody()->getContents();
		Assert::regex($content, '#' . $pattern . '#', self::safeAssertionMessage(sprintf('Response body does not match pattern "%s"', $pattern)));
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	#[Given('the response should be a JSON array with the following mandatory values')]
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(DataTable $table): void {
		$this->response->getBody()->seek(0);
		$expectedValues = $table->asMaps();
		$json = $this->response->getBody()->getContents();
		$this->response->getBody()->seek(0);
		$this->jsonStringMatchWith($json, $expectedValues);
	}

	private function jsonStringMatchWith(string $json, array $expectedValues): void {
		$this->decodeJson($json, 'response body');
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
				$this->assertJsonSemanticallyEquals($value['value'], $actual, 'Key: ' . $value['key'] . ' JSON: ' . $json);
				continue;
			}
			Assert::eq($actual, $value['value'], self::safeAssertionMessage('Key: ' . $value['key'] . ' expected ' . $value['value'] . ', actual ' . $actual . '. JSON: ' . $json));
		}
	}

	/**
	 * webmozart/assert treats a custom message as a sprintf format string.
	 * Escape data-driven percent signs so diagnostics never mask assertion failures.
	 */
	private static function safeAssertionMessage(string $message): string {
		return str_replace('%', '%%', $message);
	}

	/**
	 * Compare JSON semantically: object property order does not matter, but array
	 * order and JSON value types remain significant.
	 */
	private function assertJsonSemanticallyEquals(string $expected, string $actual, string $context = ''): void {
		$expectedValue = $this->decodeJson($expected, 'expected JSON');
		$actualValue = $this->decodeJson($actual, 'actual JSON');
		$expectedCanonical = json_encode($this->sortJsonObjectKeys($expectedValue), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
		$actualCanonical = json_encode($this->sortJsonObjectKeys($actualValue), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
		Assert::same($actualCanonical, $expectedCanonical, self::safeAssertionMessage(trim($context . ' Expected JSON: ' . $expected . '; actual JSON: ' . $actual)));
	}

	private function decodeJson(string $json, string $description): mixed {
		try {
			return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \InvalidArgumentException('Invalid ' . $description . ': ' . $e->getMessage() . '. Input: ' . $json, 0, $e);
		}
	}

	private function sortJsonObjectKeys(mixed $value): mixed {
		if ($value instanceof \stdClass) {
			$properties = get_object_vars($value);
			ksort($properties, SORT_STRING);
			$sorted = new \stdClass();
			foreach ($properties as $key => $child) {
				$sorted->{$key} = $this->sortJsonObjectKeys($child);
			}
			return $sorted;
		}
		if (is_array($value)) {
			return array_map($this->sortJsonObjectKeys(...), $value);
		}
		return $value;
	}

	private function testAndGetActualValue(array $value, string $json): string {
		$realResponseArray = json_decode($json, true);
		Assert::isArray($realResponseArray, self::safeAssertionMessage('The response is not a JSON array: ' . $json));
		if (str_starts_with($value['key'], '(jq)')) {
			$actual = $this->evalJsonQuery(
				substr($value['key'], 4),
				$json
			);
			if (!is_string($actual)) {
				$actual = json_encode($actual);
				Assert::string($actual);
			}
			return $actual;
		}
		$responseAsJsonString = json_encode($realResponseArray);
		Assert::string($responseAsJsonString);
		Assert::keyExists(
			$realResponseArray,
			$value['key'],
			self::safeAssertionMessage('Not found: "' . $value['key'] . '" at array: ' . $responseAsJsonString)
		);
		$actual = $realResponseArray[$value['key']];
		if (!is_string($actual)) {
			$actual = json_encode($actual);
			Assert::string($actual);
		}
		return $actual;
	}

	/**
	 * @return mixed
	 */
	private function evalJsonQuery(string $jsonQuery, string $target) {
		return $this->runJsonQuery($target, $jsonQuery);
	}

	private function runJsonQuery(string $json, string $filter): mixed {
		Assert::notEmpty((new ExecutableFinder())->find('jq'), 'Is necessary install the jq command to use jq');
		$process = new Process(['jq', '-M', $filter], null, null, $json);
		$process->mustRun();
		$output = trim($process->getOutput());
		$decoded = json_decode($output, true);
		if (is_string($decoded)) {
			// Preserve the escaped jq string representation used by the legacy mapper.
			return substr($output, 1, -1);
		}
		return json_last_error() === JSON_ERROR_NONE ? $decoded : $output;
	}

	private function validateAsJsonQuery(string $expected, string $actual): void {
		$result = $this->runJsonQuery($actual, $expected);
		Assert::true($result, self::safeAssertionMessage('The jq "' . $expected . '" do not match with: ' . $actual));
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
			Assert::keyExists($value, $key, self::safeAssertionMessage('Key [' . $key . '] of path [' . $path . '] not found at body: ' . $body));
			$value = $value[$key];
		}
		if (isset($alias)) {
			$this->fields[$alias] = $value;
		}
		$this->fields[$path] = $value;
	}

	#[Given('the response should contain the initial state :name with the following values:')]
	public function theResponseShouldContainTheInitialStateWithTheFollowingValues(string $name, DocString $expected): void {
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
			throw new \Exception('Initial state not found: ' . $name);
		}
		$base64 = $element->getAttribute('value');
		$actual = base64_decode($base64);
		$actual = $this->parseText($actual);
		$expected = $this->parseText((string)$expected);
		if ($this->isJson($expected)) {
			$this->assertJsonSemanticallyEquals($expected, $actual, 'Initial state ' . $name);
		} else {
			if (trim($actual, '"') === json_decode($actual) && str_starts_with($actual, '"')) {
				$actual = trim($actual, '"');
				$expected = trim($expected, '"');
			}
			Assert::eq($actual, $expected);
		}
	}

	#[Given('the response should contain the initial state :name json that match with:')]
	public function theResponseShouldContainTheInitialStateJsonThatMatchWith(string $name, DataTable $table): void {
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
			throw new \Exception('Initial state not found: ' . $name);
		}
		$base64 = $element->getAttribute('value');
		$actual = base64_decode($base64);
		$actual = $this->parseText($actual);
		$expectedValues = $table->asMaps();
		$this->jsonStringMatchWith($actual, $expectedValues);
	}

	#[Given('the following :appId app config is set')]
	public function setAppConfig(string $appId, DataTable $formData): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		foreach ($formData->asLists() as $row) {
			$this->sendOCSRequest('POST', '/apps/provisioning_api/api/v1/config/apps/' . $appId . '/' . $row[0], [
				'value' => $row[1],
			]);
		}
		$this->setCurrentUser($currentUser);
	}

	protected function parseFormParams(array $options): array {
		if (!empty($options['form_params'])) {
			$this->parseTextRcursive($options['form_params']);
			if (!empty($options['_decode_table_json'])) {
				$options['form_params'] = $this->decodeIfIsJsonString($options['form_params']);
			}
		}
		unset($options['_decode_table_json']);
		return $options;
	}

	private function parseTextRcursive(array &$array): array {
		array_walk_recursive($array, function (mixed &$value) {
			if (is_string($value)) {
				$value = $this->parseText($value);
			} elseif ($value instanceof \stdClass) {
				$value = (array)$value;
				$buffer = json_encode($this->parseTextRcursive($value));
				Assert::string($buffer);
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
		Assert::string($text);
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
		$baseCommand = 'php ' . $console . ' ' . $command;
		$commandEnvironment = self::$environments;
		$restartSettings = XdebugHandler::getRestartSettings();
		if ($restartSettings !== null) {
			$commandEnvironment['PHP_INI_SCAN_DIR'] = $restartSettings['scanDir'];
			$commandEnvironment['PHPRC'] = $restartSettings['phprc'];
			$commandEnvironment['XDEBUG_MODE'] = 'off';
		}
		$environmentPrefix = self::buildEnvironmentPrefix($commandEnvironment);

		if (posix_getuid() !== $owner['uid']) {
			$fullCommand = 'runuser -u ' . escapeshellarg($owner['name']) . ' -- '
				. ($environmentPrefix !== '' ? 'env ' . $environmentPrefix . ' ' : '')
				. $baseCommand;
		} else {
			$fullCommand = ($environmentPrefix !== '' ? 'env ' . $environmentPrefix . ' ' : '') . $baseCommand;
		}
		$fullCommand .= '  2>&1';
		return self::runBashCommand($fullCommand);
	}

	/**
	 * @param array<string, string|false> $environment
	 */
	private static function buildEnvironmentPrefix(array $environment): string {
		$options = [];
		$assignments = [];
		foreach ($environment as $name => $value) {
			if ($value === false) {
				$options[] = '-u ' . escapeshellarg($name);
				continue;
			}
			$assignments[] = $name . '=' . escapeshellarg($value);
		}
		return implode(' ', [...$options, ...$assignments]);
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
	public static function theOutputOfTheLastCommandContains(DocString $text): void {
		Assert::contains(self::$commandOutput, (string)$text, self::safeAssertionMessage('The output of the last command does not contain: ' . (string)$text));
	}

	#[Given('the output of the last command should be empty')]
	public static function theOutputOfTheLastCommandShouldBeEmpty(): void {
		Assert::same(self::$commandOutput, '', self::safeAssertionMessage('The output of the last command should be empty, but got: ' . self::$commandOutput));
	}

	#[Given('/^run the command "(?P<command>(?:[^"]|\\")*)" with result code (\d+)$/')]
	public static function runCommandWithResultCode(string $command, int $resultCode): void {
		$return = self::runCommand($command);
		Assert::eq($return['resultCode'], $resultCode, self::safeAssertionMessage(print_r($return, true)));
	}

	#[Given('/^run the bash command "(?P<command>(?:[^"]|\\")*)" with result code (\d+)$/')]
	public static function runBashCommandWithResultCode(string $command, int $resultCode): void {
		$return = self::runBashCommand($command);
		Assert::eq($return['resultCode'], $resultCode, self::safeAssertionMessage(print_r($return, true)));
	}

	#[Given('create an environment :name with value :value to be used by occ command')]
	public static function createAnEnvironmentWithValueToBeUsedByOccCommand(string $name, string $value):void {
		self::$environments[$name] = $value;
	}

	#[Given('/^wait for ([0-9]+) seconds?$/')]
	public function waitForXSecond(int $seconds): void {
		$this->startWaitFor = $seconds;
		sleep($seconds);
	}

	#[Given('/^past ([0-9]+) seconds? since wait step$/')]
	public function pastXSecondsSinceWaitStep(int $seconds): void {
		$currentTime = time();
		$startTime = $currentTime - $this->startWaitFor;
		Assert::greaterThanEq($currentTime, $startTime, 'The current time is not greater than or equal to the start time.');
	}

	#[AfterScenario()]
	public function tearDown(): void {
		self::$environments = [];
		foreach (self::$createdUsers as $user) {
			$this->deleteUser($user);
		}
		self::restoreGuestIdHashing();
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
