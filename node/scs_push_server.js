/**
 * Author: Brandon Francis
 * 
 * A flexible and portable push relay server.
 * Uses a ticketing system to be portable.
 */

/*
 * Installing socket.io
 * npm install -g node-gyp
 * npm install -g socket.io
 */

var url = require('url');
var qs = require('querystring');
var socketio = require('socket.io');
var net = require('net');
var http = require('http');

var SETTINGS = {
    'AUTH_KEY': 'a01bia92912bf9',
    'HTTP_PORT': 8083,
    'SOCKET_PORT': 8084,
    'TICKET_LIFETIME': 3000,
    'SOCKET_LIFETIME': 2000,
    'CHAT_LOG_LENGTH': 30
};

function PushServer() {
    var thisServer = this;
    this.clients = new ClientManager();
    this.httpServer = http.createServer(function(req, res) { thisServer.httpHandler(req, res); });
    this.io = socketio.listen(this.httpServer, {log: false});
    this.socketServer = net.Server(function(socket) {
        thisServer.socketHandler(socket, false);
    }).listen(SETTINGS['SOCKET_PORT']);
    
    this.httpServer.listen(SETTINGS['HTTP_PORT']);
    
    this.io.sockets.on('connection', function(socket) {
        thisServer.socketHandler(socket, true);
    });
    
    this.chat_log = [];
}

PushServer.prototype.addToChatLog = function(data) {
    this.chat_log.unshift(data);
    while (this.chat_log.length > SETTINGS['CHAT_LOG_LENGTH'])
        this.chat_log.splice(SETTINGS['CHAT_LOG_LENGTH'] - 1, 1);
};

PushServer.prototype.socketHandler = function(socket, isSocketIo) {
    new SocketWrapper(this, socket, isSocketIo);
};

PushServer.prototype.httpHandler = function(req, res) {
    
    // Make sure the request is trusted
    if (req.headers['auth-key'] !== SETTINGS['AUTH_KEY']) {
        res.writeHead(403);
        res.end();
        return;
    }

    // Create the headers each response will use
    var headersToUse = {
        'Access-Control-Allow-Origin': '*',
        'Content-Type': 'text/json'
    };

    // Handle the requests according to what kind they are
    var urlParts = url.parse(req.url, true);
    if (req.method === 'GET') {
        var handled = this.handleGetRequest(urlParts);
        res.writeHead(handled.status, headersToUse);
        res.end(handled.data);
        return;
    } else if (req.method === 'POST') {
        var thisServer = this;
        processPost(req, res, function() {
            var handled = thisServer.handlePostRequest(res.post, urlParts);
            res.writeHead(handled.status, headersToUse);
            res.end(handled.data);
            return;
        });
        return;
    }

    // We didn't handle the request
    res.writeHead(404);
    res.end();
};

PushServer.prototype.handleGetRequest = function(getParts) {
    return {status: 404, data: ''};
};

PushServer.prototype.handlePostRequest = function(postParts, getParts) {
    
    if (postParts.mode === 'get_ticket' && postParts.userid !== undefined) {
        
        // Create a temp client
        var clientInfo = new ClientInfo(String(postParts.userid), String(postParts.full_name), String(postParts.email));
        var ticket = this.clients.createTicket(clientInfo);
        
        // We handled the request
        return {status: 200, data: createSuccessPayload(ticket)};
        
    } else if (postParts.mode === 'emit' &&
            postParts.endpoint !== undefined &&
            postParts.userid !==  undefined) {
        
        if (postParts.data !== undefined) {
            try {
                postParts.data = JSON.parse(postParts.data);
            } catch (Ex) { }
        }
        var success = this.clients.emit(postParts.userid, postParts.endpoint, postParts.data);
        
        // If at least one suitable client was found, return a success
        if (success)
            return {status: 200, data: createSuccessPayload({success: true})};
        
        // Couldn't find a client with the given userid
        return {status: 200, data: createErrorPayload('Client does not exist')};
     
    } else if (postParts.mode === 'emit_activity' &&
            postParts.message !== undefined) {

        var obj = {message: postParts.message, time: Math.round(+new Date() / 1000), type: 'activity'};
        if (postParts.user !== undefined) {
            try {
                var userobj = JSON.parse(postParts.user);
                obj.user = {userid: userobj.userid, name: userobj.full_name, email: userobj.email};
            } catch (Ex) { }
        }
        if (postParts.url !== undefined)
            obj.url = postParts.url;

        this.addToChatLog(obj);
        this.clients.emitToAll('activity', obj);

    } else if (postParts.mode === 'status') {

        // Just return some information about what's going on now
        var info = {clients: Object.keys(this.clients.clients).length};
        return {status: 200, data: createSuccessPayload(info)};
        
    }
    
    // The request wasn't handled
    return {status: 404, data: ''};
    
};

