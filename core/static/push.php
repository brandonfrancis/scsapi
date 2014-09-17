<?php

/**
 * Manages access to the push servers.
 */
class Push {
    
    /**
     * Gets a login ticket for an available push server.
     * @return type
     */
    public static function getTicket() {
        if (Auth::getUser()->isGuest()) {
            return null;
        }
        $pushServer = self::getPushServer(Auth::getUser());
        $ticket = $pushServer->getTicket(Auth::getUser());
        return array(
            'host' => $pushServer->host,
            'socket_port' => $pushServer->socket_port,
            'http_host' => $pushServer->getHostUrl(),
            'ticket' => $ticket
        );
    }

    /**
     * Gets an available push server.
     * @return PushServer
     */
    public static function getPushServer(User $user) {
        return new PushServer('72.8.168.110', 8081, 8082, 'a01bia92912bf9');
    }
    
}

/**
 * Represents a single push server instance.
 * Handles communication with the instance.
 */
class PushServer {
    
    /**
     * The host name of this PushServer.
     * @var String
     */
    public $host;
    
    /**
     * The port for HTTP communication to this PushServer.
     * @var number 
     */
    public $http_port;
    
    /**
     * The port for socket commucation to this PushServer.
     * @var Number 
     */
    public $socket_port;
    
    /**
     * The authentication key to communcate with this PushServer.
     * @var String
     */
    private $auth_key;
    
    /**
     * Creates a new PushServer object.
     * @param String $host The hostname of the PushServer.
     * @param Number $http_port The port for HTTP communcation.
     * @param Number $socket_port The port for socket communication.
     * @param String $auth_key The authentication key to communcate.
     */
    function __construct($host, $http_port, $socket_port, $auth_key) {
        $this->host = $host;
        $this->http_port = intval($http_port);
        $this->socket_port = intval($socket_port);
        $this->auth_key = $auth_key;
    }
    
    /**
     * Returns the response of the PushServer as an object.
     * @param array $post_data The data, as an array, to post.
     * @return Object
     * @throws Exception
     */
    private function getJSONResponse($post_data) {
        $response = json_decode(file_get_contents($this->getHostUrl(),
                false, $this->createStreamContext($post_data)));
        if (!$response->success) {
            throw new Exception($response->error);
        }
        return $response->data;
    }
   
    /**
     * Creates a stream context for posting data.
     * @param array $data The data to create the context for.
     * @return StreamContext
     */
    private function createStreamContext($data) {
        $postdata = http_build_query($data);
        $opts = array('http' =>
            array(
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded' .
                    PHP_EOL . 'auth-key: ' . $this->auth_key,
                'content' => $postdata
            )
        );
        $context = stream_context_create($opts);
        return $context;
    }
    
    /**
     * Returns the full url and port for the server.
     * @return String
     */
    public function getHostUrl() {
        return 'http://' . $this->host . ':' . $this->http_port . '';
    }
    
    /**
     * Gets a authentication ticket for a specific userid.
     * @param User $user The user to get the ticket for.
     * @return Object
     */
    public function getTicket(User $user) {
        if ($user->isGuest()) {
            throw new Exception('Unable to get tickets for guests.');
        }
        return $this->getJSONResponse(array('mode' => 'get_ticket',
                    'userid' => $user->getUserId(),
                    'username' => $user->getUsername(),
                    'usertitle' => $user->getUsertitle()
        ));
    }

    /**
     * Emits to a specific userid, with an endpoint and data.
     * @param int $userid The userid to emit to.
     * @param String $endpoint The endpoint to reach.
     * @param Object $data The data.
     * @return Object
     */
    public function emit($userid, $endpoint, $data = null) {
        $array = array('mode' => 'emit', 'userid' => $userid, 'endpoint' => $endpoint);
        if ($data !== null) {
            $array['data'] = $data;
        }
        return $this->getJSONResponse($array);
    }
    
    /**
     * Emits an activity to the clients.
     * @param String $message The message for the activity.
     * @param User $user The user who did the activity. Optional.
     * @param String $url The url for the activity. Optional.
     * @return Object
     */
    public function emitActivity($message, User $user = null, $url = null) {
        
        // Set up the context to pass along
        $context = array(
            'mode' => 'emit_activity',
            'message' => $message
        );
        if ($user != null) {
            $context['user'] = json_encode($user->getContext());
        }
        if ($url != null && $url != '') {
            $context['url'] = $url;
        }
        
        // Contact the server to push accordingly
        return $this->getJSONResponse($context);
        
    }

    /**
     * Gets the status of the PushServer.
     * @return Object
     */
    public function getStatus() {
        return $this->getJSONResponse(array('mode' => 'status'));
    }
    
}
