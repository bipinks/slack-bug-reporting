<?php

namespace BipinKareparambil\SlackBugReporting;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class SlackBugReportingService
{
    private ClientInterface $client;
    private ?string $ipGeoLocationApiKey;

    public function __construct(?ClientInterface $client = null, ?string $ipGeoLocationApiKey = null)
    {
        $this->client = $client ?: new Client();
        $this->ipGeoLocationApiKey = $ipGeoLocationApiKey ?: getenv('IP_GEOLOCATION_API_KEY') ?: null;
    }

    /**
     * Get Ip address and details of bug reporter
     * http://checkip.dyndns.org/ is used because some isp never expose their public ip,
     * instead they masked with a private ip which is incompatible for our use.
     */
    private function getIPData(): ?array
    {
        $ip = null;

        if (!$this->ipGeoLocationApiKey) {
            // Log or handle missing API key scenario
            return null;
        }

        try {
            $response = $this->client->get('http://checkip.dyndns.org/');
            $ipBody = (string) $response->getBody();
            if (preg_match('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $ipBody, $matches)) {
                $ip = $matches[0];
            } else {
                // Log or handle IP not found in response
                return null;
            }
        } catch (GuzzleException $e) {
            // Log or handle Guzzle exception
            return null;
        } catch (Exception $e) {
            // Log or handle other exceptions
            return null;
        }

        if (!$ip) {
            return null;
        }

        try {
            $url = "https://api.ipgeolocation.io/ipgeo?apiKey={$this->ipGeoLocationApiKey}&ip=".$ip;
            $response = $this->client->get($url);
            return json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            // Log or handle Guzzle exception
            return null;
        } catch (Exception $e) {
            // Log or handle other exceptions
            return null;
        }
    }

    /**
     * Prepare message content for Slack.
     */
    private function prepareContent(string $message): string
    {
        $messageObj = json_decode($message);
        // Simplified user fetching, removing Laravel's optional() and Auth
        $user = 'SYSTEM'; // Default to SYSTEM, can be enhanced later if needed
        $msg = '*Reported By*: '.$user;

        $ipInfo = $this->getIPData();
        if ($ipInfo !== null && isset($ipInfo['ip'], $ipInfo['country_name'], $ipInfo['city'])) {
            $ip = $ipInfo['ip'];
            $country = $ipInfo['country_name'];
            $city = $ipInfo['city'];
            $msg .= "\n*IP Address*: `".$ip." [$country - $city]`";
        } else {
            $msg .= "\n*IP Address*: `Unable to fetch IP information`";
        }

        $fields = [
            ['request_url', 'API URL'],
            ['request_method', 'Request Method'],
            ['frontend_url', 'Frontend URL'],
            ['message', 'Message'],
            ['query', 'Query'],
            ['execution_time', 'Execution Time'],
            ['exception', 'Exception'],
            ['class', 'Class'],
            ['file', 'File'],
            ['line', 'Line'],
            ['code', 'Code'],
        ];
        foreach ($fields as $field) {
            if (isset($messageObj->{$field[0]})) {
                $msg .= "\n*$field[1]*: ";
                if ($field[0] === 'file' || $field[0] === 'line') {
                    $msg .= '`'.$messageObj->{$field[0]}.'`';
                } else {
                    $msg .= $messageObj->{$field[0]};
                }
            }
        }
        $msg .= "\n...................................................................................................";

        return $msg;
    }

    /**
     * Send Slack message.
     *
     * @throws GuzzleException
     * @throws Exception
     */
    public function send(string $message): ResponseInterface
    {
        $payload = [
            'text' => $this->prepareContent($message),
        ];
        $webHookURL = getenv('SLACK_BUG_REPORTING_WEBHOOK');

        if(!$webHookURL){
            throw new Exception("SLACK_BUG_REPORTING_WEBHOOK not defined");
        }

        return $this->client->post($webHookURL, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);
    }
}
