<?php namespace DreamFactory\Enterprise\Client\Instance\Providers;

use DreamFactory\Enterprise\Client\Instance\Services\InstanceApiClientService;
use DreamFactory\Enterprise\Common\Providers\BaseServiceProvider;

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
        $this->singleton(
            static::IOC_NAME,
            function ($app){
                return new InstanceApiClientService($app);
            }
        );
    }
}
