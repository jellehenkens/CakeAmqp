<?php

class CakeAmqpBase extends Object {

/**
 * Holds the flag if a connection has been made to the AMQP broker
 *
 * @var boolean 
 */
	protected $_connected = false;

/**
 * Holds the instance of the AMQPConnection object
 *
 * @var AMQPConnection 
 */
	protected $_connection = null;

/**
 * Holds the instance of the AMQPChannel object
 *
 * @var AMQPChannel 
 */
	protected $_channel = null;

/**
 * List of valid exchange types
 *
 * @var array 
 */
	protected $_exchangeTypes = array(
		'topic' => AMQP_EX_TYPE_TOPIC,
		'direct' => AMQP_EX_TYPE_DIRECT,
		'fanout' => AMQP_EX_TYPE_FANOUT,
		'headers' => AMQP_EX_TYPE_HEADER
	);

/**
 * List of valid exchange flags
 *
 * @var array 
 */
	protected $_exchangeFlags = array(
		'durable' => AMQP_DURABLE,
		'passive' => AMQP_PASSIVE,
		'auto_delete' => AMQP_AUTODELETE,
		'internal' => AMQP_INTERNAL
	);

/**
 * List of valid queue flags
 *
 * @var array 
 */
	protected $_queueFlags = array(
		'durable' => AMQP_DURABLE,
		'passive' => AMQP_PASSIVE,
		'auto_delete' => AMQP_AUTODELETE,
		'internal' => AMQP_INTERNAL,
		'exclusive' => AMQP_EXCLUSIVE
	);

/**
 * Holds the configured exchanges
 *
 * @var array 
 */
	protected $_exchanges = array();

/**
 * Holds the configured queues
 *
 * @var array 
 */
	protected $_queues = array();

/**
 * Datasource being used
 *
 * @var string 
 */
	protected $_datasource = null;

/**
 * Connection configuration
 *
 * @var array 
 */
	protected $_config = array();

/**
 * CakeAmqp constructor which checks the configuration
 *
 * @throws CakeException 
 */
	function __construct($datasource = 'default') {
		$this->_datasource = $datasource;
	}

/**
 * Cleanly close the connection
 * 
 */
	function __destruct() {
		if ($this->_connected) {
			$this->_connection->disconnect();
		}
	}

/**
 * Returns the current AMQPConnection object
 *
 * Will try to connect to the broker if no connection was made
 *
 * @return AMQPConnection 
 */
	public function connection() {
		if ($this->_connected) {
			return $this->_connection;
		}

		try {
			$this->_loadConfiguration();

			$this->_connection = new AMQPConnection();
			$this->_connection->setHost($this->_config['host']);
			$this->_connection->setPort($this->_config['port']);
			$this->_connection->setLogin($this->_config['user']);
			$this->_connection->setPassword($this->_config['pass']);
			$this->_connection->setVhost($this->_config['vhost']);
			$this->_connection->connect();

			$this->_channel = new AMQPChannel($this->_connection);

			$this->_connected = true;

			$this->_configure();

			return $this->_connection;
		} catch (Exception $ex) {
			$this->_connected = false;
			throw $ex;
		}
	}

/**
 * Loads the datasource configuration
 *
 * @throws CakeException 
 */
	protected function _loadConfiguration() {
		if (!class_exists('AMQP_CONFIG')) {
			$path = APP . 'Config' . DS . 'amqp.php';

			if (!file_exists($path)) {
				throw new CakeException(__d('cake_amqp', 'Configuration file not found: %s', $path));
			}

			require_once($path);
		}

		$this->_config = AMQP_CONFIG::${$this->_datasource};
	}

/**
 * Declares configured exchanges, queues and bindings 
 *
 * @return void
 */
	protected function _configure() {
		foreach ((array)Configure::read('CakeAmqp.exchanges') as $name => $options) {
			if (is_string($options)) {
				$name = $options;
				$options = array();
			}
			$this->declareExchange($name, $options);
		}

		foreach ((array)Configure::read('CakeAmqp.queues') as $name => $options) {
			if (is_string($options)) {
				$name = $options;
				$options = array();
			}
			$this->declareQueue($name, $options);
		}

		foreach ((array)Configure::read('CakeAmqp.bindings') as $routingKey => $options) {
			$this->bind($routingKey, $options['exchange'], $options['queue'], $options);
		}
	}

/**
 * Declares an exchange on the broker
 *
 * available options:
 *
 * - type: string
 * - passive: boolean
 * - durable: boolean
 * - auto_delete: boolean
 * - internal: boolean
 *
 * @param string $name
 * @param array $options
 *
 * @return CakeAmqpBase
 */
	protected function declareExchange($name, $options = array()) {
		if ($this->_connected === false) {
			throw new CakeException(__d('cake_amqp', 'Not connected to broker'));
		}

		if (isset($this->_exchanges[$name])) {
			throw new CakeException(__d('cake_amqp', 'Exchange already exists: %s', $name));
		}

		$defaults = array(
			'type' => 'direct',
			'passive' => false,
			'durable' => false,
			'auto_delete' => true,
			'internal' => false
		);

		$options = array_merge($defaults, $options);

		if (!array_key_exists($options['type'], $this->_exchangeTypes)) {
			throw new CakeException(__d('cake_amqp', 'Exchange type not supported: %s', $options['type']));
		}

		$exchange = new AMQPExchange($this->_channel);
		$exchange->setName($name);
		$exchange->setType($this->_exchangeTypes[$options['type']]);
		$exchange->setFlags($this->__getExchangeFlags($options));
		$exchange->declare();

		$this->_exchanges[$name] = $exchange;

		return $this;
	}

/**
 * Returns the flags used for declaring an exchange
 *
 * @param array $options
 * @return int 
 */
	private function __getExchangeFlags($options) {
		$flags = 0;

		foreach($options as $option => $enabled) {
			if (array_key_exists($option, $this->_exchangeFlags) && $enabled) {
				$flags = $flags | $this->_exchangeFlags[$option];
			}
		}

		return $flags;
	}

/**
 * Declares a queue on the broker
 *
 * available options:
 *
 * - passive: boolean
 * - durable: boolean
 * - exclusive: boolean
 * - auto_delete: boolean
 * - nowait: boolean
 *
 * @param string $name
 * @param array $options 
 *
 * @return CakeAmqpBase
 */
	protected function declareQueue($name, $options = array()) {
		if ($this->_connected === false) {
			throw new CakeException(__d('cake_amqp', 'Not connected to broker'));
		}

		if (isset($this->_queues[$name])) {
			throw new CakeException(__d('cake_amqp', 'Queue already exists: %s', $name));
		}

		$defaults = array(
			'passive' => false,
			'durable' => false,
			'exclusive' => false,
			'autodelete' => true,
		);

		$options = array_merge($defaults, $options);

		$queue = new AMQPQueue($this->_channel);
		$queue->setName($name);
		$queue->setFlags($this->__getQueueFlags($options));
		$queue->declare();

		$this->_queues[$name] = $queue;

		return $this;
	}

/**
 * Returns the flags used for declaring a queue
 *
 * @param array $options
 * @return int 
 */
	private function __getQueueFlags($options) {
		$flags = 0;

		foreach($options as $option => $enabled) {
			if (array_key_exists($option, $this->_queueFlags) && $enabled) {
				$flags = $flags | $this->_queueFlags[$option];
			}
		}

		return $flags;
	}

/**
 * Binds a queue to an exchange with a routing key
 *
 * available options:
 *
 * - nowait: boolean
 *
 * @param string $routingKey
 * @param string $exchange
 * @param string|array $queues
 * @param array $options
 * @throws CakeException
 *
 * @return CakeAmqpBase
 */
	protected function bind($routingKey, $exchange, $queues, $options) {
		if ($this->_connected === false) {
			throw new CakeException(__d('cake_amqp', 'Not connected to broker'));
		}

		$defaults = array('nowait' => false);
		$options = array_merge($defaults, $options);

		if (is_string($queues)) {
			$queues = array($queues);
		}

		if (!isset($this->_exchanges[$exchange])) {
			throw new CakeException(__d('cake_amqp', 'Exchange does not exist: %s', $exchange));
		}

		foreach ($queues as $queue) {
			if (!isset($this->_queues[$queue])) {
				throw new CakeException(__d('cake_amqp', 'Queue does not exist: %s', $queue));
			}

			$this->_queues[$queue]->bind($exchange, $routingKey);
		}

		return $this;
	}

/**
 * Returns true if connection is active
 *
 * @return boolean
 */
	public function connected() {
		return $this->_connected;
	}
}
