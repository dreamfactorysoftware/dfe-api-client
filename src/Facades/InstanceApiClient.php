<?php namespace DreamFactory\Enterprise\Instance\Ops\Facades;

use DreamFactory\Enterprise\Client\Instance\Providers\InstanceApiClientServiceProvider;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string make(string $templateKey, array $data = [], array $mergeData = [])
 * @method static string makeFromString(string $template, array $data = [], array $mergeData = [])
 * @method static Client connect(\DreamFactory\Enterprise\Database\Models\Instance $instance, $config = [])
 * @method static array resources()
 * @method static array|\stdClass resource(string $resource, mixed $id = null)
 * @method static array|boolean get(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean post(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean put(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean delete(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean patch(string $uri, mixed $payload = [], array $options = [])
 * @method static array|boolean any(string $method, string $uri, mixed $payload = [], array $options = [])
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