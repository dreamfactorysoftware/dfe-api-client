<?php namespace DreamFactory\Enterprise\Instance\Ops\Services;

use DreamFactory\Enterprise\Common\Enums\EnterpriseDefaults;
use DreamFactory\Enterprise\Common\Services\BaseService;
use DreamFactory\Enterprise\Database\Models\Instance;
use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\Uri;
use Illuminate\Http\Request;

class InstanceApiClientService extends BaseService
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type Instance The current instance
     */
    protected $instance;
    /**
     * @type string The access token to use for communication with instances
     */
    protected $token;
    /**
     * @type string The base resource uri to use to talk to the instance
     */
    protected $resourceUri;

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Initialize and set up the transport layer
     *
     * @param Instance $instance
     *
     * @return $this
     */
    public function connect(Instance $instance)
    {
        $this->instance = $instance;
        $this->token = $this->generateToken([$instance->cluster->cluster_id_text, $instance->instance_id_text]);
        $this->resourceUri = Uri::segment([$instance->getProvisionedEndpoint(), $instance->getResourceUri()], false);

        return $this;
    }

    /**
     * Returns a list of available resources
     *
     * @return array
     */
    public function resources()
    {
        //  Return all system resources
        return (array)$this->get()->resource;
    }

    /**
     * Requests a list of or specific resource
     *
     * @param string     $resource The resource to retrieve
     * @param mixed|null $id       An id to append to the resource request
     *
     * @return array|bool|\stdClass
     */
    public function resource($resource, $id = null)
    {
        if (false !== ($_response = $this->get(Uri::segment([$resource, $id], true)))) {
            if (isset($_response->resource)) {
                return $_response->resource;
            }
        }

        return (array)$this->get(Uri::segment([$resource, $id], true))->resource;
    }

    /**
     * @param string|null $uri
     * @param array       $payload
     * @param array       $options
     *
     * @return array|bool|\stdClass
     */
    public function get($uri = null, $payload = [], $options = [])
    {
        return $this->call($uri, $payload, $options, Request::METHOD_GET);
    }

    /**
     * @param string $uri
     * @param array  $payload
     * @param array  $options
     *
     * @return array|bool|\stdClass
     */
    public function post($uri, $payload = [], $options = [])
    {
        return $this->call($uri, $payload, $options, Request::METHOD_POST);
    }

    /**
     * @param string $uri
     * @param array  $payload
     * @param array  $options
     *
     * @return array|bool|\stdClass
     */
    public function put($uri, $payload = [], $options = [])
    {
        return $this->call($uri, $payload, $options, Request::METHOD_PUT);
    }

    /**
     * @param string $uri
     * @param array  $payload
     * @param array  $options
     *
     * @return array|bool|\stdClass
     */
    public function patch($uri, $payload = [], $options = [])
    {
        return $this->call($uri, $payload, $options, Request::METHOD_PATCH);
    }

    /**
     * @param string $uri
     * @param array  $payload
     * @param array  $options
     *
     * @return array|bool|\stdClass
     */
    public function delete($uri, $payload = [], $options = [])
    {
        return $this->call($uri, $payload, $options, Request::METHOD_DELETE);
    }

    /**
     * @param string $method The HTTP method to use
     * @param string $uri
     * @param array  $payload
     * @param array  $options
     *
     * @return array|bool|\stdClass
     */
    public function any($method, $uri, $payload = [], $options = [])
    {
        return $this->call($uri, $payload, $options, $method);
    }

    /**
     * Makes a shout out to an instance's private back-end. Should be called bootyCall()  ;)
     *
     * @param string $uri     The REST uri (i.e. "/[rest|api][/v[1|2]]/db", "/rest/system/users", etc.) to retrieve
     *                        from the instance
     * @param array  $payload Any payload to send with request
     * @param array  $options Any options to pass to transport layer
     * @param string $method  The HTTP method. Defaults to "POST"
     *
     * @return array|bool|\stdClass
     */
    public function call($uri, $payload = [], $options = [], $method = Request::METHOD_POST)
    {
        $options[CURLOPT_HTTPHEADER] =
            array_merge(array_get($options, CURLOPT_HTTPHEADER, []),
                [EnterpriseDefaults::CONSOLE_X_HEADER . ': ' . $this->token]);

        try {
            $_response = Curl::request($method, Uri::segment([$this->resourceUri, $uri], false), $payload, $options);
        } catch (\Exception $_ex) {
            return false;
        }

        return $_response;
    }

    /**
     * Creates token to talk to the instance
     *
     * @param mixed       $parts     One or more keys/strings to be concatenated to make the hash string
     * @param string|null $separator The string to delimit the parts
     *
     * @return string
     */
    protected function generateToken($parts = [], $separator = null)
    {
        $parts = is_array($parts) ? $parts : $parts = func_get_args();

        return hash(config('dfe.signature-method', EnterpriseDefaults::DEFAULT_SIGNATURE_METHOD), implode('', $parts));
    }
}
