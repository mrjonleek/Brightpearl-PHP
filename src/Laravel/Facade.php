<?php namespace Brightpearl\Laravel;

use Illuminate\Support\Facades\Facade as LaravelFacade;

/**
 * @see \Brightpearl\Client
 */
class Facade extends LaravelFacade {

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor() { return 'brightpearl'; }

}
