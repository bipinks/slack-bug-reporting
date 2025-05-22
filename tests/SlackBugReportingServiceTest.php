<?php

namespace BipinKareparambil\SlackBugReporting\Tests;

use BipinKareparambil\SlackBugReporting\SlackBugReportingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use GuzzleHttp\ClientInterface;

class SlackBugReportingServiceTest extends TestCase
{
    private MockHandler $mockHandler;
    private ClientInterface $mockGuzzleClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $this->mockGuzzleClient = new Client(['handler' => $handlerStack]);

        // It's important to clear these before each test if they are set globally
        // or ensure each test sets what it needs.
        putenv('IP_GEOLOCATION_API_KEY');
        putenv('SLACK_BUG_REPORTING_WEBHOOK');
    }

    private function getPrivateMethodInvoker(string $methodName): \ReflectionMethod
    {
        $class = new ReflectionClass(SlackBugReportingService::class);
        $method = $class->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    protected function tearDown(): void
    {
        // Clear any environment variables set during tests
        putenv('IP_GEOLOCATION_API_KEY');
        putenv('SLACK_BUG_REPORTING_WEBHOOK');
        parent::tearDown();
    }

    // Tests for getIPData()
    public function testGetIPDataSuccess()
    {
        $this->mockHandler->append(
            new Response(200, [], 'Current IP Address: 123.123.123.123'),
            new Response(200, [], json_encode(['ip' => '123.123.123.123', 'country_name' => 'Test Country', 'city' => 'Test City']))
        );
        
        putenv('IP_GEOLOCATION_API_KEY=test_api_key_from_env');
        // Service instantiated with the mock client and specific API key for this test
        $service = new SlackBugReportingService($this->mockGuzzleClient, 'test_api_key_from_env'); 
        
        $getIPDataMethod = $this->getPrivateMethodInvoker('getIPData');
        $result = $getIPDataMethod->invoke($service);

        $this->assertNotNull($result);
        $this->assertEquals('123.123.123.123', $result['ip']);
        $this->assertEquals('Test Country', $result['country_name']);
        $this->assertEquals('Test City', $result['city']);
    }

    public function testGetIPDataCheckIPFailure()
    {
        $this->mockHandler->append(
            new RequestException("Error Communicating with Server", new Request('GET', 'http://checkip.dyndns.org/'))
        );
        
        // API key passed directly to constructor for this test case
        $service = new SlackBugReportingService($this->mockGuzzleClient, 'test_api_key'); 
        
        $getIPDataMethod = $this->getPrivateMethodInvoker('getIPData');
        $result = $getIPDataMethod->invoke($service);
        $this->assertNull($result);
    }

    public function testGetIPDataRegexMismatch()
    {
        $this->mockHandler->append(
            new Response(200, [], 'Could not find IP address')
        );
        $service = new SlackBugReportingService($this->mockGuzzleClient, 'test_api_key');
        $getIPDataMethod = $this->getPrivateMethodInvoker('getIPData');
        $result = $getIPDataMethod->invoke($service);
        $this->assertNull($result);
    }

    public function testGetIPDataGeolocationFailure()
    {
        $this->mockHandler->append(
            new Response(200, [], 'Current IP Address: 123.123.123.123'),
            new RequestException("Error Communicating with Server", new Request('GET', 'https://api.ipgeolocation.io/'))
        );
        $service = new SlackBugReportingService($this->mockGuzzleClient, 'test_api_key');
        $getIPDataMethod = $this->getPrivateMethodInvoker('getIPData');
        $result = $getIPDataMethod->invoke($service);
        $this->assertNull($result);
    }

    public function testGetIPDataMissingApiKey()
    {
        // API key is null because env var is not set and null is passed to constructor
        $service = new SlackBugReportingService($this->mockGuzzleClient, null); 
        $getIPDataMethod = $this->getPrivateMethodInvoker('getIPData');
        $result = $getIPDataMethod->invoke($service);
        $this->assertNull($result);
    }

    // Tests for prepareContent()
    public function testPrepareContentCompleteMessage()
    {
        // Configure mock Guzzle client for getIPData's calls
        $this->mockHandler->append(
            new Response(200, [], 'Current IP Address: 1.1.1.1'), // For checkip.dyndns.org
            new Response(200, [], json_encode(['ip' => '1.1.1.1', 'country_name' => 'Testland', 'city' => 'Testville'])) // For ipgeolocation.io
        );

        // Instantiate the real service with the mock Guzzle client and a test API key
        $service = new SlackBugReportingService($this->mockGuzzleClient, 'test_api_key_content');

        $messageData = [
            'request_url' => 'http://localhost/api/test',
            'request_method' => 'POST',
            'frontend_url' => 'http://localhost/test',
            'message' => 'Test error message',
            'query' => 'SELECT * FROM tests',
            'execution_time' => '100ms',
            'exception' => 'TestException',
            'class' => 'TestClass',
            'file' => '/path/to/TestClass.php',
            'line' => 50,
            'code' => 500,
        ];
        $messageJson = json_encode($messageData);

        $prepareContentMethod = $this->getPrivateMethodInvoker('prepareContent');
        $result = $prepareContentMethod->invokeArgs($service, [$messageJson]);

        $this->assertStringContainsString('*Reported By*: SYSTEM', $result);
        $this->assertStringContainsString('*IP Address*: `1.1.1.1 [Testland - Testville]`', $result);
        $this->assertStringContainsString('*API URL*: http://localhost/api/test', $result);
        $this->assertStringContainsString('*Request Method*: POST', $result);
        $this->assertStringContainsString('*Frontend URL*: http://localhost/test', $result);
        $this->assertStringContainsString('*Message*: Test error message', $result);
        $this->assertStringContainsString('*Query*: SELECT * FROM tests', $result);
        $this->assertStringContainsString('*Execution Time*: 100ms', $result);
        $this->assertStringContainsString('*Exception*: TestException', $result);
        $this->assertStringContainsString('*Class*: TestClass', $result);
        $this->assertStringContainsString('*File*: `/path/to/TestClass.php`', $result);
        $this->assertStringContainsString('*Line*: `50`', $result);
        $this->assertStringContainsString('*Code*: 500', $result);
    }

    public function testPrepareContentMissingFields()
    {
        // Configure mock Guzzle client for getIPData's calls
        $this->mockHandler->append(
            new Response(200, [], 'Current IP Address: 2.2.2.2'), // For checkip.dyndns.org
            new Response(200, [], json_encode(['ip' => '2.2.2.2', 'country_name' => 'Noland', 'city' => 'Nocity'])) // For ipgeolocation.io
        );
        $service = new SlackBugReportingService($this->mockGuzzleClient, 'test_api_key_content_missing');

        $messageData = [
            'request_url' => 'http://localhost/api/minimal',
            'message' => 'Minimal error',
        ];
        $messageJson = json_encode($messageData);

        $prepareContentMethod = $this->getPrivateMethodInvoker('prepareContent');
        $result = $prepareContentMethod->invokeArgs($service, [$messageJson]);

        $this->assertStringContainsString('*Reported By*: SYSTEM', $result);
        $this->assertStringContainsString('*IP Address*: `2.2.2.2 [Noland - Nocity]`', $result);
        $this->assertStringContainsString('*API URL*: http://localhost/api/minimal', $result);
        $this->assertStringContainsString('*Message*: Minimal error', $result);
        $this->assertStringNotContainsString('*Request Method*:', $result);
        $this->assertStringNotContainsString('*Frontend URL*:', $result);
    }

    public function testPrepareContentGetIPDataReturnsNull()
    {
        // Configure mock Guzzle client for getIPData to fail (e.g., by returning an error for the first call)
        $this->mockHandler->append(
            new RequestException("Error fetching IP", new Request('GET', 'http://checkip.dyndns.org/'))
        );
        // Or, to simulate missing API key, instantiate service with null API key
        $service = new SlackBugReportingService($this->mockGuzzleClient, null);


        $messageData = ['message' => 'Error with no IP'];
        $messageJson = json_encode($messageData);

        $prepareContentMethod = $this->getPrivateMethodInvoker('prepareContent');
        $result = $prepareContentMethod->invokeArgs($service, [$messageJson]);

        $this->assertStringContainsString('*Reported By*: SYSTEM', $result);
        $this->assertStringContainsString('*IP Address*: `Unable to fetch IP information`', $result);
        $this->assertStringContainsString('*Message*: Error with no IP', $result);
    }

    // Tests for send()
    public function testSendSuccess()
    {
        // 1. Mock Guzzle for getIPData (called by prepareContent)
        $this->mockHandler->append(
            new Response(200, [], 'Current IP Address: 1.2.3.4'), // For checkip.dyndns.org
            new Response(200, [], json_encode(['ip' => '1.2.3.4', 'country_name' => 'Sendland', 'city' => 'Sendville'])) // For ipgeolocation.io
        );
        // 2. Mock Guzzle for the send method's POST call
        $this->mockHandler->append(new Response(200, [], 'ok_send_success'));

        $expectedWebhookUrl = 'https://hooks.slack.com/services/TEST/WEBHOOK_SEND_SUCCESS';
        putenv('SLACK_BUG_REPORTING_WEBHOOK=' . $expectedWebhookUrl);
        putenv('IP_GEOLOCATION_API_KEY=test_api_key_send');


        // Instantiate the real service with the mock Guzzle client
        // The API key for getIPData will be read from putenv
        $service = new SlackBugReportingService($this->mockGuzzleClient);


        $messageToSend = ['message' => 'Test message for send success'];
        $response = $service->send(json_encode($messageToSend));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('ok_send_success', (string) $response->getBody());
        
        // Assert details of the POST request made by send()
        $sentRequest = $this->mockHandler->getLastRequest(); // This will be the POST request
        $this->assertNotNull($sentRequest);
        $this->assertEquals('POST', $sentRequest->getMethod());
        $this->assertEquals($expectedWebhookUrl, (string) $sentRequest->getUri());
        
        $sentPayload = json_decode((string)$sentRequest->getBody(), true);
        $this->assertArrayHasKey('text', $sentPayload);
        $this->assertStringContainsString('*Reported By*: SYSTEM', $sentPayload['text']);
        $this->assertStringContainsString('*IP Address*: `1.2.3.4 [Sendland - Sendville]`', $sentPayload['text']);
        $this->assertStringContainsString('*Message*: Test message for send success', $sentPayload['text']);
    }

    public function testSendThrowsExceptionWhenWebhookNotSet()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SLACK_BUG_REPORTING_WEBHOOK not defined');

        // Ensure SLACK_BUG_REPORTING_WEBHOOK is not set (cleared in setUp/tearDown)
        // Pass the mock Guzzle client, though it won't be used in this path
        $service = new SlackBugReportingService($this->mockGuzzleClient, 'dummy_api_key');
        $service->send(json_encode(['message' => 'Test send webhook missing']));
    }
}
