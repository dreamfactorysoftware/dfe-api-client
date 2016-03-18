<?php namespace DreamFactory\Enterprise\Instance\Ops\Services;

use DreamFactory\Enterprise\Common\Enums\EnterpriseDefaults;
use DreamFactory\Enterprise\Common\Enums\InstanceStates;
use DreamFactory\Enterprise\Common\Services\BaseService;
use DreamFactory\Enterprise\Database\Enums\DeactivationReasons;
use DreamFactory\Enterprise\Database\Models\Instance;
use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\Json;
use DreamFactory\Library\Utility\Uri;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    /**
     * @type array The request headers
     */
    protected $headers;

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Initialize and set up the transport layer
     *
     * @param Instance    $instance
     * @param string|null $token  The token to use instead of automatic one
     * @param string|null $header The HTTP header to use instead of DFE one
     *
     * @return $this
     */
    public function connect(Instance $instance, $token = null, $header = null)
    {
        $this->instance = $instance;

        //  Note trailing slash added...
        $this->resourceUri = rtrim(Uri::segment([$this->getProvisionedEndpoint(), $instance->getResourceUri()], false), '/') . '/';

        //  Set up the channel
        $this->token = $token ?: $this->generateToken([$instance->cluster->cluster_id_text, $instance->instance_id_text]);
        $this->headers = [$header ?: EnterpriseDefaults::CONSOLE_X_HEADER . ': ' . $this->token,];

        return $this;
    }

    /**
     * Retrieves an instance's environment
     *
     * @return array|bool
     */
    public function environment()
    {
        try {
            $_env = (array)$this->get('environment');

            if (Response::HTTP_OK == Curl::getLastHttpCode() && null !== data_get($_env, 'platform')) {
                return $_env;
            }
        } catch (Exception $_ex) {
            //  If we get here, must not be an active instance
            $this->error('[dfe.instance-api-client] environment() call failure | instance "' . $this->instance->instance_id_text . '"');
        }

        return false;
    }

    /**
     * Queries an instance to determine it's status and "ready" state
     *
     * @param bool $sync If true, the instance's state is noted
     *
     * @return array|bool
     */
    public function determineInstanceState($sync = true)
    {
        //  Assume not activated
        $_readyState = InstanceStates::INIT_REQUIRED;

        //  No environment, no instance...
        if (false !== $_env = $this->environment()) {
            //  Are we already "READY"?
            if (InstanceStates::READY == $this->instance->ready_state_nbr) {
                return $_env;
            }

            //  Check environment and determine ready state
            if (null !== data_get($_env, 'platform.version_current')) {
                try {
                    //  Check if fully ready
                    if (false === ($_admin = $this->resource('admin')) || 0 == count($_admin)) {
                        $_readyState = InstanceStates::ADMIN_REQUIRED;
                    } else {
                        //  We're good!
                        $_readyState = InstanceStates::READY;
                    }
                } catch (Exception $_ex) {
                    //  No bueno. Not activated
                }
            } else {
                $_readyState = InstanceStates::INIT_REQUIRED;
            }
        }

        //  Sync if requested...
        (InstanceStates::INIT_REQUIRED == $_readyState) && $_env = false;
        $sync && $this->instance->updateInstanceState(false !== $_env, true, DeactivationReasons::NEVER_ACTIVATED, $_readyState);

        return $_env;
    }

    /**
     * Returns a list of available resources
     *
     * @return array
     */
    public function resources()
    {
        try {
            //  Return all system resources
            $_response = (array)$this->get('/?as_list=true');

            if (Response::HTTP_OK == ($_last = Curl::getLastHttpCode()) && !empty($_resource = array_get($_response, 'resource'))) {
                return $_resource;
            }
        } catch (Exception $_ex) {
            $this->error('[dfe.instance-api-client] resources() call failure from instance "' . $this->instance->instance_id_text . '"', Curl::getInfo());
        }

        return false;
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
        $_last = null;

        //  Remove the setting resource if requested
        if ('setting' == $resource) {
            try {
                if ($this->instance->instanceConnection()->delete('DELETE FROM system_resource WHERE name = :name', [':name' => 'setting'])) {
                    logger('[dfe.instance-api-client.resource] legacy artifact "setting" removed from system_resource table');
                }
            } catch (Exception $_ex) {
                //  Ignored...
            }

            return [];
        }

        try {
            $_response = (array)$this->get(Uri::segment([$resource, $id]));

            if (Response::HTTP_OK == ($_last = Curl::getLastHttpCode()) && !empty($_resource = array_get($_response, 'resource'))) {
                return $_resource;
            }
        } catch (Exception $_ex) {
            $this->error('[dfe.instance-api-client] resource() call failure from instance "' . $this->instance->instance_id_text . '": ' . $_ex->getMessage());
        }

        return false;
    }

    /**
     * @return string
     */
    public function getProvisionedEndpoint()
    {
        return $this->instance->getProvisionedEndpoint();
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
        $options[CURLOPT_HTTPHEADER] = array_merge(array_get($options, CURLOPT_HTTPHEADER, []), $this->headers ?: []);

        if (!empty($payload) && !is_scalar($payload)) {
            $payload = Json::encode($payload);
            $options[CURLOPT_HTTPHEADER] = array_merge(array_get($options, CURLOPT_HTTPHEADER, []), ['Content-Type: application/json']);
        }

        try {
            $_response = Curl::request($method, $this->resourceUri . ltrim($uri, ' /'), $payload, $options);

            $_info = Curl::getInfo();

            if (false === stripos($_info['content_type'], 'text/html') && Response::HTTP_OK != $_info['http_code']) {
                if (!is_string($_response) && null !== ($_error = data_get($_response, 'error'))) {
                    $this->error('[df.instance-api-client.' .
                        $method .
                        '.' .
                        $uri .
                        '] unexpected response: ' .
                        data_get($_error, 'code', $_info['http_code']) .
                        ' - ' .
                        data_get($_error, 'message'));
                } else {
                    $this->error('[df.instance-api-client.' . $method . '.' . $uri . '] possible bad response: ' . print_r($_response, true));
                }

                return false;
            }
        } catch (Exception $_ex) {
            $this->error('[df.instance-api-client.' . $method . '.' . $uri . '] failure: ' . $_ex->getMessage());

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
