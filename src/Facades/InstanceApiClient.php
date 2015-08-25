<?php namespace DreamFactory\Enterprise\Instance\Ops\Facades;

use DreamFactory\Enterprise\Database\Models\Instance;
use DreamFactory\Enterprise\Instance\Ops\Providers\InstanceApiClientServiceProvider;
use DreamFactory\Enterprise\Instance\Ops\Services\InstanceApiClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static InstanceApiClientService connect(Instance $instance)
 * @method static array resources()
 * @method static array resource(string $resource, mixed $id = null)
 * @method static array|boolean get(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean post(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean put(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean delete(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean patch(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean any(string $method, string $uri, mixed $payload = [], array $options = [])
 * @method static array|null|\stdClass call(string $uri, array $payload = [], array $options = [], string $method = Request::METHOD_POST)
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