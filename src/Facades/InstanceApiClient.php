<?php namespace DreamFactory\Enterprise\Instance\Ops\Facades;

use DreamFactory\Enterprise\Database\Models\Instance;
use DreamFactory\Enterprise\Instance\Ops\Providers\InstanceApiClientServiceProvider;
use DreamFactory\Enterprise\Instance\Ops\Services\InstanceApiClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static InstanceApiClientService connect(Instance $instance)
 * @method static array resources()
 * @method static array resource($resource, $id = null)
 * @method static array|boolean get($uri, $payload = [], array $options = [])
 * @method static array|boolean post($uri, $payload = [], array $options = [])
 * @method static array|boolean put($uri, $payload = [], array $options = [])
 * @method static array|boolean delete($uri, $payload = [], array $options = [])
 * @method static array|boolean patch($uri, $payload = [], array $options = [])
 * @method static array|boolean any($method, $uri, $payload = [], array $options = [])
 * @method static array|null|\stdClass call($uri, array $payload = [], array $options = [], $method = Request::METHOD_POST)
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
