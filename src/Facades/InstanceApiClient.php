<?php namespace DreamFactory\Enterprise\Instance\Ops\Facades;

use DreamFactory\Enterprise\Database\Models\Instance;
use DreamFactory\Enterprise\Instance\Ops\Providers\InstanceApiClientServiceProvider;
use DreamFactory\Enterprise\Instance\Ops\Services\InstanceApiClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static InstanceApiClientService connect(Instance $instance, $token = null, $header = null)
 * @method static string getProvisionedEndpoint()
 * @method static array|bool|\stdClass environment()
 * @method static array|bool determineInstanceState($sync = false)
 * @method static array resources()
 * @method static array|bool|\stdClass resource($resource, $id = null)
 * @method static bool clearLimitsCache()
 * @method static bool clearLimitsCounter($cacheKey)
 * @method static bool deleteManagedDataCache()
 * @method static array|bool|\stdClass get($uri, $payload = [], array $options = [])
 * @method static array|bool|\stdClass post($uri, $payload = [], array $options = [])
 * @method static array|bool|\stdClass put($uri, $payload = [], array $options = [])
 * @method static array|bool|\stdClass delete($uri, $payload = [], array $options = [])
 * @method static array|bool|\stdClass patch($uri, $payload = [], array $options = [])
 * @method static array|bool|\stdClass any($method, $uri, $payload = [], array $options = [])
 * @method static array|null|bool|\stdClass call($uri, array $payload = [], array $options = [], $method = Request::METHOD_POST)
 *
 * @see \DreamFactory\Enterprise\Instance\Ops\Services\InstanceApiClientService
 */
class InstanceApiClient extends Facade
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return InstanceApiClientServiceProvider::IOC_NAME;
    }
}
