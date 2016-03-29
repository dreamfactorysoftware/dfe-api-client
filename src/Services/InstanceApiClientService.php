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
use Exception;
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
        $_env = null;

        /**
         * Try and fast-track the determination. Peek in the instance's database to see how many tables exist...
         */
        try {
            $_db = $this->instance->instanceConnection();

            $_tables =
                $_db->select('SELECT count(*) AS table_count FROM information_schema.tables WHERE table_schema = :table_schema',
                    [':table_schema' => $this->instance->db_name_text]);

            $_tables = empty($_tables) ? 0 : $_tables[0]->table_count;

            //  Disconnect and clean up so we don't have orphan connections
            $_db->disconnect();
            unset($_db);

            //  If the instance was auto-deactivated, we're done.
            if (empty($_tables) && $this->instance->platform_state_nbr == OperationalStates::DEACTIVATED && !$this->instance->activate_ind) {
                return false;
            }
        } catch (\Exception $_ex) {
            \Log::error('[dfe.instance-api-client.determine-instance-state] error contacting instance database: ' . $_ex->getMessage());

            if (isset($_db)) {
                $_db->disconnect();
                unset($_db);
            }

            return false;
        }

        //  The instance appears initialized, make the environment call
        if (false !== ($_env = $this->environment())) {
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
        $_db = $_last = null;

        //  Remove the setting resource if requested
        if ('setting' == $resource) {
            try {
                $_db = $this->instance->instanceConnection();

                if ($_db->delete('DELETE FROM system_resource WHERE name = :name', [':name' => 'setting'])) {
                    logger('[dfe.instance-api-client.resource] legacy artifact "setting" removed from system_resource table');
                }
            } catch (Exception $_ex) {
                //  Ignored...
            }
            finally {
                //  Make sure we close the connection
                !empty($_db) && $_db->disconnect();
                unset($_db);
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
}
