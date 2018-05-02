<?php namespace Brightpearl;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use GuzzleHttp\Client as BaseClient;
use GuzzleHttp\Command\Guzzle\GuzzleClient;

class Client
{
    /**
     * Brightpearl API Version this client currently uses.
     *
     * @var string
     */
    const API_VERSION = '2.0.0';

    /**
     * Guzzle service description
     *
     * @var \Brightpearl\Description
     */
    private static $description;

    /**
     * Guzzle base client
     *
     * @var \GuzzleHttp\Client
     */
    private $baseClient;

    /**
     * Adapter for Guzzle base client
     *
     * @var \GuzzleHttp\Adapter\AdapterInterface
     */
    private $baseClientAdapter;

    /**
     * Api client services
     *
     * @var \GuzzleHttp\Command\Guzzle\GuzzleClient
     */
    private $serviceClient;

    /**
     * Staff auth credentials to acquire staff token (user email and password)
     *
     * @var array
     */
    private $installCredentials;

    /**
     * Brightpearl client config settings
     *
     * @var array
     */
    private $settings;

    /**
     * Request header items
     *
     * @var array
     */
    private $globalParams = [
            "apiVersion" => [
                "type" => "string",
                "location" => "uri",
                "required" => true,
            ],
            "account_code" => [
                "type" => "string",
                "location" => "uri",
                "required" => true,
            ],
            "dev_reference" => [
                "type" => "string",
                "location" => "header",
                "required" => false,
                "sentAs" => "brightpearl-dev-ref",
            ],
            "app_reference" => [
                "type" => "string",
                "location" => "header",
                "required" => false,
                "sentAs" => "brightpearl-app-ref",
            ],
            "account_token" => [
                "type" => "string",
                "location" => "header",
                "required" => false,
                "sentAs" => "brightpearl-account-token",
            ],
            "staff_token" => [
                "type" => "string",
                "location" => "header",
                "required" => false,
                "sentAs" => "brightpearl-staff-token",
            ],
        ];

    /**
     * Create a new GuzzleClient Service, ability to use the client
     * without setting properties on instantiation.
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct(array $settings = array())
    {
        $this->settings = $settings;
    }

    /**
     * Merge additional settings with existing and save. Overrides
     * existing settings as well.
     *
     * @param  array  $settings
     * @return static
     */
    public function settings(array $settings)
    {
        $this->settings = array_merge($this->settings, $settings);

        if ($this->serviceClient) $this->buildClient();

        return $this;
    }

    /**
     * Build new service client from descriptions.
     *
     * @return void
     */
    private function buildClient()
    {
        $client = $this->getBaseClient();

        // If no api domain is set use master datacenter
        if (!isset($this->settings['api_domain'])) {
            $this->settings['api_domain'] = 'ws-eu1.brightpearl.com';
        }

        if (!static::$description) {
            $this->reloadDescription();
        }

        // sync data center code across client and description
        else $this->setApiDomain($this->settings['api_domain']);

        $this->serviceClient = new GuzzleClient(
                $client,
                static::$description,
                ['emitter' => $this->baseClient->getEmitter()]
            );
    }

    /**
     * Retrieve Guzzle base client.
     *
     * @return \GuzzleHttp\Client
     */
    private function getBaseClient()
    {
        return $this->baseClient ?: $this->baseClient = $this->loadBaseClient();
    }

    /**
     * Set adapter and create Guzzle base client.
     *
     * @return \GuzzleHttp\Client
     */
    private function loadBaseClient(array $settings = [])
    {
        if ($this->baseClientAdapter)
            $settings['adapter'] = $this->baseClientAdapter;

        return $this->baseClient = new BaseClient($settings);
    }

    /**
     * Description works tricky as a static
     * property, reload as a needed.
     *
     * @return void
     */
    private function reloadDescription()
    {
        static::$description = new Description($this->loadConfig());
    }

    /**
     * Load configuration file and parse resources.
     *
     * @return array
     */
    private function loadConfig()
    {
        $description = $this->loadResource('service-config');

        // initial description building, use api info and build base url
        $description = $description + [
                'baseUrl' => 'https://'.$this->settings['api_domain'],
                'operations' => [],
                'models' => []
            ];

        // process each of the service description resources defined
        foreach ($description['services'] as $serviceName) {

            $service = $this->loadResource($serviceName);

            $description = $this->loadServiceDescription($service, $description);

        }

        // dead weight now, clean it up
        unset($description['services']);

        return $description;
    }

    /**
     * Load service description from resource, add global
     * parameters to operations. Operations and models
     * added to full description.
     *
     * @param  array $service
     * @param  array $description
     * @return array
     */
    private function loadServiceDescription(array $service, array $description)
    {
        foreach ($service as $section => $set) {

            if ($section == 'operations') {

                // add global parameters to the operation parameters
                foreach ($set as &$op)
                    $op['parameters'] = isset($op['parameters'])
                                    ? $op['parameters'] + $this->globalParams
                                    : $this->globalParams;
            }

            $description[$section] = $description[$section] + $set;
        }

        return $description;
    }

    /**
     * Load resource configuration file and return array.
     *
     * @param  string  $name
     * @return array
     */
    private function loadResource($name)
    {
        return require __DIR__.'/resources/'.$name.'.php';
    }

