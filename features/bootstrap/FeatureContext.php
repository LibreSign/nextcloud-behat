<?php

use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Behat\Tester\Result\ExecutedStepResult;
use donatj\MockWebServer\MockWebServer;
use donatj\MockWebServer\RequestInfo;
use Libresign\NextcloudBehat\NextcloudApiContext;
use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\Container;

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
     * @BeforeStep
     */
    public function beforeSteps(BeforeStepScope $scope): void
    {
        $step = $scope->getStep()->getText();
        $methodName = 'testBefore' . $this->getMethodNameFromStep($step);
        if (!method_exists($this, $methodName)) {
            return;
        }
        $this->$methodName();
    }

    /**
     * @AfterStep
     */
    public function afterSteps(AfterStepScope $scope): void
    {
        $testResult = $scope->getTestResult();
        if (!$testResult instanceof ExecutedStepResult) {
            return;
        }
        $step = $testResult->getSearchResult()->getMatchedText() ?? '';
        $methodName = 'testAfter' . $this->getMethodNameFromStep($step);
        $arguments = $testResult->getSearchResult()->getMatchedArguments() ?? [];
        if (!method_exists($this, $methodName)) {
            $message  = <<<MESSAGE
            Implement the follow snippet to test the step "%s":

            public function %s(%s): void
            {
                throw new PendingException();
            }
            MESSAGE;
            $stringArgs = [];
            foreach ($arguments as $argumentName => $value) {
                $stringArgs[] = gettype($value) . ' $' . $argumentName;
            }
            $stringArgs = implode(', ', $stringArgs);
            throw new Exception(sprintf($message, $step, $methodName, $stringArgs));
        }
        $this->$methodName(...array_values($arguments));
    }

    private function getMethodNameFromStep(string $step): string {
        $methodName = Container::camelize($step);
        $methodName = preg_replace('/[^a-zA-Z0-9_\x7f-\xff]/', '', $methodName);
        return $methodName;
    }

    public function testAfterAsUserTest(?string $user): void
    {
        Assert::assertEquals($this->currentUser, $user);
    }

    public function testAfterSendingPOSTTo(): void
    {
        $lastRequest = $this->mockServer->getLastRequest();
        if (!$lastRequest instanceof RequestInfo) {
            throw new Exception('Invalid response');
        }
        Assert::assertEquals('POST', $lastRequest->getRequestMethod());
    }


    public function testAfterUserTestExists(string $user): void {
        $lastRequest = $this->mockServer->getLastRequest();
        if (!$lastRequest instanceof RequestInfo) {
            throw new Exception('Invalid response');
        }
        $headers = $lastRequest->getHeaders();
        Assert::assertEquals('/ocs/v2.php/cloud/users/test', $lastRequest->getRequestUri());
        Assert::assertArrayHasKey('OCS-ApiRequest', $headers);
        Assert::assertEquals('true', $headers['OCS-ApiRequest']);
        Assert::assertArrayHasKey('Authorization', $headers);
        Assert::assertArrayHasKey('Accept', $headers);
        Assert::assertEquals('application/json', $headers['Accept']);
    }
}
