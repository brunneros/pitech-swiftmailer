<?php

namespace Pitech\SwiftBundle\Transport;

use Swift_ReplacementFilterFactory;
use Swift_Transport_StreamBuffer;
use Swift_TransportException;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;

/**
 * A generic IoBuffer implementation supporting remote sockets and local processes.
 *
 * @author Chris Corbyn
 */
class SocksStreamBuffer extends Swift_Transport_StreamBuffer
{
    /** A primary socket */
    private $_stream;

    /** The input stream */
    private $_in;

    /** The output stream */
    private $_out;

    /** Buffer initialization parameters */
    private $_params = array();

    /** The host which tunnels the connection */
    private $socksProxy;

    /**
     * Create a new StreamBuffer using $replacementFactory for transformations.
     *
     * @param Swift_ReplacementFilterFactory $replacementFactory
     * @param $socksProxy
     */
    public function __construct(Swift_ReplacementFilterFactory $replacementFactory, $socksProxy)
    {
        parent::__construct($replacementFactory);
        $this->socksProxy = $socksProxy;
    }

    /**
     * Perform any initialization needed, using the given $params.
     *
     * Parameters will vary depending upon the type of IoBuffer used.
     *
     * @param array $params
     */
    public function initialize(array $params)
    {
        $this->_establishSocketConnection();
    }


    /**
     * Establishes a connection to a remote server.
     */
    private function _establishSocketConnection()
    {
        $host = $this->_params['host'];
        if (!empty($this->_params['protocol'])) {
            $host = $this->_params['protocol'].'://'.$host;
        }
        $timeout = 15;
        if (!empty($this->_params['timeout'])) {
            $timeout = $this->_params['timeout'];
        }
        $options = array();
        if (!empty($this->_params['sourceIp'])) {
            $options['socket']['bindto'] = $this->_params['sourceIp'].':0';
        }
        if (isset($this->_params['stream_context_options'])) {
            $options = array_merge($options, $this->_params['stream_context_options']);
        }

        if (!isset($this->socksProxy['host']) || !isset($this->socksProxy['port'])) {
            throw new ParameterNotFoundException('Proxy host or port not defined ');
        }

        // disable ssl host verification
        $options['ssl']['verify_peer'] = FALSE;
        $options['ssl']['verify_peer_name'] = FALSE;

        $streamContext = stream_context_create($options);

        $this->_stream = @stream_socket_client(
            'tcp://' . $this->socksProxy['host'].':'.$this->socksProxy['port'], $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $streamContext
        );

        fwrite($this->_stream , pack( "C3", 0x05, 0x01, 0x00 ) );
        $server_status = fread($this->_stream , 2048 );

        if ($server_status !== pack( "C2", 0x05, 0x00 )) {
            throw new Swift_TransportException('SOCKS Server does not support this version and/or authentication method of SOCKS');
        }


        fwrite( $this->_stream, pack( "C5", 0x05, 0x01, 0x00, 0x03, strlen( $this->_params['host'] ) ) . $this->_params['host'] . pack( "n", $this->_params['port'] ) );


        $server_buffer = fread( $this->_stream, 10 );

        if (!( ord( $server_buffer[0] ) == 5 && ord( $server_buffer[1] ) == 0 && ord( $server_buffer[2] ) == 0 )) {
            throw new Swift_TransportException("The SOCKS server failed to connect to the specificed host and port. ( " . $host . ":" . $this->_params['port'] . " )" );
        }

        stream_socket_enable_crypto( $this->_stream, TRUE, STREAM_CRYPTO_METHOD_TLS_CLIENT );

        if (false === $this->_stream) {
            throw new Swift_TransportException(
                'Connection could not be established with host '.$this->_params['host'].
                ' ['.$errstr.' #'.$errno.']'
            );
        }

        if (!empty($this->_params['blocking'])) {
            stream_set_blocking($this->_stream, 1);
        } else {
            stream_set_blocking($this->_stream, 0);
        }
        stream_set_timeout($this->_stream, $timeout);
        $this->_in = &$this->_stream;
        $this->_out = &$this->_stream;
    }
}
