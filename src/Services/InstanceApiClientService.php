<?php namespace DreamFactory\Enterprise\Instance\Ops\Services;

use DreamFactory\Enterprise\Common\Enums\EnterpriseDefaults;
use DreamFactory\Enterprise\Common\Enums\InstanceStates;
use DreamFactory\Enterprise\Common\Services\BaseService;
use DreamFactory\Enterprise\Common\Traits\Restful;
use DreamFactory\Enterprise\Database\Enums\DeactivationReasons;
use DreamFactory\Enterprise\Database\Models\Instance;
use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\Uri;
use Exception;
use Illuminate\Http\Response;

class InstanceApiClientService extends BaseService
{
    //******************************************************************************
    //* Traits
    //******************************************************************************

    use Restful;

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
        $this->baseUri = rtrim(Uri::segment([$this->getProvisionedEndpoint(), $instance->getResourceUri()], false), '/') . '/';

        //  Set up the channel
        $this->token = $token ?: $this->generateToken([$instance->cluster->cluster_id_text, $instance->instance_id_text]);
        $this->requestHeaders = [$header ?: EnterpriseDefaults::CONSOLE_X_HEADER . ': ' . $this->token,];

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
        }

        //  If we get here, must not be an active instance
        $this->error('[dfe.instance-api-client] environment() call failure | instance "' . $this->instance->instance_id_text . '"');

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
        $_env = $this->environment();

        //  Assume not activated
        $_readyState = InstanceStates::INIT_REQUIRED;

        //  Pull environment and try and determine ready state
        if (false !== $_env) {
            //  If already marked "READY", just return...
            if (InstanceStates::READY == $this->instance->ready_state_nbr) {
                return $_env;
            }

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

        (InstanceStates::INIT_REQUIRED === $_readyState) && $_env = false;
        $sync && $this->instance->updateInstanceState($_env !== false, true, DeactivationReasons::INCOMPLETE_PROVISION, $_readyState);

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

            return data_get($_response, 'resource', false);
        } catch (Exception $_ex) {
            $this->error('[dfe.instance-api-client] resources() call failure from instance "' . $this->instance->instance_id_text . '"', Curl::getInfo());

            return false;
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
        $_last = null;

        try {
            $_response = (array)$this->get(Uri::segment([$resource, $id]));

            if (Response::HTTP_OK == ($_last = Curl::getLastHttpCode())) {
                return data_get($_response, 'resource', false);
            }
        } catch (Exception $_ex) {
            $_last = $_ex->getMessage();
        }

        $this->error('[dfe.instance-api-client] resource() call failure from instance "' . $this->instance->instance_id_text . '": ' . $_last,
            Curl::getInfo());

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