function ClientManager() {
    this.tickets = {};
    this.clients = {};
}

ClientManager.prototype.emit = function(userid, endpoint, data) {
    if (!(userid in this.clients))
        return false;
    return this.clients[userid].emit(endpoint, data);
};

ClientManager.prototype.emitToAll = function(endpoint, data) {
    for (var key in this.clients)
        this.clients[key].emit(endpoint, data);
};

ClientManager.prototype.associateTicket = function(socket, givenTicket) {

    // See if the unauthorized client exists
    if (!(givenTicket.id in this.tickets))
        return;

    // Make sure the key matches
    if (givenTicket.key !== this.tickets[givenTicket.id].key)
        return;
   
    // The key matches so we can now move it
    var ticket = this.tickets[givenTicket.id];
    this.removeTicket(ticket);
    
    // Create the new client if necessary
    if (!(ticket.clientInfo.userid in this.clients))
        this.clients[ticket.clientInfo.userid] = new Client(this, ticket.clientInfo);
    
    // Add the socket to the client
    var client = this.clients[ticket.clientInfo.userid];
    socket.setClient(client);
    client.addSocket(socket);

};

ClientManager.prototype.createTicket = function(clientInfo) {
    
    // Create the new ticket
    var ticket = new Ticket(clientInfo);
    
    // Add it to the list of tickets
    this.tickets[ticket.id] = ticket;
    
    // Handle the ticket timeout so tickets become invalid
    var thisManager = this;
    setTimeout(function() {
        thisManager.removeTicket(ticket);
    }, SETTINGS['TICKET_LIFETIME']);
    
    // Return the created ticket
    return ticket;
};

ClientManager.prototype.removeClient = function(client) {
    
    // Make sure the client exists and then delete it
    if (client.clientInfo.userid in this.clients)
        delete this.clients[client.clientInfo.userid];
    
};

ClientManager.prototype.removeTicket = function(ticket) {
    
    // Make sure the ticket exists and then delete it
    if (ticket.id in this.tickets)
        delete this.tickets[ticket.id];
    
};

function Client(clientManager, clientInfo) {
    this.clientManager = clientManager;
    this.sockets = [];
    this.clientInfo = clientInfo;
}

Client.prototype.emit = function(endpoint, data) {
    
    // Go through all of the sockets and emit to it
    for (var i = 0; i < this.sockets.length; i++)
        this.sockets[i].send(endpoint, data);
    return true;
    
};

Client.prototype.addSocket = function(socket) {
    this.sockets.push(socket);
};

Client.prototype.handleDisconnect = function(socket) {
    
    // Get the index of the socket and remove it
    var index = this.sockets.indexOf(socket);
    if (index > -1)
        this.sockets.splice(index, 1);
    
    // If there are no sockets left let's remove this client
    if (this.sockets.length === 0)
        this.clientManager.removeClient(this);
    
};

function SocketWrapper(server, socket, isSocketIo) {
    this.server = server;
    this.socket = socket;
    this.isSocketIo = isSocketIo;
    this.client = null;
    this.initialize();
}

SocketWrapper.prototype.setClient = function(client) {
    this.client = client;
};

