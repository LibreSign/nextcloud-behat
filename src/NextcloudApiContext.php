<?php

namespace Libresign\NextcloudBehat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use DOMDocument;
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
	protected ?string $currentUser = null;
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

	/**
	 * @BeforeScenario
	 */
	public function setUp(): void {
		$this->createdUsers = [];
	}

	/**
	 * @When as user :user
	 * @param string $user
	 */
	public function setCurrentUser(?string $user): void {
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

	protected function setUserDisplayName(string $user): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('PUT', '/cloud/users/' . $user, [
			'key' => 'displayname',
			'value' => $user . '-displayname'
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
		if ($this->currentUser) {
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
			$this->requestOptions = $options;
			list($fullUrl, $options) = $this->beforeRequest($fullUrl, $options);
			$this->response = $client->{$verb}($fullUrl, $options);
		} catch (ClientException $ex) {
			$this->response = $ex->getResponse();
		} catch (\GuzzleHttp\Exception\ServerException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	protected function beforeRequest(string $fullUrl, array $options): array {
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
		Assert::assertEquals($code, $currentCode);
	}

	/**
	 * @When the response should be a JSON array with the following mandatory values
	 * @param TableNode $table
	 * @throws \InvalidArgumentException
	 */
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(TableNode $table): void {
		$this->response->getBody()->seek(0);
		$expectedValues = $table->getColumnsHash();
		$realResponseArray = json_decode($this->response->getBody()->getContents(), true);
		foreach ($expectedValues as $value) {
			if ($this->isJson($realResponseArray[$value['key']]) || is_bool($realResponseArray[$value['key']])) {
				$actualJson = json_encode($realResponseArray[$value['key']]);
				Assert::assertJsonStringEqualsJsonString($value['value'], $actualJson, 'Key: ' . $value['key']);
				continue;
			}
			$actual = $realResponseArray[$value['key']];
			Assert::assertEquals($value['value'], $actual, 'Key: ' . $value['key']);
		}
	}

	/**
	 * @When the response should contain the initial state :name with the following values:
	 */
	public function theResponseShouldContainTheInitialStateWithTheFollowingValues(string $name, PyStringNode $expected): void {
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
		$actual = $this->parseText((string) $actual);
		$expected = $this->parseText((string) $expected);
		if ($this->isJson($expected)) {
			Assert::assertJsonStringEqualsJsonString($expected, $actual);
		} else {
			Assert::assertEquals($expected, $actual);
		}
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

	protected function parseText(string $text): string {
		return $text;
	}

	/**
	 * @AfterScenario
	 */
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
