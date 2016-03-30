<?php namespace DreamFactory\Enterprise\Instance\Ops\Services;

use DreamFactory\Enterprise\Common\Enums\EnterpriseDefaults;
use DreamFactory\Enterprise\Common\Enums\InstanceStates;
use DreamFactory\Enterprise\Common\Enums\OperationalStates;
use DreamFactory\Enterprise\Common\Services\BaseService;
use DreamFactory\Enterprise\Common\Traits\Restful;
use DreamFactory\Enterprise\Database\Enums\DeactivationReasons;
use DreamFactory\Enterprise\Database\Models\Instance;
use DreamFactory\Library\Utility\Curl;
use DreamFactory\Library\Utility\Uri;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
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
    /**
     * @type Connection
     */
    protected $db;

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Initialize and set up the transport layer
     *
     * @param Instance    $instance
     * @param string|null $token  The token to use instead of automatic one
     * @param string|null $header The HTTP header to use instead of DFE one
     * @param bool        $db     If true, open the instance database
     *
     * @return $this
     */
    public function connect(Instance $instance, $token = null, $header = null, $db = true)
    {
        $this->instance = $instance;

        //  Note trailing slash added...
        $this->baseUri = rtrim(Uri::segment([$this->instance->getProvisionedEndpoint(), $instance->getResourceUri()], false), '/') . '/';

        //  Set up the channel
        $this->token = $token ?: $this->generateToken([$instance->cluster->cluster_id_text, $instance->instance_id_text]);
        $this->requestHeaders = [$header ?: EnterpriseDefaults::CONSOLE_X_HEADER . ': ' . $this->token,];

        //  Open the database if wanted
        $db && $this->db = $this->instance->instanceConnection();

        return $this;
    }

    /**
     * Stay-puft
     */
    public function __destruct()
    {
        //  Make sure the database is disconnected before we go
        if (!empty($this->db)) {
            $this->db->disconnect();
            unset($this->db);
        }
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
        } catch (\Exception $_ex) {
            //  If we get here, must not be an active instance
            $this->error('[dfe.instance-api-client.environment] instance "' . $this->instance->instance_id_text . '" call failure: ' . $_ex->getMessage(),
                ['info' => Curl::getInfo()]);
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
        //  Already activated? We're done here
        if ($this->instance->activate_ind && $this->instance->ready_state_nbr == InstanceStates::READY) {
            return $this->environment();
        }

        //  Assume not activated
        $_readyState = InstanceStates::INIT_REQUIRED;
        $_env = null;

        //  Crack open the database and see how many tables we have
        if (false === ($_count = $this->getInstanceTableCount())) {
            //  But if we're already marked bogus, we're done here
            if ($this->instance->platform_state_nbr == OperationalStates::DEACTIVATED && !$this->instance->activate_ind) {
                return false;
            }
        }

        //  If the instance appears initialized, make the environment call
        if (false !== $_count && false !== ($_env = $this->environment())) {
            //  If not ready, figure out what it is
            if (InstanceStates::READY != $this->instance->ready_state_nbr) {
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
                    } catch (\Exception $_ex) {
                        //  No bueno. Not activated
                    }
                } else {
                    $_readyState = InstanceStates::INIT_REQUIRED;
                }
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

            if (Response::HTTP_OK == Curl::getLastHttpCode() && !empty($_resource = array_get($_response, 'resource'))) {
                return $_resource;
            }
        } catch (\Exception $_ex) {
            $this->error('[dfe.instance-api-client.resources] instance "' . $this->instance->instance_id_text . '" call failed', ['info' => Curl::getInfo()]);
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
        try {
            $_response = (array)$this->get(Uri::segment([$resource, $id]));

            if (Response::HTTP_OK == Curl::getLastHttpCode() && !empty($_resource = data_get($_response, 'resource'))) {
                return $_resource;
            }
        } catch (\Exception $_ex) {
            $this->error('[dfe.instance-api-client.resource] instance "' . $this->instance->instance_id_text . '" call failed: ' . $_ex->getMessage(),
                ['resource' => $resource, 'id' => $id, 'info' => Curl::getInfo()]);
        }

        return false;
    }

    /**
     * @return bool
     */
    public function clearLimitsCache()
    {
        try {
            //  Use instance's call method avoiding resource uri injection
            $this->instance->call('/instance/clear-limits-cache', [], [], Request::METHOD_DELETE);
            logger('[dfe.instance-api-client.clear-limits-cache] limits cache flushed by console');

            return true;
        } catch (\Exception $_ex) {
            \Log::error('[dfe.instance-api-client.clear-limits-cache] exception clearing limits cache: ' . $_ex->getMessage());

            return false;
        }
    }

    /**
     * @param string $cacheKey The cache key of the counter to delete
     *
     * @return bool
     */
    public function clearLimitsCounter($cacheKey)
    {
        try {
            //  Use instance's call method avoiding resource uri injection
            $this->instance->call('/instance/clear-limits-counter?cacheKey=' . urlencode($cacheKey), [], [], Request::METHOD_DELETE);
            logger('[dfe.instance-api-client.clear-limits-counter] limits counter "' . $cacheKey . '" cleared by console');

            return true;
        } catch (\Exception $_ex) {
            \Log::error('[dfe.instance-api-client.clear-limits-counter] exception clearing limits counter: ' . $_ex->getMessage());

            return false;
        }
    }

    /**
     * Deletes all cached managed data for instance
     *
     * @return Response
     */
    public function deleteManagedDataCache()
    {
        try {
            //  Use instance's call method avoiding resource uri injection
            $this->instance->call('/instance/managed-data-cache', [], [], Request::METHOD_DELETE);
            logger('[dfe.instance-api-client.delete-managed-data-cache] managed instance cache cleared by console');

            return true;
        } catch (\Exception $_ex) {
            \Log::error('[dfe.instance-api-client.delete-managed-data-cache] exception clearing managed instance cache: ' . $_ex->getMessage());

            return false;
        }
    }

    /**
     * @param array       $parts
     * @param string|null $separator
     *
     * @return string
     */
    protected function generateToken($parts = [], $separator = null)
    {
        $parts = is_array($parts) ? $parts : $parts = func_get_args();

        return hash(config('dfe.signature-method', EnterpriseDefaults::DEFAULT_SIGNATURE_METHOD), implode('', $parts));
    }

    /**
     * @return bool|int
     */
    protected function getInstanceTableCount()
    {
        $_count = false;

        try {
            $_tables = $this->getConnection()->getDoctrineSchemaManager()->listTables();

            if (!empty($_count = count($_tables))) {
                //  A chance to clean up old junk
                $this->removeLegacySettings();
            }
        } catch (\Exception $_ex) {
            \Log::error('[dfe.instance-api-client.get-instance-table-count] error contacting instance database: ' . $_ex->getMessage());
        }

        return $_count;
    }

    /**
     * Remove any legacy settings that were otherwise missed
     */
    protected function removeLegacySettings()
    {
        try {
            if ($this->getConnection()->delete('DELETE FROM system_resource WHERE name = :name', [':name' => 'setting'])) {
                logger('[dfe.instance-api-client.remove-legacy-settings] legacy artifact "setting" removed from system_resource table');
            }
        } catch (\Exception $_ex) {
            //  Ignored...
        }

        return $this;
    }

    /**
     * Call any instance method bypass
     *
     * @param string $name
     * @param array  $arguments
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        try {
            return call_user_func_array([$this->instance, $name], $arguments);
        } catch (\Exception $_ex) {
            throw new \BadMethodCallException('Method "' . $name . '" not found');
        }
    }

    /**
     * Call any static instance method bypass
     *
     * @param string $name
     * @param array  $arguments
     *
     * @return mixed
     */
    public function __callStatic($name, $arguments)
    {
        try {
            return call_user_func_array([get_class($this->instance), $name], $arguments);
        } catch (\Exception $_ex) {
            throw new \BadMethodCallException('Method "' . $name . '" not found');
        }
    }

    /**
     * Retrieves the current instance database connection
     *
     * @return \Illuminate\Database\Connection
     */
    protected function getConnection()
    {
        return $this->db ?: $this->db = $this->instance->instanceConnection();
    }
}
