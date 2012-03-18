<?php
/**
 * MongoSession is a port (with modifications) of the Database Session library included with FuelPHP
 *
 * @package    MongoSession
 * @author     Justin Hall
 * @license    MIT License
 * @copyright  2010 - 2011 Justin Hall
 */

/**
 * Part of the Fuel framework.
 *
 * @package    Fuel
 * @version    1.0
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2011 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Fuel\Core;



// --------------------------------------------------------------------

class Session_Mongo extends \Session_Driver
{

	/*
	 * @var	session database result object
	 */
	protected $record = null;

	/**
	 * array of driver config defaults
	 */
	protected static $_defaults = array(
		'cookie_name'		=> 'fueldid',			// name of the session cookie for database based sessions
		'collection'		=> 'Sessions',                // name of the sessions collection
		'gc_probability'	=> 5                        // probability % (between 0 and 100) for garbage collection
	);

	// --------------------------------------------------------------------

	public function __construct($config = array())
	{
		// merge the driver config with the global config
		$this->config = array_merge($config, is_array($config['db']) ? $config['db'] : static::$_defaults);

		$this->config = $this->_validate_config($this->config);
	}

	// --------------------------------------------------------------------

	/**
	 * create a new session
	 *
	 * @access	public
	 * @return	Fuel\Core\Session_Db
	 */
	public function create()
	{
		// create a new session
		$this->keys['session_id']	= $this->_new_session_id();
		$this->keys['previous_id']	= $this->keys['session_id'];	// prevents errors if previous_id has a unique index
		$this->keys['ip_hash']		= md5(\Input::ip().\Input::real_ip());
		$this->keys['user_agent']	= \Input::user_agent();
		$this->keys['created'] 		= $this->time->get_timestamp();
		$this->keys['updated'] 		= $this->keys['created'];
		$this->keys['payload'] 		= '';

                // create the session record
                $result = \Mongo_Db::instance($this->config['database'])->insert($this->config['collection'], $this->keys);

		// and set the session cookie
		$this->_set_cookie();

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * read the session
	 *
	 * @access	public
	 * @param	boolean, set to true if we want to force a new session to be created
	 * @return	Fuel\Core\Session_Driver
	 */
	public function read($force = false)
	{
		// get the session cookie
		$cookie = $this->_get_cookie();

		// if no session cookie was present, create it
		if ($cookie === false or $force)
		{
			$this->create();
		}

                // read the session record
                $this->record = \Mongo_Db::instance($this->config['database'])
                        ->where(
                                array(
                                    'session_id' => $this->keys['session_id']
                                ))
                        ->get_one($this->config['collection']);

		// record found?
		if (!empty($this->record))
		{
			$payload = $this->_unserialize($this->record['payload']);
		}
		else
		{
                        // try to find the session on previous id
                        $this->record = \Mongo_Db::instance($this->config['database'])
                            ->where(
                                    array(
                                        'previous_id' => $this->keys['session_id']
                                    ))
                            ->get_one($this->config['collection']);
                        

			// record found?
			if (!empty($this->record))
			{
                                // previous id used, correctly set session id so it wont be overwritten with previous id.
                                $this->keys['session_id'] = $this->record['session_id'];                             
				$payload = $this->_unserialize($this->record['payload']);
			}
			else
			{
				// cookie present, but session record missing. force creation of a new session
				$this->read(true);
				return;
			}
		}

		if (isset($payload[0])) $this->data = $payload[0];
		if (isset($payload[1])) $this->flash = $payload[1];

		return parent::read();
	}

	// --------------------------------------------------------------------

	/**
	 * write the current session
	 *
	 * @access	public
	 * @return	Fuel\Core\Session_Db
	 */
	public function write()
	{
		// do we have something to write?
		if ( ! empty($this->keys) and ! empty($this->record))
		{
			parent::write();

			// rotate the session id if needed
			$this->rotate(false);

			// create the session record, and add the session payload
                        
			$session = $this->keys;
			$session['payload'] = $this->_serialize(array($this->data, $this->flash));
                        
                        // make sure we aren't messing with the $id field
                        unset($session['_id']);
                        
                        /* we do findandmodify for ACID compliance. 
                         * 
                         */
                        $result = \Mongo_Db::instance($this->config['database'])
                                ->command(
                                    array(
                                        'findandmodify' => $this->config['collection'],
                                        'query' => array(
                                            'session_id' => $this->record['session_id']
                                        ),
                                        'update' => array (
                                            '$set' => $session
                                        ),
                                        'new' => true
                                    )
                                );
			
			// update went well?
			if ($result !== false || !empty($result))
			{
				// then update the cookie
				$this->_set_cookie();
			}
			else
			{
				logger(Fuel::L_ERROR, 'Session update failed, session record could not be found. Concurrency issue?');
			}

			// do some garbage collection
			if (mt_rand(0,100) < $this->config['gc_probability'])
			{
				$expired = $this->time->get_timestamp() - $this->config['expiration_time'];
                                
                                $this->record = \Mongo_Db::instance($this->config['database'])
                                    ->where_lt('updated', $expired)
                                    ->delete($this->config['collection']);
                        }
		}

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * destroy the current session
	 *
	 * @access	public
	 * @return	Fuel\Core\Session_Db
	 */
	public function destroy()
	{
		// do we have something to destroy?
		if ( ! empty($this->keys) and ! empty($this->record))
		{
                        // delete the session record
                        $result = \Mongo_Db::instance($this->config['database'])
                                    ->where(
                                            array(
                                                'session_id' => $this->keys['session_id']
                                            ))
                                    ->delete($this->config['collection']);
		}

		// reset the stored session data
		$this->record = null;
		$this->keys = $this->flash = $this->data = array();

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * validate a driver config value
	 *
	 * @param	array	array with configuration values
	 * @access	public
	 * @return  array	validated and consolidated config
	 */
	public function _validate_config($config)
	{
		$validated = array();

		foreach ($config as $name => $item)
		{
			// filter out any driver config
			if (!is_array($item))
			{
				switch ($name)
				{
					case 'cookie_name':
						if ( empty($item) or ! is_string($item))
						{
							$item = 'fueldid';
						}
					break;

					case 'database':
						// do we have a database?
						if ( empty($item) or ! is_string($item))
						{
							\Config::load('db', true);
							$item = \Config::get('db.active', false);
						}
						if ($item === false)
						{
							throw new \FuelException('You have specify a database to use MongoDB backed sessions.');
						}
					break;

					case 'collection':
						// and a table name?
						if ( empty($item) or ! is_string($item))
						{
							throw new \FuelException('You have specify a collection to use MongoDB backed sessions.');
						}
					break;

					case 'gc_probability':
						// do we have a path?
						if ( ! is_numeric($item) or $item < 0 or $item > 100)
						{
							// default value: 5%
							$item = 5;
						}
					break;

					default:
					break;
				}

				// global config, was validated in the driver
				$validated[$name] = $item;
			}
		}

		// validate all global settings as well
		return parent::_validate_config($validated);
	}

}