SocketWrapper.prototype.initialize = function() {
    var socket = this.socket;
    var thisWrapper = this;
    if (this.isSocketIo) {
        socket.on('use_ticket', function(ticket) { thisWrapper.handleTicketUsed(ticket); });
        socket.on('disconnect', function() { thisWrapper.handleDisconnect(); });
        socket.on('chat', function(message) { thisWrapper.handleChat(message); });
        socket.on('chat_history', function() { thisWrapper.handleChatHistory(); });
    } else {
        socket.on('end', function() { thisWrapper.handleDisconnect(); });
        socket.on('error', function() { thisWrapper.handleDisconnect(); });
        socket.on('timeout', function() { thisWrapper.handleDisconnect(); });
        socket.on('data', function(data) { thisWrapper.handleData(data); });
    }
    setTimeout(function() { thisWrapper.authCheck(); }, SETTINGS['SOCKET_LIFETIME']);
};

SocketWrapper.prototype.handleChat = function(message) {
    var data = {message: message, user: this.client.clientInfo, time: Math.round(+new Date()/1000), type: 'chat'};
    this.server.addToChatLog(data);
    this.server.clients.emitToAll('chat', data);
};

SocketWrapper.prototype.handleChatHistory = function() {
    this.send('chat_history', this.server.chat_log);
};

SocketWrapper.prototype.handleTicketUsed = function(ticket) {
    this.server.clients.associateTicket(this, ticket);
    this.send('ticket_used');
};

SocketWrapper.prototype.handleDisconnect = function() {
    if (!this.isSocketIo)
        this.socket.destroy();
    if (this.client !== null)
        this.client.handleDisconnect(this);
};

SocketWrapper.prototype.disconnect = function() {
    if (this.isSocketIo) {
        this.socket.disconnect();
    } else {
        this.send('disconnect');
        this.socket.destroy();
    }
};

SocketWrapper.prototype.authCheck = function() {
    if (this.client === null)
        this.disconnect();
};

SocketWrapper.prototype.handleData = function(data) {
    data = String(data).trim();
    if (data.length === 0)
        return;

    var payload;
    try {
        payload = JSON.parse(data);
    } catch (ex) {
        return;
    }

    var endpoint = payload.endpoint;
    data = payload.data;
    if (endpoint === 'use_ticket') {
        this.handleTicketUsed(data);
        return;
    } else if (endpoint === 'disconnect') {
        this.handleDisconnect();
        return;
    } else if (endpoint === 'chat') {
        this.handleChat(data);
        return;
    } else if (endpoint === 'chat_history') {
        this.handleChatHistory();
        return;
    }
};

SocketWrapper.prototype.send = function(endpoint, data) {
    if (data === undefined) {
        if (this.isSocketIo) {
            this.socket.emit(endpoint);
            return;
        } else {
            var payload = JSON.stringify({endpoint: endpoint, data: null});
            this.socket.write(payload.length + ':' + payload);
            return;
        }
    } else {
        if (this.isSocketIo) {
            this.socket.emit(endpoint, data);
            return;
        } else {
            var payload = JSON.stringify({endpoint: endpoint, data: data});
            this.socket.write(payload.length + ':' + payload);
            return;
        }
    }
};

function Ticket(clientInfo) {
    this.id = getRandomId();
    this.key = getRandomId();
    this.clientInfo = clientInfo;
}

function ClientInfo(userid, name, email) {
    this.userid = userid;
    this.name = name;
    this.email = email;
}

function getRandomId() {
    var rnd1 = Math.random().toString(36).substring(2, 15);
    var rnd2 = Math.random().toString(36).substring(2, 15);
    return rnd1 + rnd2;
}

function createSuccessPayload(data) {
    return JSON.stringify({success: true, data: data});
}

function createErrorPayload(error_message) {
    return JSON.stringify({success: false, error: error_message});
}

function processPost(request, response, callback) {
    var queryData = "";
    if (typeof callback !== 'function')
        return null;
    request.on('data', function(data) {
        queryData += data;
        if (queryData.length > 1e6) {
            queryData = "";
            response.writeHead(413, {'Content-Type': 'text/plain'}).end();
            request.connection.destroy();
        }
    });
    request.on('end', function() {
        response.post = qs.parse(queryData);
        callback();
    });
}

var server = new PushServer();
console.log('Running...');