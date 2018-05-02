<?php namespace Brightpearl\Laravel;

use Brightpearl\Exception\UnauthorizedException;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class ServiceProvider extends LaravelServiceProvider {

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('brightpearl', function($app) {

          $config = array_filter([
            'dev_reference' => env('BRIGHTPEARL_DEV_REFERENCE', ''),
            'dev_secret'    => env('BRIGHTPEARL_DEV_SECRET', ''),
            'app_reference' => env('BRIGHTPEARL_APP_REFERENCE', ''),
            'account_code'  => env('BRIGHTPEARL_ACCOUNT_CODE', ''),
            'account_token' => env('BRIGHTPEARL_ACCOUNT_TOKEN', ''),
            'api_domain'    => env('BRIGHTPEARL_API_DOMAIN', ''),
            'staff_token'   => env('BRIGHTPEARL_STAFF_TOKEN', ''),
          ]);

          return new \Brightpearl\Client($config);
        });

        $app = $this->app;
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('brightpearl');
    }

}
