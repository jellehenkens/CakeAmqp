<?php

App::uses('CakeAmqp', 'CakeAmqp.Lib');

Configure::write('CakeAmqp.config_path', __DIR__ . DS . '..' . DS . '..' . DS . 'test_app' . DS . 'Config' . DS);

class TestCakeAmqp extends CakeAmqp {
	public static function reset() {
		self::$_instance = null;
	}
}

/**
 * CakeAmqpTest class
 *
 * @package       CakeAmqp.Test.Case
 */
class CakeAmqpTest extends CakeTestCase {

/**
 * setUp method
 *
 * @return void
 */
	public function setUp() {

	}

/**
 * tearDown method
 *
 * @return void
 */
	public function tearDown() {
		TestCakeAmqp::reset();
	}

/**
 * Tests the static getInstance method
 *
 * @return void
 */
	public function testGetInstance() {
		$result = TestCakeAmqp::getInstance();
		$this->assertTrue($result instanceof CakeAmqpProducer);
	}

/**
 * Tests the static send method
 *
 * @return void
 */
	public function testSend() {
		$mock = $this->getMock('CakeAmqpProducer', array('send'));
		$mock->expects($this->once())
			->method('send')
			->will($this->returnValue(true))
			->with('exchange', 'routing-key', array('foo' => 'bar'), array('option' => 'one'));
		TestCakeAmqp::setInstance($mock);
		TestCakeAmqp::send('exchange', 'routing-key', array('foo' => 'bar'), array('option' => 'one'));
	}
}
