<?php

namespace Libresign\NextcloudBehat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
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
class NextcloudApiContext implements Context
{
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

	public function __construct(?array $parameters = [])
	{
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
	public function setUp(): void
	{
		$this->createdUsers = [];
	}

	/**
	 * @Given as user :user
	 * @param string $user
	 */
	public function setCurrentUser(?string $user): void
	{
		$this->currentUser = $user;
	}

	/**
	 * @Given user :user exists
	 * @param string $user
	 */
	public function assureUserExists(string $user): void
	{
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

	private function userExists(string $user): ResponseInterface
	{
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('GET', '/cloud/users/' . $user);
		$this->setCurrentUser($currentUser);
		return $this->response;
	}

	private function createUser(string $user): void
	{
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

	private function setUserDisplayName(string $user): void
	{
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('PUT', '/cloud/users/' . $user, [
			'key' => 'displayname',
			'value' => $user . '-displayname'
		]);
		$this->setCurrentUser($currentUser);
	}

	/**
	 * @param string $verb
	 * @param string $url
	 * @param TableNode|array|null $body
	 */
	public function sendOCSRequest(string $verb, string $url, $body = null, array $headers = []): void
	{
		$url = 'ocs/v2.php' . $url;
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = ['admin', $this->adminPassword];
		} elseif ($this->currentUser) {
			$options['auth'] = [$this->currentUser, $this->testPassword];
		}
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
	public function sendRequest(string $verb, string $url, $body = null, array $headers = [], array $options = []): void
	{
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

		try {
			$url = $this->hydrateUrl($url);
			$url = ltrim($url, '/');
			$fullUrl = $this->baseUrl . $url;
			$this->response = $client->{$verb}($fullUrl, $options);
		} catch (ClientException $ex) {
			$this->response = $ex->getResponse();
		} catch (\GuzzleHttp\Exception\ServerException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	protected function hydrateUrl(string $url): string
	{
		return $url;
	}

	protected function decodeIfIsJsonString(array $list): array
	{
		foreach ($list as $key => $value) {
			$decoded = json_decode($value);
			if (json_last_error() == JSON_ERROR_NONE) {
				$list[$key] = $decoded;
			}
		}
		return $list;
	}

	protected function getUserCookieJar(string $user): CookieJar
	{
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
	protected function assertStatusCode(ResponseInterface $response, int $statusCode, string $message = ''): void
	{
		Assert::assertEquals($statusCode, $response->getStatusCode(), $message);
	}

	/**
	 * @Then the response should have a status code :code
	 * @param string $code
	 * @throws \InvalidArgumentException
	 */
	public function theResponseShouldHaveStatusCode($code): void
	{
		$currentCode = $this->response->getStatusCode();
		Assert::assertEquals($code, $currentCode);
	}

	/**
	 * @Then the response should be a JSON array with the following mandatory values
	 * @param TableNode $table
	 * @throws \InvalidArgumentException
	 */
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(TableNode $table): void
	{
		$this->response->getBody()->seek(0);
		$expectedValues = $table->getColumnsHash();
		$realResponseArray = json_decode($this->response->getBody()->getContents(), true);
		foreach ($expectedValues as $value) {
			$actualJson = json_encode($realResponseArray[$value['key']]);
			Assert::assertJsonStringEqualsJsonString($value['value'], $actualJson);
		}
	}

	/**
	 * @AfterScenario
	 */
	public function tearDown(): void
	{
		foreach ($this->createdUsers as $user) {
			$this->deleteUser($user);
		}
	}

	private function deleteUser(string $user): ResponseInterface
	{
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('DELETE', '/cloud/users/' . $user);
		$this->setCurrentUser($currentUser);

		unset($this->createdUsers[array_search($user, $this->createdUsers, true)]);

		return $this->response;
	}
}
