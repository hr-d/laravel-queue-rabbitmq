<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue;

use DateTime;
use ErrorException;
use Exception;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;

class RabbitMQQueue extends Queue implements QueueContract
{

	protected $connection;
	protected $channel;

	protected $declareExchange;
	protected $declareBindQueue;

	protected $defaultQueue;
	protected $configQueue;
	protected $configExchange;
	protected $sleepOnError;

	/**
	 * @param AMQPConnection $amqpConnection
	 * @param array          $config
	 */
	public function __construct(AMQPConnection $amqpConnection, $config)
	{
		$this->connection = $amqpConnection;
		$this->defaultQueue = $config['queue'];
		$this->configQueue = $config['queue_params'];
		$this->configExchange = $config['exchange_params'];
		$this->declareExchange = $config['exchange_declare'];
		$this->declareBindQueue = $config['queue_declare_bind'];
		$this->sleepOnError = isset($config['sleep_on_error']) ? $config['sleep_on_error'] : 5;

		$this->channel = $this->getChannel();
	}

	/**
	 * Push a new job onto the queue.
	 *
	 * @param  string $job
	 * @param  mixed  $data
	 * @param  string $queue
	 *
	 * @return bool
	 */
	public function push($job, $data = '', $queue = null)
	{
		return $this->pushRaw($this->createPayload($job, $data), $queue, []);
	}

	/**
	 * Push a raw payload onto the queue.
	 *
	 * @param  string $payload
	 * @param  string $queue
	 * @param  array  $options
	 *
	 * @return mixed
	 */
	public function pushRaw($payload, $queue = null, array $options = [])
	{
		$queue = $this->getQueueName($queue);
		try {
			$this->declareQueue($queue);
			if (isset($options['delay'])) {
				$queue = $this->declareDelayedQueue($queue, $options['delay']);
			}

			// push job to a queue
			$message = new AMQPMessage($payload, [
				'Content-Type'  => 'application/json',
				'delivery_mode' => 2,
			]);
            $message->set('priority', $this->configQueue['priority']);

			// push task to a queue
			$this->channel->basic_publish($message, $queue, $queue);
		} catch (ErrorException $e) {
			$this->reportConnectionError('pushRaw', $e);
		}

		return true;
	}

	/**
	 * Push a new job onto the queue after a delay.
	 *
	 * @param  \DateTime|int $delay
	 * @param  string        $job
	 * @param  mixed         $data
	 * @param  string        $queue
	 *
	 * @return mixed
	 */
	public function later($delay, $job, $data = '', $queue = null)
	{
		return $this->pushRaw($this->createPayload($job, $data), $queue, ['delay' => $delay]);
	}

	/**
	 * Pop the next job off of the queue.
	 *
	 * @param string|null $queue
	 *
	 * @return \Illuminate\Queue\Jobs\Job|null
	 */
	public function pop($queue = null)
	{
		$queue = $this->getQueueName($queue);

		try {
			// declare queue if not exists
			$this->declareQueue($queue);

			// get envelope
			$message = $this->channel->basic_get($queue);

			if ($message instanceof AMQPMessage) {
				return new RabbitMQJob($this->container, $this, $this->channel, $queue, $message);
			}
		} catch (ErrorException $e) {
			$this->reportConnectionError('pop', $e);
		}

		return null;
	}

	/**
	 * @param string $queue
	 *
	 * @return string
	 */
	private function getQueueName($queue)
	{
		return $queue ?: $this->defaultQueue;
	}

	/**
	 * @return AMQPChannel
	 */
	private function getChannel()
	{
		return $this->connection->channel();
	}

	/**
	 * @param string $name
	 */
	private function declareQueue($name)
	{
		$name = $this->getQueueName($name);

		if ($this->declareExchange) {
			// declare exchange
			$this->channel->exchange_declare(
				$name,
				$this->configExchange['type'],
				$this->configExchange['passive'],
				$this->configExchange['durable'],
				$this->configExchange['auto_delete']
			);
		}

		if ($this->declareBindQueue) {
			// declare queue
			$this->channel->queue_declare(
				$name,
				$this->configQueue['passive'],
				$this->configQueue['durable'],
				$this->configQueue['exclusive'],
				$this->configQueue['auto_delete'],
                false,
                new AMQPTable([
                    'x-max-priority' => $this->configQueue['max_priority']
                ])
			);

			// bind queue to the exchange
			$this->channel->queue_bind($name, $name, $name);
		}
	}

	/**
	 * @param string       $destination
	 * @param DateTime|int $delay
	 *
	 * @return string
	 */
	private function declareDelayedQueue($destination, $delay)
	{
		$delay = $this->getSeconds($delay);
		$destination = $this->getQueueName($destination);
		$name = $this->getQueueName($destination) . '_deferred_' . $delay;

		// declare exchange
		$this->channel->exchange_declare(
			$name,
			$this->configExchange['type'],
			$this->configExchange['passive'],
			$this->configExchange['durable'],
			$this->configExchange['auto_delete']
		);

		// declare queue
		$this->channel->queue_declare(
			$name,
			$this->configQueue['passive'],
			$this->configQueue['durable'],
			$this->configQueue['exclusive'],
			$this->configQueue['auto_delete'],
			false,
			new AMQPTable([
				'x-dead-letter-exchange'    => $destination,
				'x-dead-letter-routing-key' => $destination,
				'x-message-ttl'             => $delay * 1000,
                'x-max-priority' => $this->configQueue['max_priority']
			])
		);

		// bind queue to the exchange
		$this->channel->queue_bind($name, $name, $name);

		return $name;
	}

	/**
	 * @param string    $action
	 * @param Exception $e
	 */
	private function reportConnectionError($action, Exception $e)
	{
		Log::error('AMQP error while attempting ' . $action . ': ' . $e->getMessage());

		// Sleep so that we don't flood the log file
		sleep($this->sleepOnError);
	}
}
