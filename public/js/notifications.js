function NotificationManager(getUrl, clearUrl) {
    this.getUrl = getUrl;
    this.clearUrl = clearUrl;
    this.notifications = null;
    this.totalUnread = 0;
}

NotificationManager.prototype.load = function() {
    var thisManager = this;
    $.getJSON(this.getUrl, function(data) {
        if (!data.success) { return; }
        thisManager.totalUnread = 0;
        thisManager.notifications = data.data;
        for (var i = 0; i < thisManager.notifications.length; i++) {
            if (thisManager.notifications[i].has_read === false)
                thisManager.totalUnread++;
        }
        thisManager.changed();
    }).fail(function() {
        thisManager.notifications = [];
        thisManager.totalUnread = 0;
        thisManager.changed();
    });
};

NotificationManager.prototype.readAll = function() {
    var thisManager = this;
    $.getJSON(this.clearUrl, function(data) {
        if (!data.success) { return; }
        thisManager.totalUnread = 0;
        thisManager.changed();
    }).fail(function() {
        thisManager.changed();
    });
};

NotificationManager.prototype.setupPush = function(ticketerUrl) {
    var thisManager = this;
    $.getJSON(ticketerUrl, function(data) {
        if (!data.success) { return; }
        var socket = io.connect(data.data.http_host);
        socket.emit('use_ticket', data.data.ticket);
        socket.on('ticket_used', function() {
            socket.on('notification', function() { thisManager.load(); });
        });
    });
};

NotificationManager.prototype.changed = function() { };
