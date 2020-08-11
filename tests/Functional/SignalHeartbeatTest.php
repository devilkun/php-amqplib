<?php

namespace PhpAmqpLib\Tests\Functional;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

/**
 * @group connection
 */
class SignalHeartbeatTest extends TestCase
{
    protected $exchangeName = 'test_exchange';

    protected $queueName = null;

    protected $connection;

    protected $channel;

    protected $heartbeatTimeout = 4;

    protected function setUp()
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl_async_signals is required');
        }

        $this->connection = AMQPStreamConnection::create_connection(
            [
                ['host' => HOST, 'port' => PORT, 'user' => USER, 'password' => PASS, 'vhost' => VHOST]
            ],
            ['heartbeat' => $this->heartbeatTimeout]
        );
        $this->connection->set_heartbeat_sender(new PCNTLHeartbeatSender($this->connection));
        $this->channel = $this->connection->channel();
        $this->channel->exchange_declare($this->exchangeName, 'direct', false, false, false);
        list($this->queueName, ,) = $this->channel->queue_declare();
        $this->channel->queue_bind($this->queueName, $this->exchangeName, $this->queueName);
    }

    public function tearDown()
    {
        if ($this->channel) {
            $this->channel->exchange_delete($this->exchangeName);
            $this->channel->close();
            $this->channel = null;
        }
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    /**
     * @test
     */
    public function process_message_longer_than_heartbeat_timeout()
    {
        $msg = new AMQPMessage($this->heartbeatTimeout, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_NON_PERSISTENT]);

        $this->channel->basic_publish($msg, $this->exchangeName, $this->queueName);

        $this->channel->basic_consume(
            $this->queueName,
            '',
            false,
            false,
            false,
            false,
            [$this, 'processMessage']
        );

        while (count($this->channel->callbacks)) {
            $this->channel->wait();
        }
    }

    public function processMessage($msg)
    {
        $timeLeft = (int) $msg->body * 3;
        while ($timeLeft > 0) {
            $timeLeft = sleep($timeLeft);
        }

        $delivery_info = $msg->delivery_info;
        $delivery_info['channel']->basic_ack($delivery_info['delivery_tag']);
        $delivery_info['channel']->basic_cancel($delivery_info['consumer_tag']);

        $this->assertEquals($this->heartbeatTimeout, (int) $msg->body);
    }
}
