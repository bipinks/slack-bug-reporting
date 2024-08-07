# Slack Bug Reporting Package

This Laravel package allows you to report bugs to Slack for easy tracking and collaboration.

## Installation

To install the package, follow these steps:

1. Require the package using Composer:

   ```shell
   composer require bipinkareparambil/slack-bug-reporting

2. Set up the environment variable:

    In your .env file, add the following variable and set its value to the webhook URL of your Slack app

   ```shell
   SLACK_BUG_REPORTING_WEBHOOK="https://hooks.slack.com/services/{remaining_url_part}"

You're ready to use the Slack Bug Reporting package in your Laravel application!

# Usage

To report bugs to Slack, you can use the SlackBugReportingService provided by the package. Here's an example of how to use it:

```php
use BipinKareparambil\SlackBugReporting\SlackBugReportingService;

// Create an instance of the SlackBugReportingService
$slackBugReporting = new SlackBugReportingService();

// Send a bug report message
$message = "Bug report message...";
$response = $slackBugReporting->send($message);
