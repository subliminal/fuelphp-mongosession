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
	 * @var sessionn database result object
	 */
	protected $record = null;

	/**
	 * array of driver config defaults
	 */
	protected static $_defaults = array(
		'cookie_name' => 'fuelmonid', // name of the session cookie for database based sessions
		'collection' => 'session', // name of the sessions collection
		'gc_probability' => 5 // probability % (between 0 and 100) for garbage collection
	);

	/**
	 * @var \MongoCollection storage for the mongo object
	 */
	protected $mongo = false;

	// --------------------------------------------------------------------

	public function __construct($config = array())
	{
		// merge the driver config with the global config
		$this->config = array_merge($config, is_array($config['mongo']) ? $config['mongo'] : static::$_defaults);

		$this->config = $this->_validate_config($this->config);
	}

	// --------------------------------------------------------------------

	/**
	 * driver initialisation
	 *
	 * @access public
	 * @return void
	 */
	public function init()
	{
		// generic driver initialisation
		parent::init();

		if ($this->mongo === false)
		{
			// do we have the mongo extenion available
			if (!class_exists('MongoClient'))
			{
				throw new \FuelException('Mongo session are configured, but your PHP installation doesn\'t have the MongoDB extension loaded.');
			}

			// instantiate the mongo object
			$this->mongo = \Mongo_Db::instance($this->config['database'])->get_collection($this->config['collection']);
		}
	}

	/**
	 * create a new session
	 *
	 * @access    public
	 * @return    \Fuel\Core\Session_Db
	 */
	public function create($payload = '')
	{
		// create a new session
		$this->keys['session_id'] = $this->_new_session_id();
		$this->keys['previous_id'] = $this->keys['session_id']; // prevents errors if previous_id has a unique index
		$this->keys['ip_hash'] = md5(\Input::ip() . \Input::real_ip());
		$this->keys['user_agent'] = \Input::user_agent();
		$this->keys['created'] = $this->time->get_timestamp();
		$this->keys['updated'] = $this->keys['created'];

		// add the payload
		$this->keys['payload'] = $payload;

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * read the session
	 *
	 * @access    public
	 * @param    boolean , set to true if we want to force a new session to be created
	 * @return    \Fuel\Core\Session_Driver
	 */
	public function read($force = false)
	{
		$this->data = array();
		$this->keys = array();
		$this->flash = array();
		$this->record = array();

		// get the session cookie
		$cookie = $this->_get_cookie();

		// if no session cookie was present, create it
		if ($cookie and !$force and isset($cookie[0]))
		{
			$payload = $this->_read_mongo($cookie[0]);

			if (empty($payload))
			{
				// cookie present, but session record missing. force creation of a new session
				return $this->read(true);
			} else {
				$payload['keys']['session_id'] = $cookie[0];
			}
			// unpack the payload
//			$payload = $this->_unserialize($payload);

			// session referral?
			if (isset($payload['rotated_session_id']))
			{
				$payload = $this->_read_mongo($payload['rotated_session_id']);
				if ($payload === false)
				{
					// cookie present, but session record missing. force creation of a new session
					return $this->read(true);
				} else
				{
					// unpack the payload
//					$payload = $this->_unserialize($payload);
				}
			}

			if ( ! isset($payload['_id']) or get_class($payload['_id']) != 'MongoId')
			{
				// not a valid cookie payload
			} elseif ($payload['keys']['updated'] + $this->config['expiration_time'] <= $this->time->get_timestamp())
			{
				// session has expired
			} elseif ($this->config['match_ip'] and $payload['keys']['ip_hash'] !== md5(\Input::ip() . \Input::real_ip()))
			{
				// IP address doesn't match
			} elseif ($this->config['match_ua'] and $payload['keys']['user_agent'] !== \Input::user_agent())
			{
				// user agent doesn't match
			} else
			{
				// session is valid, retrieve the rest of the payload
				if (isset($payload['keys']) and is_array($payload['keys'])) $this->keys = $payload['keys'];
				if (isset($payload['data']) and is_array($payload['data'])) $this->data = $payload['data'];
				if (isset($payload['flash']) and is_array($payload['flash'])) $this->flash = $payload['flash'];
			}
		}

		return parent::read();
	}

	/**
	 * write the session
	 *
	 * @access public
	 * @return \Fuel\Core\Session_Mongo
	 */
	public function write()
	{
		// do we have something to write?
		if (!empty($this->keys) or !empty($this->data) or !empty($this->flash))
		{
			parent::write();

			// rotate the session id if needed
			$this->rotate(false);

			// session payload
//			$payload = $this->_serialize();

//			$cookie = $this->_get_cookie();

			// create the session file
			$result = $this->_write_mongo(
				$this->keys['session_id'],
				array('keys' => $this->keys, 'data' => $this->data, 'flash' => $this->flash)
			);

			// was the session id rotated?
			if (isset($this->keys['previous_id']) and $this->keys['previous_id'] != $this->keys['session_id'])
			{

				// point the old session file to the new one, we don't want to lose the session
//				$payload = $this->_serialize(array('rotated_session_id', $this->keys['session']));
				$this->_write_mongo(
					$this->keys['previous_id'],
					array('keys' => $this->keys, 'data' => $this->data, 'flash' => $this->flash)
				);
				$this->_set_cookie($this->keys['previous_id']);
			} else {
				$this->_set_cookie($this->keys['session_id']);
			}

			if(mt_rand(0,100) < $this->config['gc_probability']) {
				$expired = $this->time->get_timestamp() - $this->config['expiration_time'];
				$this->mongo->remove(array('keys' => array('updated' => array('$lt' => $expired))));
			}
		}
	}

	protected function _read_mongo($cookie_id)
	{
		$result = $this->mongo->findOne(array('session_id'=>$cookie_id));

		return $result;
	}

	protected function _write_mongo($cookie_id, $payload)
	{
		$payload['session_id'] = $cookie_id;
		if (($result = $this->mongo->update( array('session_id'=>$cookie_id), $payload, array('upsert' => true))) !== true)
		{
			throw \FuelException('Mongo couldn\'t write a session returned error code "' . $result['errmsg'] . '".');
		}
		return $result;
	}

// --------------------------------------------------------------------

	/**
	 * destroy the current session
	 *
	 * @access    public
	 * @return    Fuel\Core\Session_Db
	 */
	public function destroy()
	{
		// do we have something to destroy?
		if (!empty($this->keys) and !empty($this->record))
		{
			$this->mongo->remove(array('session_id'=>$this->keys['session_id']));
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
	 * @param    array    array with configuration values
	 * @access    public
	 * @return  array    validated and consolidated config
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
						if (empty($item) or !is_string($item))
						{
							$item = 'fueldid';
						}
						break;

					case 'database':
						// do we have a database?
						if (empty($item) or !is_string($item))
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
						if (empty($item) or !is_string($item))
						{
							throw new \FuelException('You have specify a collection to use MongoDB backed sessions.');
						}
						break;

					case 'gc_probability':
						// do we have a path?
						if (!is_numeric($item) or $item < 0 or $item > 100)
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


