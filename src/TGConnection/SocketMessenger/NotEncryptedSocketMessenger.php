<?php

namespace TelegramOSINT\TGConnection\SocketMessenger;

use LogicException;
use TelegramOSINT\Exception\TGException;
use TelegramOSINT\LibConfig;
use TelegramOSINT\Logger\ClientDebugLogger;
use TelegramOSINT\Logger\Logger;
use TelegramOSINT\MTSerialization\AnonymousMessage;
use TelegramOSINT\MTSerialization\MTDeserializer;
use TelegramOSINT\MTSerialization\OwnImplementation\OwnDeserializer;
use TelegramOSINT\TGConnection\DataCentre;
use TelegramOSINT\TGConnection\Socket\Socket;
use TelegramOSINT\TGConnection\SocketMessenger\MessengerTools\MessageIdGenerator;
use TelegramOSINT\TGConnection\SocketMessenger\MessengerTools\OuterHeaderWrapper;
use TelegramOSINT\TLMessage\TLMessage\TLClientMessage;

class NotEncryptedSocketMessenger extends TgSocketMessenger
{
    /**
     * @var OuterHeaderWrapper
     */
    private $outerHeaderWrapper;
    /**
     * @var MessageIdGenerator
     */
    private $msgIdGenerator;
    /**
     * @var MTDeserializer
     */
    private $deserializer;
    /** @var ClientDebugLogger|null */
    private $logger;

    /**
     * @param Socket                 $socket
     * @param ClientDebugLogger|null $logger
     */
    public function __construct(Socket $socket, ?ClientDebugLogger $logger = null)
    {
        parent::__construct($socket);
        $this->outerHeaderWrapper = new OuterHeaderWrapper();
        $this->msgIdGenerator = new MessageIdGenerator();
        $this->deserializer = new OwnDeserializer();
        $this->logger = $logger;
    }

    private function log(string $code, string $message): void
    {
        if ($this->logger) {
            $this->logger->debugLibLog($code, $message);
        } else {
            Logger::log($code, $message);
        }
    }

    /**
     * @throws TGException
     *
     * @return AnonymousMessage
     */
    public function readMessage(): ?AnonymousMessage
    {
        $packet = $this->readPacket();
        if (!$packet) {
            return null;
        }

        $this->log('Read_Message_Binary', bin2hex($packet));

        $decoded = $this->decodePayload($this->outerHeaderWrapper->unwrap($packet));
        $deserialized = $this->deserializer->deserialize($decoded);

        $this->log('Read_Message_Binary', bin2hex($decoded));
        $this->log('Read_Message_TL', $deserialized->getDebugPrintable());

        return $deserialized;
    }

    /**
     * @param string $payload
     *
     * @throws TGException
     *
     * @return false|string
     */
    private function decodePayload($payload)
    {
        $auth_key_id = unpack('V', substr($payload, 0, 8))[1];

        // must be 0 because it is unencrypted messaging
        if($auth_key_id !== 0) {
            throw new TGException(TGException::ERR_TL_CONTAINER_BAD_AUTHKEY_ID_MUST_BE_0);
        }
        $message_data_length = unpack('V', substr($payload, 16, 4))[1];

        return substr($payload, 20, $message_data_length);
    }

    /**
     * @param TLClientMessage $payload
     *
     * @throws TGException
     */
    public function writeMessage(TLClientMessage $payload): void
    {
        $payloadStr = $this->outerHeaderWrapper->wrap(
            $this->wrapPayloadWithMessageId($payload->toBinary())
        );

        $this->socket->writeBinary($payloadStr);

        $this->log('Write_Message_Binary', bin2hex($payload->toBinary()));
        $this->log('Write_Message_TL', $this->deserializer->deserialize($payload->toBinary())->getDebugPrintable());
    }

    /**
     * @param string $payload
     *
     * @return string
     */
    private function wrapPayloadWithMessageId(string $payload): string
    {
        $msg_id = $this->msgIdGenerator->generateNext();
        $length = strlen($payload);
        $payload = pack('x8PI', $msg_id, $length).$payload;

        return $payload;
    }

    /**
     * @return DataCentre
     */
    public function getDCInfo(): DataCentre
    {
        return $this->socket->getDCInfo();
    }

    public function terminate(): void
    {
        $this->socket->terminate();
    }

    /**
     * @param TLClientMessage $message
     * @param callable        $cb      function(AnonymousMessage $message)
     *
     * @throws TGException
     */
    public function getResponseAsync(TLClientMessage $message, callable $cb): void
    {
        // Dummy impl
        $this->writeMessage($message);
        $startTimeMs = microtime(true) * 1000;

        while(true){
            $response = $this->readMessage();
            if($response) {
                $cb($response);

                return;
            }

            $currentTimeMs = microtime(true) * 1000;
            if(($currentTimeMs - $startTimeMs) > LibConfig::CONN_SOCKET_TIMEOUT_WAIT_RESPONSE_MS) {
                break;
            }

            usleep(LibConfig::CONN_SOCKET_RESPONSE_DELAY_MICROS);
        }

        throw new TGException(TGException::ERR_MSG_RESPONSE_TIMEOUT);
    }

    public function getResponseConsecutive(array $messages, callable $onLastResponse): void
    {
        throw new LogicException('not implemented '.__METHOD__);
    }
}