    /**
     * Set api domain.
     *
     * @param  string $apiDomain
     * @return void
     */
    public function setApiDomain($apiDomain)
    {
        $this->settings['api_domain'] = $apiDomain;

        if (static::$description) $this->reloadDescription();
    }

    /**
     * Public application install callback handler
     *
     * @param  array  $query
     * @param  string $signature
     * @return array
     */
    public function installCallback(array $query, $signature = null)
    {
        extract($query);

        $this->validateRequest(compact('accountCode', 'timestamp', 'token'), $signature);

        $timestamp = $this->callbackDatetime($timestamp);

        return ['account_code' => $accountCode, 'account_token' => $token] + compact('timestamp');
    }

    /**
     * Public application regular callback handler
     *
     * @param  array  $query
     * @param  string $signature
     * @return array
     */
    public function simpleCallback(array $query, $signature = null)
    {
        extract($query);

        $this->validateRequest(compact('accountCode', 'timestamp'), $signature);

        $timestamp = $this->callbackDatetime($timestamp);

        return ['account_code' => $accountCode, 'timestamp' => $timestamp];
    }

    /**
     * Brightpearl signature validator
     *
     * @param  array  $query
     * @param  string $signature
     * @return void
     */
    private function validateRequest(array $query, $signature)
    {
        $string = $this->settings['dev_secret'];

        ksort($query);

        foreach ($query as $key => $val)
            $string = $string.$key.'='.$val;

        if ($signature !== hash('sha256', $string))
            throw new Exception\UnauthorizedException('Error signature "'.$signature.'"does not match!');
    }

    /**
     * Process timestamps for callbacks, for some
     * reason they are returned in milliseconds so
     * we are reducing times to seconds first.
     *
     * @param  string|int $timestamp
     * @return \DateTime
     */
    private function callbackDatetime($timestamp)
    {
        $epoch = floor(((int)$timestamp)/1000);

        return $this->getDatetimeTimestamp($epoch);
    }

    /**
     * Process timestamps to DateTime or Carbon if
     * available. (Carbon extends DateTime php class)
     *
     * @param  string|int $timestamp
     * @return \DateTime
     */
    private function getDatetimeTimestamp($timestamp)
    {
        if (class_exists('Carbon\Carbon'))
            return new \Carbon\Carbon("@$timestamp");

        return new \DateTime("@$timestamp");
    }

    /**
     * Set custom guzzle adapter (Mock and others)
     *
     * @param AdapterInterface $adapter
     * @return static
     * @deprecated since Guzzle 5, use handlers and subscribers
     */
    public function setClientAdapter(\GuzzleHttp\Adapter\AdapterInterface $adapter)
    {
        $this->baseClientAdapter = $adapter;

        return $this;
    }

    /**
     * Set custom guzzle subscriber (ie. History, mock, etc)
     *
     * @param SubscriberInterface $subscriber
     * @return static
     */
    public function setClientSubscriber(\GuzzleHttp\Event\SubscriberInterface $subscriber)
    {
        if ( ! $this->serviceClient) $this->getBaseClient()->getEmitter()->attach($subscriber);

        else $this->serviceClient->getEmitter()->attach($subscriber);

        return $this;
    }

    /**
     * Sign account token with Developer Secret.
     * (Only used for public system applications,
     * ie Brightpearl app store apps.)
     *
     * @param  array $settings
     * @return void
     */
    private function signAccountToken(array &$settings)
    {
        if (isset($settings['dev_secret']) && isset($settings['account_token']))

        $settings['account_token'] = $this->signToken($settings['account_token'], $settings['dev_secret']);
    }

    /**
     * Sign developer token with Developer Secret.
     *
     * @param  array $settings
     * @return void
     */
    private function signDevToken(array &$settings)
    {
        if (isset($settings['dev_secret']) && isset($settings['dev_token']))

        $settings['dev_token'] = $this->signToken($settings['dev_token'], $settings['dev_secret']);
    }

    /**
     * Sign a token with Developer Secret.
     *
     * @param  string $token
     * @param  string $secret
     * @return string
     */
    private function signToken($token, $secret)
    {
        $string = hash_hmac("sha256", $token, $secret, TRUE);

        return base64_encode($string);
    }

    /**
     * Handle dynamic method calls into the method.
     * Build client on first api call and compile
     * settings and parameters.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        // In cases of Facade using Client->__call() make
        // sure methods are handled by Client if they exist
        if (method_exists($this, $method))
            call_user_func_array([$this, $method], $parameters);

        // build the client on the first call
        if (!$this->serviceClient) $this->buildClient();

        // gather parameters to pass to service definitions
        $settings = ['apiVersion' => self::API_VERSION] +
                    $this->settings;

        // Sign tokens if they are signable
        $this->signAccountToken($settings);
        $this->signDevToken($settings);

        // merge client settings/parameters and method parameters
        $parameters[0] = isset($parameters[0])
                             ? $parameters[0] + $settings
                             : $settings;

        // pass off to Guzzle-services client
        $response = call_user_func_array([$this->serviceClient, $method], $parameters);

        return isset($response['response']) && !isset($response['reference']) ? $response['response'] : $response;
    }
}
