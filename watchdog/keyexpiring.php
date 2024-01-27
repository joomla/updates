<?php

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Joomla\Http\HttpFactory;
use Tuf\Client\DurableStorage\FileStorage;
use Tuf\Client\SignatureVerifier;
use Tuf\Client\Updater;
use Tuf\Exception\RepoFileNotFound;
use Tuf\Loader\LoaderInterface;
use Tuf\Loader\SizeCheckingLoader;

require_once 'vendor/autoload.php';

$config = [
    'repository' => 'https://raw.githubusercontent.com/joomla/updates',
    'branch' => 'dec23-target',
    'folder' => 'repository',
    'path' => __DIR__ . '/metadata',
];

$apiElasticKey = getenv('ELASTIC_EMAIL_KEY');

$notifier = new class($apiElasticKey)
{
    private readonly ElasticEmail\Configuration $config;

    public function __construct(private readonly string $apiKey)
    {
        $this->config = ElasticEmail\Configuration::getDefaultConfiguration()->setApiKey('X-ElasticEmail-ApiKey', $this->apiKey);
    }

    /**
     * Notify all contacts from the elastic email list
     *
     * @param string $message
     *
     * @return void
     */
    public function notify(string $message): void
    {
        $apiInstance = new ElasticEmail\Api\EmailsApi(
            // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
            // This is optional, `GuzzleHttp\Client` will be used as default.
            new GuzzleHttp\Client(),
            $this->config
        );

        $contacts = $this->getContacts();

        // No contacts found
        if (empty($contacts)) {
            return;
        }

        $email_message_data = new \ElasticEmail\Model\EmailMessageData([
            "recipients" => $contacts,
            "content" => new \ElasticEmail\Model\EmailContent([
                "body" => [
                    new \ElasticEmail\Model\BodyPart([
                        "content_type" => "HTML",
                        "content" => "Hello,<br><br>There is an error in the TUF Watchdog:<br><br>" . $message . "<br><br>Best regards,<br>Joomla TUF Watchdog"
                    ])
                ],
                "from" => "joomla-tuf@opensourcematters.org",
                "subject" => "TUF Watchdog Error",
            ]),
            "options" => new \ElasticEmail\Model\Options([
                "channel_name" => "TUF Watchdog"
            ])
        ]);

        try {
            $response = $apiInstance->emailsPost($email_message_data);
        } catch (Exception $e) {
            echo 'Exception when calling EmailsApi->emailsPost: ', $e->getMessage(), PHP_EOL;
        }
    }

    protected function getContacts()
    {
        $apiInstance = new ElasticEmail\Api\ContactsApi(
            // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
            // This is optional, `GuzzleHttp\Client` will be used as default.
            new GuzzleHttp\Client(),
            $this->config
        );

        $result = [];

        try {
            $contacts = $apiInstance->contactsGet();

            foreach ($contacts as $contact) {
                if (!$contact->getStatus() || $contact->getStatus() !== 'Active') {
                    continue;
                }

                $result[] = new \ElasticEmail\Model\EmailRecipient([
                    'email' => $contact->getEmail()
                ]);
            }

            return $result;
        } catch (Exception $e) {
            echo 'Exception when calling ListsApi->listsByNameContactsGet: ', $e->getMessage(), PHP_EOL;
        }
    }
};

/**
 * Use Joomla http client to load the metadata files
 */
$http = new class($config['repository'] . '/' . $config['branch'] . '/' . $config['folder'] . '/') implements LoaderInterface
{
    public function __construct(private readonly string $repositoryPath)
    {
    }

    public function load(string $locator, int $maxBytes): PromiseInterface
    {
        $httpFactory = new HttpFactory();

        // Get client instance
        $client = $httpFactory->getHttp([], 'curl');
        $response = $client->get($this->repositoryPath . $locator);

        if ($response->code !== 200) {
            throw new RepoFileNotFound();
        }

        $response->getBody()->rewind();

        // Return response
        return Create::promiseFor($response->getBody());
    }
};

$sizeCheckingLoader = new SizeCheckingLoader($http);

$storage = new FileStorage($config['path']);

$storage->delete('timestamp');
$storage->delete('snapshot');
$storage->delete('targets');

$updater = new Updater($sizeCheckingLoader, $storage);

try {
    $updater->refresh(true);
} catch (Exception $e) {

    $notifier->notify($e->getMessage() ?: 'Unknown error: ' . get_class($e));

    exit(1);
}
