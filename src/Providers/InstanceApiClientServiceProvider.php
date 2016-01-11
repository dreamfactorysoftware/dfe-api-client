<?php namespace DreamFactory\Enterprise\Instance\Ops\Providers;

use DreamFactory\Enterprise\Common\Providers\BaseServiceProvider;
use DreamFactory\Enterprise\Instance\Ops\Services\InstanceApiClientService;

/**
 * InstanceApiClientServiceProvider
 *
 * @package DreamFactory\Enterprise\Instance\Ops\Providers
 */
class InstanceApiClientServiceProvider extends BaseServiceProvider
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /** @inheritdoc */
    const IOC_NAME = 'dfe.instance-api-client';

    //********************************************************************************
    //* Methods
    //********************************************************************************

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->singleton(static::IOC_NAME,
            function($app) {
                return new InstanceApiClientService($app);
            });
    }
}
