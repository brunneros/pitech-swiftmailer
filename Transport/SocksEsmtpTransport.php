<?php
namespace Pitech\SwiftBundle\Transport;

use Swift_DependencyContainer;
use Swift_Transport_EsmtpHandler;
use Swift_Transport_EsmtpTransport;
use Swift_Transport_IoBuffer;
use Swift_TransportException;

/**
 * Tweak of SwiftMailer SMTP transport class to work with SOCKS5 proxy
 */
class Pitech_Transport_SocksEsmtpTransport extends Swift_Transport_EsmtpTransport
{
    /**
     * ESMTP extension handlers.
     *
     * @var Swift_Transport_EsmtpHandler[]
     */
    private $_handlers = array();

    /**
     * ESMTP capabilities.
     *
     * @var string[]
     */
    private $_capabilities = array();

    /**
     * Connection buffer parameters.
     *
     * @var array
     */
    private $_params = array(
        'protocol' => 'tcp',
        'host' => 'localhost',
        'port' => 25,
        'timeout' => 30,
        'blocking' => 1,
        'tls' => false,
        'type' => Swift_Transport_IoBuffer::TYPE_SOCKET,
        'stream_context_options' => array(),
    );

    private $socksHost;

    /**
     * Creates a new EsmtpTransport using the given I/O buffer.
     * @param Swift_Transport_IoBuffer $socksHost
     */
    public function __construct($socksHost)
    {
        // get dependencies for parent EsmtpTransport
        $transportDeps = Swift_DependencyContainer::getInstance()
                    ->createDependenciesFor('transport.smtp');

        // get dependencies for normal StreamBuffer
        $streamDeps = Swift_DependencyContainer::getInstance()
                    ->createDependenciesFor('transport.buffer');

        // get our stream buffer
        $buffer = new Pitech_Transport_StreamBuffer($streamDeps[0], $socksHost);

        parent::__construct($buffer, $transportDeps[1], $transportDeps[2]);
        $this->socksHost = $socksHost;
    }


    /** Overridden to skip STARTTLS **/
    protected function _doHeloCommand()
    {
        try {
            $response = $this->executeCommand(
                sprintf("EHLO %s\r\n", $this->_domain), array(250)
            );
        } catch (Swift_TransportException $e) {
            return parent::_doHeloCommand();
        }

        if ($this->_params['tls']) {
            try {
                try {
                    $response = $this->executeCommand(
                        sprintf("EHLO %s\r\n", $this->_domain), array(250)
                    );
                } catch (Swift_TransportException $e) {
                    return parent::_doHeloCommand();
                }
            } catch (Swift_TransportException $e) {
                $this->_throwException($e);
            }
        }

        $this->_capabilities = $this->_getCapabilities($response);
        $this->_setHandlerParams();
        foreach ($this->_getActiveHandlers() as $handler) {
            $handler->afterEhlo($this);
        }
    }

    /** Determine ESMTP capabilities by function group */
    private function _getCapabilities($ehloResponse)
    {
        $capabilities = array();
        $ehloResponse = trim($ehloResponse);
        $lines = explode("\r\n", $ehloResponse);
        array_shift($lines);
        foreach ($lines as $line) {
            if (preg_match('/^[0-9]{3}[ -]([A-Z0-9-]+)((?:[ =].*)?)$/Di', $line, $matches)) {
                $keyword = strtoupper($matches[1]);
                $paramStr = strtoupper(ltrim($matches[2], ' ='));
                $params = !empty($paramStr) ? explode(' ', $paramStr) : array();
                $capabilities[$keyword] = $params;
            }
        }

        return $capabilities;
    }

    /** Set parameters which are used by each extension handler */
    private function _setHandlerParams()
    {
        foreach ($this->_handlers as $keyword => $handler) {
            if (array_key_exists($keyword, $this->_capabilities)) {
                $handler->setKeywordParams($this->_capabilities[$keyword]);
            }
        }
    }
    /** Get ESMTP handlers which are currently ok to use */
    private function _getActiveHandlers()
    {
        $handlers = array();
        foreach ($this->_handlers as $keyword => $handler) {
            if (array_key_exists($keyword, $this->_capabilities)) {
                $handlers[] = $handler;
            }
        }

        return $handlers;
    }

}
