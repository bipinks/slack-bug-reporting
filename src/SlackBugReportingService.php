<?php

namespace BipinKareparambil\SlackBugReporting;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Auth;
use Psr\Http\Message\ResponseInterface;

class SlackBugReportingService
{
    private string $ipGeoLocationApiKey = '1cdc2c1ee617455084e3e85a1884abf2';

    private string $webHookUrl = 'https://hooks.slack.com/services/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';

    /**
     * Get Ip address and details of bug reporter
     * http://checkip.dyndns.org/ is used because some isp never expose their public ip,
     * instead they masked with a private ip which is incompatible for our use.
     */
    private function getIPData(): mixed
    {
        $ip = file_get_contents('http://checkip.dyndns.org/');
        preg_match('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $ip, $matches);
        $ip = $matches[0];
        $url = "https://api.ipgeolocation.io/ipgeo?apiKey=$this->ipGeoLocationApiKey&ip=".$ip;

        return json_decode(file_get_contents($url), true);
    }

    /**
     * Prepare message content for Slack.
     */
    private function prepareContent(string $message): string
    {
        $messageObj = json_decode($message);
        $user = optional(Auth::user())->username;
        if (empty($user)) {
            $user = 'SYSTEM';
        }
        $msg = '*Reported By*: '.$user;

        $ipInfo = $this->getIPData();
        $ip = $ipInfo['ip'];
        $country = $ipInfo['country_name'];
        $city = $ipInfo['city'];

        $msg .= "\n*IP Address*: `".$ip." [$country - $city]`";

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
     */
    public function send(string $message): ResponseInterface
    {
        $client = new Client();
        $payload = [
            'text' => $this->prepareContent($message),
        ];

        return $client->post($this->webHookUrl, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);
    }
}
