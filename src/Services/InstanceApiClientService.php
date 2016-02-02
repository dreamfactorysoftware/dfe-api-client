<?php namespace DreamFactory\Enterprise\Instance\Ops\Services;

use Carbon\Carbon;
use DreamFactory\Enterprise\Common\Enums\EnterpriseDefaults;
use DreamFactory\Enterprise\Common\Enums\OperationalStates;
use DreamFactory\Enterprise\Common\Services\BaseService;
use DreamFactory\Enterprise\Database\Models\Deactivation;
use DreamFactory\Enterprise\Database\Models\Instance;
use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\Enums\DateTimeIntervals;
use DreamFactory\Library\Utility\Json;
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
        $this->resourceUri = rtrim(Uri::segment([$instance->getProvisionedEndpoint(), $instance->getResourceUri()], false), '/') . '/';

        //  Set up the channel
        $this->token = $token ?: $this->generateToken([$instance->cluster->cluster_id_text, $instance->instance_id_text]);
        $this->headers = [$header ?: EnterpriseDefaults::CONSOLE_X_HEADER . ': ' . $this->token,];

        return $this;
    }

    /**
     * Queries an instance to determine it's status
     *
     * @param bool $update If true, the instance's state is noted
     *
     * @return array|bool
     */
    public function status($update = true)
    {
        try {
            $_env = $this->environment();

            if ($update) {
                if (empty($_env)) {
                    //  Instance not activated...
                    $this->instance->where('last_state_date', '0000-00-00 00:00:00')->update([
                        'last_state_date' => Carbon::now(),
                        'activate_ind'    => 0,
                        'ready_state_nbr' => OperationalStates::NOT_ACTIVATED,
                    ]);

                    //  Register for deactivation if not already...
                    try {
                        Deactivation::create([
                            'user_id'          => $this->instance->user_id,
                            'instance_id'      => $this->instance->id,
                            'activate_by_date' => date('Y-m-d H:i:s', time() + DateTimeIntervals::SECONDS_PER_DAY),
                        ]);
                    } catch (\Exception $_ex) {
                        //  Ignore dupes
                        if (false !== stripos($_ex->getMessage(), '1062')) {
                            $this->error('[dfe.instance-api-client] exception saving deactivation row: ' . $_ex->getMessage());
                        }
                    }

                    //  Bogosity gets false
                    $_env = false;
                } else {
                    if ($this->instance->where('activate_ind', 0)->update([
                        'last_state_date'    => Carbon::now(),
                        'activate_ind'       => 1,
                        'platform_state_nbr' => OperationalStates::ACTIVATED,
                    ])
                    ) {
                        //  Remove deactivation row cuz we's activated!
                        if (null !== ($_model = Deactivation::where(['instance_id' => $this->instance->id, 'user_id' => $this->instance->user_id])->first())) {
                            $_model->delete();
                        }
                    }
                }
            }

            return $_env;
        } catch (\Exception $_ex) {
            $this->error('[dfe.instance-api-client] exception getting instance status: ' . $_ex->getMessage());
        }

        return false;
    }

    /**
     * Retrieves an instance's environment
     */
    public function environment()
    {
        try {
            return (array)$this->get('environment');
        } catch (\Exception $_ex) {
            $this->error('[dfe.instance-api-client] environment() call failure from instance "' . $this->instance->instance_id_text . '"', Curl::getInfo());

            return [];
        }
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

            return array_get($_response, 'resource', false);
        } catch (\Exception $_ex) {
            $this->error('[dfe.instance-api-client] resources() call failure from instance "' . $this->instance->instance_id_text . '"', Curl::getInfo());

            return [];
        }
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
        try {
            $_response = (array)$this->get(Uri::segment([$resource, $id]));

            return array_get($_response, 'resource', false);
        } catch (\Exception $_ex) {
            $this->error('[dfe.instance-api-client] resource() call failure from instance "' . $this->instance->instance_id_text . '": ' . $_ex->getMessage(),
                Curl::getInfo());

            return [];
        }
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
        } catch (\Exception $_ex) {
            $this->error('[dfe.instance-api-client] ' . $method . ' failure: ' . $_ex->getMessage());

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
